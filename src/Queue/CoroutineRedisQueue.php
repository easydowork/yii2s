<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Queue;

use yii\base\Component;
use yii\di\Instance;
use Yii2S\Swoole\Redis\CoroutineRedisConnection;

/**
 * Coroutine-safe Redis queue component.
 *
 * Waiting jobs live in a sorted set (score = available-at). Reserving moves a
 * job into a second sorted set until [[ack()]] (handler success) or
 * [[release()]] (handler failure). The reserved score is the visibility
 * deadline: a worker that exits before either call leaves the job there, and
 * the next [[reserve()]] treats that as a failed attempt.
 *
 * Configuration:
 *
 * ```php
 * 'queue' => [
 *     'class' => \Yii2S\Swoole\Queue\CoroutineRedisQueue::class,
 *     'redis' => 'redis',
 *     'channel' => 'emails',
 *     'handler' => function (array $payload): void {
 *         // process the job
 *     },
 * ],
 * ```
 *
 * Consume from the console with `php yii queue/serve`.
 *
 * @property CoroutineRedisConnection $redis
 */
class CoroutineRedisQueue extends Component
{
    /** @var CoroutineRedisConnection|array|string the redis component */
    public $redis = 'redis';

    /** @var string queue channel; multiple channels share one redis */
    public string $channel = 'default';

    /** @var int seconds a failed job waits before it becomes visible again */
    public int $ttr = 60;

    /**
     * Seconds a reserved job may stay in flight before the next [[reserve()]]
     * treats the worker as crashed and counts a failed attempt. This is not
     * [[ttr]]: a handler that runs longer than this is delivered again.
     */
    public int $visibilityTimeout = 60;

    /** @var int attempts after which a repeatedly failing job is buried */
    public int $maxAttempts = 3;

    /** @var string redis key prefix */
    public string $keyPrefix = 'yii2s:queue:';

    /** @var callable|null default job handler: function (array $payload, array $job): mixed */
    public $handler = null;

    /**
     * Atomically moves one due job from the delayed set onto the reserved set.
     * KEYS: delayed, reserved. ARGV: now, visibility deadline.
     */
    private const RESERVE_SCRIPT = <<<'LUA'
local now = tonumber(ARGV[1])
local deadline = tonumber(ARGV[2])
local jobs = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', now, 'LIMIT', 0, 1)
if #jobs == 0 then
    return false
end
if redis.call('ZREM', KEYS[1], jobs[1]) == 1 then
    redis.call('ZADD', KEYS[2], deadline, jobs[1])
    return jobs[1]
end
return false
LUA;

    private ?CoroutineRedisConnection $redisInstance = null;

    public function init(): void
    {
        parent::init();
    }

    public function getRedis(): CoroutineRedisConnection
    {
        if ($this->redisInstance === null) {
            $this->redisInstance = Instance::ensure($this->redis, CoroutineRedisConnection::class);
        }

        return $this->redisInstance;
    }

    public function setRedis($redis): void
    {
        $this->redis = $redis;
        $this->redisInstance = null;
    }

    /**
     * Pushes a job onto the queue.
     *
     * @param array $payload job payload (must be JSON-serializable)
     * @param int $delay seconds before the job becomes visible
     * @return string the job id
     */
    public function push(array $payload, int $delay = 0): string
    {
        $job = [
            'id' => bin2hex(random_bytes(8)),
            'payload' => $payload,
            'attempts' => 0,
        ];
        $this->getRedis()->executeCommand('ZADD', [
            $this->getDelayedKey(),
            (string) (time() + $delay),
            $this->encode($job),
        ]);

        return $job['id'];
    }

    /**
     * Reserves the next visible job, if any.
     *
     * Expired reservations are requeued first (the worker that held them did
     * not ack). The move from the delayed set onto the reserved set is one
     * Redis script, so two workers cannot own the same member.
     */
    public function reserve(): ?array
    {
        $this->reclaimExpired();

        $now = time();
        $member = $this->getRedis()->executeCommand('EVAL', [
            self::RESERVE_SCRIPT,
            [
                $this->getDelayedKey(),
                $this->getReservedKey(),
                (string) $now,
                (string) ($now + max(0, $this->visibilityTimeout)),
            ],
            2,
        ]);
        if (!is_string($member) || $member === '') {
            return null;
        }

        return $this->decode($member);
    }

    /**
     * Drops a successfully handled job from the reserved set.
     *
     * @param array $job the job as returned by [[reserve()]]
     */
    public function ack(array $job): void
    {
        $this->getRedis()->executeCommand('ZREM', [$this->getReservedKey(), $this->encode($job)]);
    }

