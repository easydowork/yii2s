<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\resilience;

use Yii;
use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use Yii2S\Swoole\Resilience\RateLimiter;
use yii\web\Controller;
use yii\web\TooManyRequestsHttpException;
use yii2stest\TestCase;

class RateLimiterTest extends TestCase
{
    private function limiter(CoroutineRedisConnection $redis): RateLimiter
    {
        return new RateLimiter([
            'redis' => $redis,
            'prefix' => 'yii2s:test:ratelimit:',
        ]);
    }

    public function testAllowWithinLimit(): void
    {
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379, 'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null]);
        $limiter = $this->limiter($redis);

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
        $limiter = $this->limiter($redis);

        $this->expectException(\InvalidArgumentException::class);
        $limiter->allow('test', 0, 60);
    }

    public function testConcurrentAllowIsAccurate(): void
    {
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379, 'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null]);
        $limiter = $this->limiter($redis);

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

    public function testFilterLimitsByClientIp(): void
    {
        $this->mockWebApplication();
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379, 'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null]);
        $controller = new RateLimitProbeController('site', Yii::$app);
        $action = $controller->createAction('api');
        $filter = new RateLimiter([
            'redis' => $redis,
            'prefix' => 'yii2s:test:ratelimit:' . uniqid('', true) . ':',
            'limit' => 2,
            'window' => 60,
        ]);

        try {
            $this->runInCoroutine(function () use ($filter, $action): void {
                $this->setClientIp('203.0.113.10');
                $this->assertTrue($filter->beforeAction($action));
                $this->assertTrue($filter->beforeAction($action));
                $this->assertSame('0', Yii::$app->response->getHeaders()->get('X-Rate-Limit-Remaining'));

                try {
                    $filter->beforeAction($action);
                    $this->fail('the third request from this IP must be rejected');
                } catch (TooManyRequestsHttpException $e) {
                    $this->assertSame(429, $e->statusCode);
                }

                $this->setClientIp('203.0.113.11');
                $this->assertTrue($filter->beforeAction($action), 'a different client IP has its own window');
            });
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }

    private function setClientIp(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $request = Yii::$app->getRequest();
        $cached = new \ReflectionProperty($request, '_ip');
        $cached->setValue($request, null);
    }
}

class RateLimitProbeController extends Controller
{
    public function actionApi(): string
    {
        return 'ok';
    }
}
