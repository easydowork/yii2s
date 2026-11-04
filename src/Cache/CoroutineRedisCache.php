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
    /**
     * @var CoroutineRedisConnection|array|string the redis connection object or
     * the application component ID / configuration of it.
     */
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
        $prefix = $this->keyPrefix;
        if ($prefix === '') {
            return $this->redis->executeCommand('FLUSHDB') === true;
        }

        // Delete only keys that belong to this cache instance.
        $iterator = null;
        do {
            $keys = $this->redis->executeCommand('SCAN', [$iterator, 'MATCH', $prefix . '*', 'COUNT', 100]);
            $iterator = (int) $keys[0];
            if (!empty($keys[1])) {
                $this->redis->executeCommand('DEL', $keys[1]);
            }
        } while ($iterator !== 0);

        return true;
    }
}
