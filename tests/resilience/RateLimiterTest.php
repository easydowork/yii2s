<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\resilience;

use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use Yii2S\Swoole\Resilience\RateLimiter;
use yii2stest\TestCase;

class RateLimiterTest extends TestCase
{
    public function testAllowWithinLimit(): void
    {
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379]);
        $limiter = new RateLimiter($redis, 'yii2s:test:ratelimit:');

        $this->runInCoroutine(function () use ($limiter): void {
            $key = 'test-' . uniqid();

            $this->assertTrue($limiter->allow($key, 2, 60));
            $this->assertTrue($limiter->allow($key, 2, 60));
            $this->assertFalse($limiter->allow($key, 2, 60), 'third request in the window must be rejected');

            $this->assertSame(0, $limiter->remaining($key, 2));
        });
    }
}
