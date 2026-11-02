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

    protected function getValue($key): mixed
    {
        return $this->redis->executeCommand('GET', [$key]);
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
            $result = $this->redis->executeCommand('SET', [$key, $value, ['NX', 'EX', (int) ceil($duration)]]);
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
