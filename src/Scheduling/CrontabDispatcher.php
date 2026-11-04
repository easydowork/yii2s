<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Scheduling;

/**
 * Dispatches [[Crontab]] tasks.
 *
 * Call [[tick()]] once per second (e.g. from the server's `workerStart`
 * coroutine) and it runs every due task. [[run()]] provides the blocking
 * coroutine loop for standalone use.
 */
class CrontabDispatcher
{
    /**
     * @var Crontab[]
     */
    private array $crontabs = [];

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
            if ($this->isDue($crontab, $now)) {
                ($crontab->getCallback())();
            }
        }
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

        return $now % $interval === 0;
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

        return $this->matches($fields[0], $parts['i'], 0, 59)
            && $this->matches($fields[1], $parts['G'], 0, 23)
            && $this->matches($fields[2], $parts['j'], 1, 31)
            && $this->matches($fields[3], $parts['n'], 1, 12)
            && $this->matches($fields[4], $parts['w'], 0, 6);
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
