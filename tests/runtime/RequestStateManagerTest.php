<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\runtime;

use Yii;
use Yii2S\Swoole\Queue\CoroutineRedisQueue;
use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use Yii2S\Swoole\Runtime\RequestStateManager;
use yii2stest\TestCase;

/**
 * @group runtime
 */
class RequestStateManagerTest extends TestCase
{
    public function testCleanupIgnoresQueueReleaseMethod(): void
    {
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379, 'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null]);
        $queue = new CoroutineRedisQueue([
            'redis' => $redis,
            'channel' => 'test',
            'handler' => static function (array $payload): void {
            },
        ]);

        $this->mockWebApplication([
            'components' => [
                'redis' => $redis,
                'queue' => $queue,
            ],
        ]);

        $stateManager = new RequestStateManager();
        $stateManager->beginRequest();

        $this->runInCoroutine(static function () use ($stateManager): void {
            // Must not throw: the queue's release(array $job) requires a job
            // argument and would fail if it were treated as a pooled component.
            $stateManager->cleanup(Yii::$app);
        });

        $this->assertSame(0, RequestStateManager::getActiveRequests());

        // The queue's release(array $job) must not be invoked with zero
        // arguments during cleanup (it is not a connection-pool component).
        foreach (Yii::getLogger()->messages as $message) {
            $this->assertStringNotContainsString("Failed to release component 'queue'", (string) $message[0]);
        }
    }
}
