<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Scheduling;

use Yii2S\Swoole\Redis\CoroutineRedisConnection;

/**
 * Dispatches [[Crontab]] tasks.
 *
 * Call [[tick()]] once per second from each worker. Only one worker in the
 * process runs the due tasks (worker id 0, or the only process when there is
 * no worker). Pass a Redis connection to also elect a single winner across
 * server instances: the due slot is taken with `SET NX`.
 *
 * A task that is still running is not started again by this dispatcher.
 * [[run()]] provides the blocking coroutine loop for standalone use.
 */
class CrontabDispatcher
{
    /**
     * @var Crontab[]
     */
    private array $crontabs = [];

    /**
     * Task names whose callback has not returned. Checked so a slow task is
     * not started again on the next tick of this same dispatcher.
     *
     * @var array<string, true>
     */
    private array $running = [];

    private ?CoroutineRedisConnection $redis;

    private string $lockPrefix;

    /**
     * Seconds the Redis lock is held if the worker dies mid-task. While the
     * callback is running the lock stays, so another instance cannot start
     * the same task on the next second. Released when the callback returns.
     */
    public int $lockSeconds = 60;

    /**
     * @var array<string, string> task name => lock token
     */
    private array $lockTokens = [];

    private const RELEASE_LOCK = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    /**
     * Worker id recorded by the HTTP server on worker start. Null outside a
     * worker, where this process is the only candidate.
     */
    private static ?int $workerId = null;

    public static function setWorkerId(?int $workerId): void
    {
        self::$workerId = $workerId;
    }

    /**
     * @var callable(Crontab, int): bool|null replaces the built-in worker-id /
     * Redis election. Used by tests and by an application that already has its own lock.
     */
    private $leader;

    /**
     * @param callable(Crontab, int): bool|null $leader
     */
    public function __construct(
        ?CoroutineRedisConnection $redis = null,
        string $lockPrefix = 'yii2s:crontab:',
        ?callable $leader = null,
    ) {
        $this->redis = $redis;
        $this->lockPrefix = $lockPrefix;
        $this->leader = $leader;
    }

    public function add(Crontab $crontab): self
    {
        $this->crontabs[] = $crontab;

        return $this;
    }

    /**
     * @return Crontab[]
     */
    public function getCrontabs(): array
    {
        return $this->crontabs;
    }

    /**
     * Runs the tasks due at the given timestamp. Call once per second.
     *
     * @param int|null $now unix timestamp (defaults to now)
     */
    public function tick(?int $now = null): void
    {
        $now ??= time();

        foreach ($this->crontabs as $crontab) {
            if (!$this->isDue($crontab, $now) || !$this->claim($crontab, $now)) {
                continue;
            }

            $name = $crontab->getName();
            $this->running[$name] = true;
            try {
                ($crontab->getCallback())();
            } finally {
                unset($this->running[$name]);
                $this->releaseRedis($name);
            }
        }
    }

    /**
     * True when this process should execute the due task. A task that has not
     * returned yet is never claimed again here.
     */
    private function claim(Crontab $crontab, int $now): bool
    {
        if (isset($this->running[$crontab->getName()])) {
            return false;
        }
        if ($this->leader !== null) {
            return (bool) ($this->leader)($crontab, $now);
        }
        if (!$this->isLocalLeader()) {
            return false;
        }
        if ($this->redis === null) {
            return true;
        }

        $token = bin2hex(random_bytes(8));
        $result = $this->redis->executeCommand('SET', [
            $this->lockPrefix . $crontab->getName(),
            $token,
            ['NX', 'EX' => max(1, $this->lockSeconds)],
        ]);
        if ($result !== true && $result !== 'OK') {
            return false;
        }
        $this->lockTokens[$crontab->getName()] = $token;

        return true;
    }

    private function releaseRedis(string $name): void
    {
        $token = $this->lockTokens[$name] ?? null;
        unset($this->lockTokens[$name]);
        if ($token === null || $this->redis === null) {
            return;
        }
        $this->redis->executeCommand('EVAL', [
            self::RELEASE_LOCK,
            [$this->lockPrefix . $name, $token],
            1,
        ]);
    }

    /**
     * Worker 0 runs the tasks. Outside a worker (a dedicated process, or the
     * test runner) there is only one candidate, so it runs them.
     */
    private function isLocalLeader(): bool
    {
        return self::$workerId === null || self::$workerId === 0;
    }

    /**
     * Blocking coroutine loop that ticks every second. For standalone use;
     * inside the server prefer calling [[tick()]] from a workerStart coroutine.
     */
    public function run(): void
    {
        // @phpstan-ignore-next-line intentional infinite loop, runs until stopped
        while (true) {
            $this->tick();
            \Swoole\Coroutine::sleep(1.0);
        }
    }

    public function isDue(Crontab $crontab, int $now): bool
    {
        $rule = $crontab->getRule();

        if (str_starts_with($rule, '@every')) {
            return $this->isDueEvery($rule, $now);
        }

        return $this->isDueCron($rule, $now);
    }

    /**
     * `@every 30s` / `@every 5m` / `@every 1h` interval scheduling.
     */
    private function isDueEvery(string $rule, int $now): bool
    {
        if (!preg_match('/^@every\s+(\d+)([smh])$/', $rule, $m)) {
            return false;
        }

        $interval = (int) $m[1] * match ($m[2]) {
            's' => 1,
            'm' => 60,
            'h' => 3600,
        };

        return $interval > 0 && $now % $interval === 0;
    }

    /**
     * Standard five-field cron (`minute hour day month weekday`).
     */
    private function isDueCron(string $rule, int $now): bool
    {
        $fields = preg_split('/\s+/', trim($rule));
        if ($fields === false || count($fields) !== 5) {
            return false;
        }

        $parts = [
            'i' => (int) date('i', $now), // minute 0-59
            'G' => (int) date('G', $now), // hour 0-23
            'j' => (int) date('j', $now), // day 1-31
            'n' => (int) date('n', $now), // month 1-12
            'w' => (int) date('w', $now), // weekday 0-6 (Sunday = 0)
        ];

        // Standard cron allows both 0 and 7 for Sunday; date('w') only ever
        // returns 0, so also accept a literal 7 on Sundays.
        $weekdayDue = $this->matches($fields[4], $parts['w'], 0, 6)
            || ($parts['w'] === 0 && $this->matches($fields[4], 7, 7, 7));

        return $this->matches($fields[0], $parts['i'], 0, 59)
            && $this->matches($fields[1], $parts['G'], 0, 23)
            && $this->matches($fields[2], $parts['j'], 1, 31)
            && $this->matches($fields[3], $parts['n'], 1, 12)
            && $weekdayDue;
    }

    /**
     * Matches a single cron field against a value, supporting `*`, `N`,
     * `N-M`, `N-M/step`, `N,M` lists and stepped ranges.
     */
    private function matches(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $part) {
            if ($part === '*') {
                return true;
            }

            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $step] = explode('/', $part, 2);
                $step = (int) $step;
                if ($step <= 0) {
                    return false;
                }
            }

            if ($part === '*') {
                $start = $min;
                $end = $max;
            } elseif (str_contains($part, '-')) {
                [$start, $end] = explode('-', $part, 2);
                $start = (int) $start;
                $end = (int) $end;
            } else {
                $start = (int) $part;
                $end = $start;
            }

            for ($i = $start; $i <= $end && $i <= $max; $i += $step) {
                if ($i === $value) {
                    return true;
                }
            }
        }

        return false;
    }
}
