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
 * Jobs are stored in a sorted set (score = available-at timestamp), which
 * covers delayed delivery and visibility timeouts in a single structure.
 * Reserving is made atomic by a ZRANGEBYSCORE + ZREM pair: only the worker
 * whose ZREM actually removes the member owns the job.
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

    /** @var int attempts after which a repeatedly failing job is buried */
    public int $maxAttempts = 3;

    /** @var string redis key prefix */
    public string $keyPrefix = 'yii2s:queue:';

    /** @var callable|null default job handler: function (array $payload, array $job): mixed */
    public $handler = null;

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
     * Reserves the next visible job, if any. The ZREM makes reservation
     * atomic: concurrent workers cannot reserve the same job twice.
     */
    public function reserve(): ?array
    {
        $redis = $this->getRedis();
        // phpredis uses an options array instead of the raw LIMIT argument.
        $members = $redis->zRangeByScore($this->getDelayedKey(), '-inf', (string) time(), ['limit' => [0, 1]]);
        if (!is_array($members) || $members === []) {
            return null;
        }

        $member = (string) $members[0];
        if ((int) $redis->executeCommand('ZREM', [$this->getDelayedKey(), $member]) !== 1) {
            return null; // another worker won the race
        }

        return $this->decode($member);
    }

    /**
     * Puts a failed job back into the queue, or buries it when it exceeded
     * [[maxAttempts]].
     *
     * @param array $job the job as returned by [[reserve()]]
     * @param int|null $delay defaults to [[ttr]]
     */
    public function release(array $job, ?int $delay = null): void
    {
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
                \Swoole\Coroutine::sleep(0.05);
                continue;
            }

            $processed++;
            try {
                $handler($job['payload'], $job);
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
     * Number of buried (exhausted) jobs.
     */
    public function countDead(): int
    {
        return (int) $this->getRedis()->executeCommand('LLEN', [$this->getDeadKey()]);
    }

    private function getDelayedKey(): string
    {
        return $this->keyPrefix . $this->channel . ':delayed';
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
