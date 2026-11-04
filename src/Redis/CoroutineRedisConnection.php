<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Redis;

use Yii2S\Swoole\Coroutine\CoroutineContext;
use Yii2S\Swoole\Pools\ConnectionPool;
use Yii2S\Swoole\Pools\HasConnectionPool;
use Yii2S\Swoole\Pools\PooledConnectionTrait;
use yii\base\Component;

/**
 * Coroutine-safe Redis connection built on the native phpredis extension.
 *
 * Requires `SWOOLE_HOOK_NATIVE_REDIS` to be enabled (part of `SWOOLE_HOOK_ALL`)
 * so that phpredis blocking calls yield to the scheduler instead of blocking
 * the whole event loop.
 *
 * Every phpredis method is available through [[__call]]:
 *
 * @method bool set(string $key, mixed $value, mixed ...$options)
 * @method mixed get(string $key)
 * @method int del(string ...$keys)
 * @method bool setex(string $key, int $ttl, mixed $value)
 * @method int incr(string $key)
 * @method bool expire(string $key, int $ttl)
 * @method int ttl(string $key)
 * @method array|false keys(string $pattern)
 * @method array|false zRangeByScore(string $key, string $start, string $end, array $options = [])
 * @method int|false zAdd(string $key, float|string $score, string $member)
 * @method int|false zRem(string $key, string ...$members)
 * @method int|false zCard(string $key)
 * @method int|false lPush(string $key, mixed ...$values)
 * @method int|false lLen(string $key)
 * @method \Redis multi(int $mode = 0)
 * @method \Redis pipeline()
 * @method mixed auth(mixed ...$args)
 * @method bool select(int $database)
 * @method bool close()
 * @method bool isConnected()
 * @method mixed ping()
 *
 * Usage:
 *
 * ```php
 * Yii::$app->redis->set('key', 'value');
 * Yii::$app->redis->executeCommand('SETEX', ['key', 60, 'value']);
 * ```
 */
class CoroutineRedisConnection extends Component implements HasConnectionPool
{
    use PooledConnectionTrait;

    public string $hostname = '127.0.0.1';

    public int $port = 6379;

    /**
     * @var string|null AUTH password, null when the server has no password
     */
    public ?string $password = null;

    public int $database = 0;

    /**
     * @var float connect timeout in seconds
     */
    public float $connectionTimeout = 3.0;

    /**
     * @var float read/write timeout in seconds (0 = never expire)
     */
    public float $dataTimeout = 3.0;

    private ?\Redis $sharedConnection = null;

    private const CONTEXT_KEY_PREFIX = 'yii2s.redis.connection.';

    public function getPool(): ConnectionPool
    {
        if ($this->pool === null) {
            $this->pool = new ConnectionPool(
                fn (): \Redis => $this->createConnection(),
                $this->poolMaxActive,
                $this->poolWaitTimeout,
                function (\Redis $redis): void {
                    try {
                        $redis->close();
                    } catch (\Throwable) {
                    }
                },
                $this->enableHealthCheck
                    ? function (\Redis $redis): bool {
                        try {
                            return $redis->isConnected() && $redis->ping() !== false;
                        } catch (\Throwable) {
                            return false;
                        }
                    }
                : null,
                $this->poolMinIdle,
                $this->poolMaxLifetime,
                $this->poolIdleTimeout
            );
        }

        return $this->pool;
    }

    /**
     * Borrows the connection bound to the current coroutine (creating it on
     * first use) and keeps it sticky until release.
     */
    public function borrow(): \Redis
    {
        // Outside a coroutine the Swoole channel pool is not available;
        // fall back to a single detached connection.
        if (!$this->enableCoroutinePooling || \Swoole\Coroutine::getCid() === -1) {
            return $this->sharedConnection ??= $this->createConnection();
        }

        $key = self::CONTEXT_KEY_PREFIX . spl_object_id($this);
        $connection = CoroutineContext::get($key);
        if ($connection instanceof \Redis) {
            return $connection;
        }

        $connection = $this->getPool()->borrow();
        CoroutineContext::set($key, $connection);

        return $connection;
    }

    /**
     * Executes a Redis command on the coroutine-bound connection.
     *
     * @param string $name command name, e.g. `GET`, `SET`, `SETEX`
     * @param array $params command arguments
     * @return mixed the raw phpredis result
     * @throws \RuntimeException when the connection fails
     */
    public function executeCommand(string $name, array $params = []): mixed
    {
        // The command execution is the fallible network operation, so the
        // circuit breaker wraps it here - not the sticky borrow(), which would
        // only guard the first connect and miss failures mid-request.
        $breaker = $this->getCircuitBreaker();
        if ($breaker === null) {
            return $this->executeCommandInternal($name, $params);
        }

        return $breaker->call(fn (): mixed => $this->executeCommandInternal($name, $params));
    }

    /**
     * Returns the coroutine-bound connection to the pool.
     *
     * Called automatically at the end of each HTTP request. Safe to call
     * multiple times.
     */
    public function release(): void
    {
        $this->releaseCurrent(false);
    }

    /**
     * Closes the pool and all its connections.
     */
    public function closePool(): void
    {
        $this->releaseCurrent(false);
        $this->pool?->close();
        $this->pool = null;

        if ($this->sharedConnection !== null) {
            try {
                $this->sharedConnection->close();
            } catch (\Throwable) {
            }
            $this->sharedConnection = null;
        }
    }

    /**
     * Opens a connection eagerly (kept for Yii2-style configuration flows).
     */
    public function open(): void
    {
        $this->borrow();
    }

    public function close(): void
    {
        $this->closePool();
    }

    /**
     * Allows direct phpredis calls: `Yii::$app->redis->get('key')`.
     */
    public function __call($name, $params)
    {
        return $this->executeCommand($name, $params);
    }

    /**
     * Executes a Redis command on the coroutine-bound connection.
     */
    private function executeCommandInternal(string $name, array $params = []): mixed
    {
        $connection = $this->borrow();
        $method = strtolower($name);

        try {
            return $connection->{$method}(...$params);
        } catch (\RedisException $e) {
            // The connection is broken: drop it from the coroutine binding and
            // the pool so the next call creates a fresh one.
            $this->releaseCurrent(true);
            throw new \RuntimeException(sprintf(
                'Redis command "%s" failed: %s',
                $name,
                $e->getMessage()
            ), 0, $e);
        }
    }

    private function createConnection(): \Redis
    {
        $redis = new \Redis();
        try {
            if (!$redis->connect($this->hostname, $this->port, $this->connectionTimeout)) {
                throw new \RuntimeException(sprintf(
                    'Failed to connect to Redis at %s:%d.',
                    $this->hostname,
                    $this->port
                ));
            }

            if ($this->password !== null && $this->password !== '') {
                $redis->auth($this->password);
            }

            if ($this->database !== 0) {
                $redis->select($this->database);
            }

            if ($this->dataTimeout > 0) {
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, (int) ceil($this->dataTimeout));
            }
        } catch (\Throwable $e) {
            try {
                $redis->close();
            } catch (\Throwable) {
            }
            throw $e;
        }

        return $redis;
    }

    private function releaseCurrent(bool $discard): void
    {
        $key = self::CONTEXT_KEY_PREFIX . spl_object_id($this);
        $connection = CoroutineContext::get($key);
        if ($connection instanceof \Redis) {
            CoroutineContext::remove($key);
            $this->getPool()->{$discard ? 'discard' : 'return'}($connection);
        }
    }
}
