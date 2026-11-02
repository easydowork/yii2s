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
}
