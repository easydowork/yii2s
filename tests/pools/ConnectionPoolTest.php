<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\pools;

use Yii2S\Swoole\Pools\ConnectionPool;
use yii2stest\TestCase;

class ConnectionPoolTest extends TestCase
{
    public function testBorrowCreatesConnectionLazily(): void
    {
        $created = 0;
        $pool = new ConnectionPool(function () use (&$created): \stdClass {
            $created++;

            return new \stdClass();
        });

        $connection = $this->runInCoroutine(fn () => $pool->borrow());

        $this->assertInstanceOf(\stdClass::class, $connection);
        $this->assertSame(1, $created);
        $this->assertSame(0, $pool->getIdleCount());
        $this->assertSame(1, $pool->getSize());
    }

    public function testReturnedConnectionIsReused(): void
    {
        $pool = new ConnectionPool(fn (): object => new \stdClass());
        $first = null;

        $this->runInCoroutine(function () use ($pool, &$first): void {
            $first = $pool->borrow();
            $pool->return($first);
        });

        $this->assertSame(1, $pool->getIdleCount());

        $second = $this->runInCoroutine(fn () => $pool->borrow());
        $this->assertSame($first, $second, 'idle connections should be reused');
    }

    public function testPoolExhaustionThrowsAfterTimeout(): void
    {
        $pool = new ConnectionPool(
            fn (): object => new \stdClass(),
            1,          // maxActive
            0.1         // waitTimeout
        );

        $exception = null;
        try {
            $this->runInCoroutine(function () use ($pool): void {
                $pool->borrow();
                $pool->borrow(); // exhausted: the single connection is in use
            });
        } catch (\RuntimeException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('exhausted', $exception->getMessage());
    }

    public function testDiscardShrinksPool(): void
    {
        $pool = new ConnectionPool(fn (): object => new \stdClass());

        $this->runInCoroutine(function () use ($pool): void {
            $connection = $pool->borrow();
            $pool->discard($connection);
        });

        $this->assertSame(0, $pool->getSize());
        $this->assertSame(0, $pool->getIdleCount());
    }

    public function testCloseMarksPoolUnavailable(): void
    {
        $pool = new ConnectionPool(fn (): object => new \stdClass());

        $this->runInCoroutine(function () use ($pool): void {
            $pool->return($pool->borrow());
        });
        $pool->close();

        $this->assertTrue($pool->isClosed());
        $this->expectException(\RuntimeException::class);
        $this->runInCoroutine(fn () => $pool->borrow());
    }

    public function testSweepReclaimsConnectionsOfDeadCoroutines(): void
    {
        $pool = new ConnectionPool(fn (): object => new \stdClass(), 5, 0.1);

        $this->runInCoroutine(function () use ($pool): void {
            // Sub-coroutines borrow and exit without returning - exactly what
            // parallel DB query sub-coroutines do when nobody releases them.
            for ($i = 0; $i < 3; $i++) {
                \Swoole\Coroutine::create(function () use ($pool): void {
                    $pool->borrow();
                });
            }
            // Let the sub-coroutines borrow and die.
            \Swoole\Coroutine::sleep(0.1);

            $reclaimed = $pool->sweep();
            $this->assertSame(3, $reclaimed);
            $this->assertSame(0, $pool->getSize());
            $this->assertSame(0, $pool->getMetrics()['active']);
        });

        // The pool is fully usable again afterwards.
        $this->runInCoroutine(function () use ($pool): void {
            $pool->return($pool->borrow());
        });
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testMetricsTrackPoolActivity(): void
    {
        $pool = new ConnectionPool(fn (): object => new \stdClass(), 2, 0.1);

        $metrics = $pool->getMetrics();
        $this->assertSame(2, $metrics['max_active']);
        $this->assertSame(0, $metrics['size']);
        $this->assertSame(0, $metrics['total_borrowed']);

        $this->runInCoroutine(function () use ($pool): void {
            $first = $pool->borrow();
            $second = $pool->borrow(); // pool now exhausted (max 2)

            $metrics = $pool->getMetrics();
            $this->assertSame(2, $metrics['size']);
            $this->assertSame(2, $metrics['active']);
            $this->assertSame(0, $metrics['idle']);
            $this->assertSame(2, $metrics['total_borrowed']);

            // A third borrow must block and time out.
            try {
                $pool->borrow();
                $this->fail('expected pool exhaustion');
            } catch (\RuntimeException) {
            }

            $metrics = $pool->getMetrics();
            $this->assertSame(1, $metrics['total_timeouts']);
            $this->assertSame(0, $metrics['waiting'], 'waiting count must drain after timeout');

            $pool->return($first);
            $pool->discard($second);

            $metrics = $pool->getMetrics();
            $this->assertSame(1, $metrics['idle']);
            $this->assertSame(0, $metrics['active'], 'the borrowed second was discarded');
            $this->assertSame(1, $metrics['total_discarded']);
        });
    }

    public function testMaxLifetimeRecyclesExpiredConnectionOnBorrow(): void
    {
        $pool = new ConnectionPool(fn (): object => new \stdClass(), 5, 0.1, null, null, 0, 0.05);

        $this->runInCoroutine(function () use ($pool): void {
            $pool->return($pool->borrow());
            $this->assertSame(1, $pool->getIdleCount());

            \Swoole\Coroutine::sleep(0.1); // exceed the 50ms max lifetime

            $pool->return($pool->borrow()); // borrow recycles the expired connection
        });

        $metrics = $pool->getMetrics();
        $this->assertSame(1, $metrics['total_discarded'], 'expired connection is recycled');
        $this->assertSame(2, $metrics['total_created'], 'a replacement connection is created');
        $this->assertSame(1, $pool->getSize());
    }

    public function testMaxLifetimeRecyclesExpiredConnectionOnReturn(): void
    {
        $pool = new ConnectionPool(fn (): object => new \stdClass(), 5, 0.1, null, null, 0, 0.05);

        $this->runInCoroutine(function () use ($pool): void {
            $connection = $pool->borrow();
            \Swoole\Coroutine::sleep(0.1); // exceed the 50ms max lifetime
            $pool->return($connection);    // return() recycles instead of reusing
        });

        $metrics = $pool->getMetrics();
        $this->assertSame(1, $metrics['total_discarded']);
        $this->assertSame(0, $pool->getIdleCount(), 'expired connection is not returned to the pool');
        $this->assertSame(0, $pool->getSize());
    }

    public function testMaintainReplenishesIdleToMinIdle(): void
    {
        $pool = new ConnectionPool(fn (): object => new \stdClass(), 5, 0.1, null, null, 3);

        $this->runInCoroutine(function () use ($pool): void {
            $pool->maintain();
        });

        $this->assertSame(3, $pool->getIdleCount(), 'idle channel is topped up to min-idle');
        $this->assertSame(3, $pool->getSize());
    }

    public function testMaintainRecyclesStaleIdleConnection(): void
    {
        $pool = new ConnectionPool(
            fn (): object => new \stdClass(),
            5,
            0.1,
            null,
            fn (object $connection): bool => false, // validator always reports dead
            0,
            0.0,
            0.05 // idleTimeout
        );

        $this->runInCoroutine(function () use ($pool): void {
            $pool->return($pool->borrow());
            $this->assertSame(1, $pool->getIdleCount());

            \Swoole\Coroutine::sleep(0.1); // idle beyond the 50ms idleTimeout

            $recycled = $pool->maintain();
            $this->assertSame(1, $recycled, 'stale idle connection is recycled');
            $this->assertSame(0, $pool->getSize());
        });
    }
}
