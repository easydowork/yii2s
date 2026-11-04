<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\redis;

use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use Yii2S\Swoole\Resilience\CircuitBreaker;
use Yii2S\Swoole\Resilience\CircuitOpenException;
use yii2stest\TestCase;

/**
 * @group redis
 */
class CoroutineRedisConnectionTest extends TestCase
{
    public function createConnection(): CoroutineRedisConnection
    {
        $config = self::getParam('redis');

        return new CoroutineRedisConnection($config);
    }

    public function testSetGetDelete(): void
    {
        $redis = $this->createConnection();
        $this->runInCoroutine(function () use ($redis): void {
            $redis->set('yii2stest:key', 'value1');
            $this->assertSame('value1', $redis->get('yii2stest:key'));

            $redis->set('yii2stest:key', 'value2');
            $this->assertSame('value2', $redis->get('yii2stest:key'));

            $this->assertSame(1, $redis->del('yii2stest:key'));
            $this->assertFalse($redis->get('yii2stest:key'));
        });
    }

    public function testExecuteCommand(): void
    {
        $redis = $this->createConnection();
        $this->runInCoroutine(function () use ($redis): void {
            $redis->executeCommand('SET', ['yii2stest:cmd', 'v']);
            $this->assertSame('v', $redis->executeCommand('GET', ['yii2stest:cmd']));
            $redis->executeCommand('DEL', ['yii2stest:cmd']);
        });
    }

    public function testConnectionIsStickyInsideCoroutine(): void
    {
        $redis = $this->createConnection();
        $this->runInCoroutine(function () use ($redis): void {
            $redis->set('yii2stest:sticky', '1');
            $first = $redis->borrow();
            $second = $redis->borrow();

            $this->assertSame($first, $second);
            $redis->release();
        });
    }

    public function testReleaseReturnsConnectionToPool(): void
    {
        $redis = $this->createConnection();
        $this->runInCoroutine(function () use ($redis): void {
            $redis->set('yii2stest:release', '1');
            $this->assertSame(0, $redis->getPool()->getIdleCount());

            $redis->release();
            $this->assertSame(1, $redis->getPool()->getIdleCount());

            $redis->release();
            $this->assertSame(1, $redis->getPool()->getIdleCount());
        });
    }

    public function testConcurrentCoroutinesGetSeparateConnections(): void
    {
        $redis = $this->createConnection();
        $idle = $this->runInCoroutine(function () use ($redis): int {
            $wait = new \Swoole\Coroutine\WaitGroup();
            $ids = [];

            for ($i = 0; $i < 5; $i++) {
                $wait->add();
                \Swoole\Coroutine::create(function () use ($redis, $wait, &$ids, $i): void {
                    try {
                        $redis->set('yii2stest:co:' . $i, (string) $i);
                        $ids[$i] = spl_object_id($redis->borrow());
                    } finally {
                        $redis->release();
                        $wait->done();
                    }
                });
            }
            $wait->wait();

            foreach ($ids as $i => $id) {
                $this->assertSame((string) $i, $redis->get('yii2stest:co:' . $i));
                $redis->del('yii2stest:co:' . $i);
            }
            $this->assertCount(5, array_unique($ids));

            // The assertions above borrowed a connection in this coroutine.
            $redis->release();

            return $redis->getPool()->getIdleCount();
        });

        $this->assertSame(5, $idle);
    }

    public function testCircuitBreakerOpensWhenRedisIsUnreachable(): void
    {
        $redis = new CoroutineRedisConnection([
            'hostname' => '127.0.0.1',
            'port' => 1, // nothing listens here
            'enableCircuitBreaker' => true,
            'circuitFailureThreshold' => 2,
            'circuitCooldown' => 30.0,
        ]);

        $this->runInCoroutine(function () use ($redis): void {
            for ($i = 0; $i < 2; $i++) {
                try {
                    $redis->get('whatever');
                    $this->fail('expected a connection failure');
                } catch (\Throwable) {
                }
            }

            $this->assertSame(CircuitBreaker::STATE_OPEN, $redis->getCircuitBreaker()->getState());

            // The command execution (not just the first borrow) is guarded, so
            // the third call fails fast without touching the network.
            $this->expectException(CircuitOpenException::class);
            $redis->get('whatever');
        });
    }
}