    /**
     * Puts a failed job back into the queue, or buries it when it exceeded
     * [[maxAttempts]]. Also used when a reservation outlives
     * [[visibilityTimeout]].
     *
     * @param array $job the job as returned by [[reserve()]]
     * @param int|null $delay defaults to [[ttr]]
     */
    public function release(array $job, ?int $delay = null): void
    {
        // Remove the member that reserve() stored, before attempts changes the
        // JSON and would no longer match the reserved entry.
        $this->getRedis()->executeCommand('ZREM', [$this->getReservedKey(), $this->encode($job)]);

        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        if ($job['attempts'] >= $this->maxAttempts) {
            $this->getRedis()->executeCommand('LPUSH', [$this->getDeadKey(), $this->encode($job)]);
            return;
        }

        $this->getRedis()->executeCommand('ZADD', [
            $this->getDelayedKey(),
            (string) (time() + ($delay ?? $this->ttr)),
            $this->encode($job),
        ]);
    }

    /**
     * Processes jobs and returns how many were executed.
     *
     * @param int $maxJobs stop after this many jobs; when the queue is empty
     *                     and $maxJobs > 0 the method returns immediately,
     *                     with 0 it keeps polling forever (blocking worker)
     * @param callable|null $handler defaults to the [[handler]] property
     */
    public function run(int $maxJobs = 0, ?callable $handler = null): int
    {
        $handler ??= $this->handler;
        if ($handler === null) {
            throw new \yii\base\InvalidConfigException('A job handler must be configured.');
        }

        $processed = 0;
        while ($maxJobs === 0 || $processed < $maxJobs) {
            $job = $this->reserve();
            if ($job === null) {
                if ($maxJobs > 0) {
                    break; // bounded run: queue is empty, stop here
                }
                $this->idle(0.05);
                continue;
            }

            $processed++;
            try {
                $handler($job['payload'], $job);
                $this->ack($job);
            } catch (\Throwable) {
                $this->release($job);
            }

            if (\Swoole\Coroutine::getCid() !== -1) {
                // Yield between jobs so parallel request coroutines keep running.
                \Swoole\Coroutine::sleep(0.001);
            }
        }

        return $processed;
    }

    /**
     * Suspends the current execution context for the given number of seconds.
     *
     * Inside a coroutine this yields to the scheduler. The console worker
     * (`php yii queue/serve`) runs outside a coroutine, where
     * [[\Swoole\Coroutine::sleep()]] throws `Swoole\Error`; it therefore
     * falls back to a blocking usleep().
     */
    private function idle(float $seconds): void
    {
        \Yii2S\Swoole\Coroutine\Sleep::sleep($seconds);
    }

    /**
     * Consumes jobs forever; intended for the console worker.
     */
    public function serve(?callable $handler = null): void
    {
        $this->run(0, $handler);
    }

    /**
     * Number of jobs waiting (including delayed ones).
     */
    public function countPending(): int
    {
        return (int) $this->getRedis()->executeCommand('ZCARD', [$this->getDelayedKey()]);
    }

    /**
     * Number of jobs reserved by a worker and not yet acked or released.
     */
    public function countReserved(): int
    {
        return (int) $this->getRedis()->executeCommand('ZCARD', [$this->getReservedKey()]);
    }

    /**
     * Number of buried (exhausted) jobs.
     */
    public function countDead(): int
    {
        return (int) $this->getRedis()->executeCommand('LLEN', [$this->getDeadKey()]);
    }

    /**
     * Counts a crashed worker's reservation as a failed attempt and makes the
     * job visible again (or buries it). Only the caller whose ZREM wins
     * touches the job, so two workers cannot both reclaim it.
     */
    private function reclaimExpired(): void
    {
        $redis = $this->getRedis();
        $members = $redis->zRangeByScore(
            $this->getReservedKey(),
            '-inf',
            (string) time(),
            ['limit' => [0, 20]]
        );
        if (!is_array($members)) {
            return;
        }

        foreach ($members as $member) {
            if ((int) $redis->executeCommand('ZREM', [$this->getReservedKey(), (string) $member]) !== 1) {
                continue;
            }
            $job = $this->decode((string) $member);
            if ($job === null) {
                continue;
            }
            $this->release($job);
        }
    }

    private function getDelayedKey(): string
    {
        return $this->keyPrefix . $this->channel . ':delayed';
    }

    private function getReservedKey(): string
    {
        return $this->keyPrefix . $this->channel . ':reserved';
    }

    private function getDeadKey(): string
    {
        return $this->keyPrefix . $this->channel . ':dead';
    }

    private function encode(array $job): string
    {
        return (string) json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $member): ?array
    {
        $job = json_decode($member, true);

        return is_array($job) ? $job : null;
    }
}
