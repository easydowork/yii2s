<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\resilience;

use Yii2S\Swoole\Resilience\Retry;
use yii2stest\TestCase;

class RetryTest extends TestCase
{
    public function testSucceedsOnFirstAttempt(): void
    {
        $this->assertSame('ok', (new Retry())->call(fn (): string => 'ok'));
    }

    public function testRetriesThenSucceeds(): void
    {
        $attempts = 0;
        $result = (new Retry())->call(function () use (&$attempts): string {
            $attempts++;
            if ($attempts < 3) {
                throw new \RuntimeException('transient');
            }

            return 'recovered';
        }, 5, [], 0.01);

        $this->assertSame('recovered', $result);
        $this->assertSame(3, $attempts);
    }

    public function testNonRetryableExceptionThrowsImmediately(): void
    {
        $attempts = 0;

        $this->expectException(\LogicException::class);
        (new Retry())->call(function () use (&$attempts): void {
            $attempts++;
            throw new \LogicException('fatal');
        }, 5, [\RuntimeException::class], 0.01);

        $this->assertSame(1, $attempts);
    }

    public function testExhaustsAttemptsThenThrows(): void
    {
        $attempts = 0;

        $this->expectException(\RuntimeException::class);
        (new Retry())->call(function () use (&$attempts): void {
            $attempts++;
            throw new \RuntimeException('still failing');
        }, 3, [], 0.01);

        $this->assertSame(3, $attempts);
    }

    public function testInvalidMaxAttemptsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Retry())->call(fn (): string => 'x', 0);
    }
}
