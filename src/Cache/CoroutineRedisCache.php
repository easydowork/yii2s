<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Cache;

use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use yii\caching\Cache;
use yii\di\Instance;

/**
 * Redis cache component that is safe for coroutine environments.
 *
 * All reads and writes go through a [[CoroutineRedisConnection]], so every
 * operation runs on a pooled, coroutine-bound phpredis connection.
 *
 * ```php
 * 'cache' => [
 *     'class' => \Yii2S\Swoole\Cache\CoroutineRedisCache::class,
 *     'redis' => 'redis', // component id of the redis connection
 * ],
 * ```
 */
class CoroutineRedisCache extends Cache
{
    /** @var CoroutineRedisConnection|string|array the connection object, or the application component ID / configuration of it (init() unwraps to the object) */
    public $redis = 'redis';

    /**
     * @var string redis keys prefix for this cache instance
     */
    public $keyPrefix = 'yii2s:cache:';

    public function init(): void
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, CoroutineRedisConnection::class);
    }

    /**
     * Single-flight [[getOrSet]]: on a cache miss a short lock is taken, so a
     * burst of concurrent requests on a cold cache runs the callback **once**
     * and the rest wait for the freshly cached value - instead of every request
     * running the (expensive) callback at once (the "thundering herd").
     *
     * {@inheritdoc}
     */
    public function getOrSet($key, $callable, $duration = null, $dependency = null)
    {
        $value = $this->get($key);
        if ($value !== false) {
            return $value;
        }

        $lockKey = $key . ':lock';
        $lockTtl = 10;
        $acquired = $this->add($lockKey, '1', $lockTtl);

        if (!$acquired) {
            // Another coroutine is computing; wait for it to publish the value.
            $deadline = microtime(true) + $lockTtl;
            while (microtime(true) < $deadline) {
                $this->sleepForLockRetry();
                $value = $this->get($key);
                if ($value !== false) {
                    return $value;
                }
            }
            // The lock holder failed (or overran the TTL); compute it ourselves.
        }

        try {
            $value = call_user_func($callable, $this);
            if (!$this->set($key, $value, $duration, $dependency)) {
                \Yii::warning('Failed to set cache value for key ' . json_encode($key), __METHOD__);
            }
        } finally {
            if ($acquired) {
                $this->delete($lockKey);
            }
        }

        return $value;
    }

    protected function getValue($key): mixed
    {
        return $this->redis->executeCommand('GET', [$key]);
    }

    /**
     * Yields between lock-retry polls without blocking the worker.
     */
    private function sleepForLockRetry(): void
    {
        \Yii2S\Swoole\Coroutine\Sleep::sleep(0.05);
    }

    protected function setValue($key, $value, $duration): bool
    {
        if ($duration > 0) {
            $result = $this->redis->executeCommand('SETEX', [$key, (int) ceil($duration), $value]);
        } else {
            $result = $this->redis->executeCommand('SET', [$key, $value]);
        }

        return $result === true || $result === 'OK';
    }

    protected function addValue($key, $value, $duration): bool
    {
        if ($duration > 0) {
            // phpredis expects the EX option as an associative `'EX' => seconds`
            // entry; a numeric-indexed `['NX', 'EX', 10]` silently drops the
            // expiry, leaving the key without a TTL.
            $result = $this->redis->executeCommand('SET', [$key, $value, ['NX', 'EX' => (int) ceil($duration)]]);
        } else {
            $result = $this->redis->executeCommand('SET', [$key, $value, ['NX']]);
        }

        return $result === true || $result === 'OK';
    }

    protected function deleteValue($key): bool
    {
        return (bool) $this->redis->executeCommand('DEL', [$key]);
    }

    protected function flushValues(): bool
    {
        // init() has unwrapped the component id/config into the connection.
        assert($this->redis instanceof CoroutineRedisConnection);
        $prefix = $this->keyPrefix;
        if ($prefix === '') {
            return $this->redis->executeCommand('FLUSHDB') === true;
        }

        // Delete only keys that belong to this cache instance. rawCommand()
        // instead of executeCommand/SCAN: phpredis's scan() is a positional
        // 4-argument signature with the cursor passed BY REFERENCE, which the
        // command-forwarding call would break (ArgumentCountError, and the
        // cursor could never be written back). rawCommand returns
        // [cursor, key...].
        $iterator = 0;
        do {
            $reply = $this->redis->rawCommand(
                'SCAN',
                (string) $iterator,
                'MATCH',
                $prefix . '*',
                'COUNT',
                100
            );
            if (!is_array($reply) || count($reply) === 0) {
                // A failed scan (false on error) or a structurally empty
                // reply must not be reported as a successful flush. A real
                // SCAN reply always carries at least the cursor.
                return false;
            }
            $iterator = (int) ($reply[0] ?? 0);
            $keys = array_slice($reply, 1);
            if ($keys !== []) {
                $this->redis->executeCommand('DEL', $keys);
            }
        } while ($iterator !== 0);

        return true;
    }
}
