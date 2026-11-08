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
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379, 'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null]);
        $limiter = new RateLimiter($redis, 'yii2s:test:ratelimit:');

        $this->runInCoroutine(function () use ($limiter): void {
            $key = 'test-' . uniqid();

            $this->assertTrue($limiter->allow($key, 2, 60));
            $this->assertTrue($limiter->allow($key, 2, 60));
            $this->assertFalse($limiter->allow($key, 2, 60), 'third request in the window must be rejected');

            $this->assertSame(0, $limiter->remaining($key, 2));
        });
    }

    public function testInvalidLimitThrows(): void
    {
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379, 'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null]);
        $limiter = new RateLimiter($redis, 'yii2s:test:ratelimit:');

        $this->expectException(\InvalidArgumentException::class);
        $limiter->allow('test', 0, 60);
    }

    public function testConcurrentAllowIsAccurate(): void
    {
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379, 'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null]);
        $limiter = new RateLimiter($redis, 'yii2s:test:ratelimit:');

        $this->runInCoroutine(function () use ($limiter): void {
            $key = 'test-concurrent-' . uniqid();
            $done = new \Swoole\Coroutine\Channel(20);

            for ($i = 0; $i < 20; $i++) {
                \Swoole\Coroutine::create(function () use ($limiter, $key, $done): void {
                    $done->push($limiter->allow($key, 10, 60));
                });
            }

            $allowed = 0;
            for ($i = 0; $i < 20; $i++) {
                if ($done->pop(5) === true) {
                    $allowed++;
                }
            }

            $this->assertSame(10, $allowed, 'exactly `limit` requests must be allowed under concurrency');
        });
    }
}
