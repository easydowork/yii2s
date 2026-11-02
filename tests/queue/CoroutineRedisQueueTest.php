<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\queue;

use Yii2S\Swoole\Queue\CoroutineRedisQueue;
use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use yii2stest\TestCase;

/**
 * @group queue
 * @group redis
 */
class CoroutineRedisQueueTest extends TestCase
{
    private CoroutineRedisConnection $redis;

    private CoroutineRedisQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new CoroutineRedisConnection(self::getParam('redis'));
        $this->queue = new CoroutineRedisQueue([
            'redis' => $this->redis,
            'channel' => 'test-' . bin2hex(random_bytes(4)),
            'ttr' => 0,
            'maxAttempts' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        $this->runInCoroutine(function (): void {
            $this->redis->del(
                'yii2s:queue:' . $this->queue->channel . ':delayed',
                'yii2s:queue:' . $this->queue->channel . ':dead'
            );
        });
        parent::tearDown();
    }

    public function testPushAndReserve(): void
    {
        $this->runInCoroutine(function (): void {
            $this->assertSame(0, $this->queue->countPending());

            $id = $this->queue->push(['action' => 'mail', 'to' => 'alice@example.com']);
            $this->assertNotEmpty($id);
            $this->assertSame(1, $this->queue->countPending());

            $job = $this->queue->reserve();
            $this->assertIsArray($job);
            $this->assertSame($id, $job['id']);
            $this->assertSame('mail', $job['payload']['action']);
            $this->assertSame(0, $job['attempts']);

            // The reserved job is no longer pending.
            $this->assertSame(0, $this->queue->countPending());
            $this->assertNull($this->queue->reserve());
        });
    }

    public function testDelayedJobIsNotVisibleBeforeDeadline(): void
    {
        $this->runInCoroutine(function (): void {
            $this->queue->push(['action' => 'later'], 3600);
            $this->assertNull($this->queue->reserve());
            $this->assertSame(1, $this->queue->countPending());
        });
    }

    public function testRunExecutesHandlerAndCountsJobs(): void
    {
        $this->runInCoroutine(function (): void {
            $this->queue->push(['n' => 1]);
            $this->queue->push(['n' => 2]);
            $this->queue->push(['n' => 3]);

            $seen = [];
            $processed = $this->queue->run(3, function (array $payload) use (&$seen): void {
                $seen[] = $payload['n'];
            });

            $this->assertSame(3, $processed);
            sort($seen);
            $this->assertSame([1, 2, 3], $seen);
            $this->assertSame(0, $this->queue->countPending());
        });
    }

    public function testFailingJobIsRetriedWithIncrementedAttempts(): void
    {
        $this->runInCoroutine(function (): void {
            $this->queue->push(['n' => 1]);

            $this->queue->run(1, function (): void {
                throw new \RuntimeException('boom');
            });

            $job = $this->queue->reserve();
            $this->assertIsArray($job);
            $this->assertSame(1, $job['attempts'], 'failed job must be retried with attempts+1');
        });
    }

    public function testExhaustedJobIsBuriedToDeadList(): void
    {
        $this->runInCoroutine(function (): void {
            $queue = new CoroutineRedisQueue([
                'redis' => $this->redis,
                'channel' => $this->queue->channel,
                'maxAttempts' => 1,
            ]);
            $queue->push(['n' => 1]);

            $queue->run(1, function (): void {
                throw new \RuntimeException('boom');
            });

            $this->assertSame(0, $queue->countPending());
            $this->assertSame(1, $queue->countDead());
        });
    }

    public function testRunWithoutHandlerConfigurationThrows(): void
    {
        $this->expectException(\yii\base\InvalidConfigException::class);
        $this->runInCoroutine(fn () => $this->queue->run(1));
    }

    /**
     * `queue/serve` runs outside a coroutine; polling an empty queue must not
     * call Swoole\Coroutine::sleep() there (it throws Swoole\Error). Regression
     * test for the worker crashing on an empty queue.
     */
    public function testServeDoesNotCrashPollingEmptyQueueOutsideCoroutine(): void
    {
        $queue = new class(['redis' => $this->redis, 'channel' => 'empty-poll']) extends CoroutineRedisQueue {
            public int $polls = 0;

            public function reserve(): ?array
            {
                $this->polls++;
                if ($this->polls > 2) {
                    throw new \RuntimeException('stop-sentinel');
                }

                return null; // always empty
            }
        };

        try {
            $queue->run(0, static function (array $payload): void {
            });
            $this->fail('expected the stop-sentinel exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('stop-sentinel', $e->getMessage());
        }

        $this->assertSame(3, $queue->polls);
    }
}
