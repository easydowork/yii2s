<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\redis;

use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use yii2stest\TestCase;

/**
 * @group redis
 */
class RedisMultiTest extends TestCase
{
    private CoroutineRedisConnection $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new CoroutineRedisConnection(self::getParam('redis'));
    }

    protected function tearDown(): void
    {
        $this->runInCoroutine(function (): void {
            $this->redis->del('yii2stest:multi:a', 'yii2stest:multi:b', 'yii2stest:multi:pipe');
        });
        parent::tearDown();
    }

    public function testMultiExecCommitsAtomically(): void
    {
        $this->runInCoroutine(function (): void {
            $result = $this->redis->multi()
                ->set('yii2stest:multi:a', '1')
                ->set('yii2stest:multi:b', '2')
                ->exec();

            $this->assertIsArray($result);
            $this->assertCount(2, $result);
            $this->assertSame('1', $this->redis->get('yii2stest:multi:a'));
            $this->assertSame('2', $this->redis->get('yii2stest:multi:b'));
        });
    }

    public function testDiscardRollsBack(): void
    {
        $this->runInCoroutine(function (): void {
            $this->redis->multi()
                ->set('yii2stest:multi:a', 'should-not-persist')
                ->discard();

            $this->assertFalse($this->redis->get('yii2stest:multi:a'));
        });
    }

    public function testPipelineBatchesCommands(): void
    {
        $this->runInCoroutine(function (): void {
            $result = $this->redis->pipeline()
                ->set('yii2stest:multi:pipe', 'piped')
                ->get('yii2stest:multi:pipe')
                ->exec();

            $this->assertIsArray($result);
            $this->assertSame('piped', $this->redis->get('yii2stest:multi:pipe'));
        });
    }
}
