<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\cache;

use Yii2S\Swoole\Cache\CoroutineRedisCache;
use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use Yii;
use yii2stest\TestCase;

/**
 * @group cache
 * @group redis
 */
class CoroutineRedisCacheTest extends TestCase
{
    private CoroutineRedisConnection $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new CoroutineRedisConnection(self::getParam('redis'));
        $this->mockWebApplication([
            'components' => [
                'redis' => $this->redis,
                'cache' => [
                    'class' => CoroutineRedisCache::class,
                    'redis' => $this->redis,
                ],
            ],
        ]);
    }

    public function testSetGet(): void
    {
        $this->runInCoroutine(function (): void {
            Yii::$app->cache->set('test-key', ['a' => 1, 'b' => 'two']);
            $this->assertSame(['a' => 1, 'b' => 'two'], Yii::$app->cache->get('test-key'));
            Yii::$app->cache->delete('test-key');
            $this->assertFalse(Yii::$app->cache->get('test-key'));
        });
    }

    public function testAddDoesNotOverwrite(): void
    {
        $this->runInCoroutine(function (): void {
            Yii::$app->cache->delete('test-add');
            $this->assertTrue(Yii::$app->cache->add('test-add', 'first'));
            $this->assertFalse(Yii::$app->cache->add('test-add', 'second'));
            $this->assertSame('first', Yii::$app->cache->get('test-add'));
            Yii::$app->cache->delete('test-add');
        });
    }

    public function testExpiration(): void
    {
        $this->runInCoroutine(function (): void {
            Yii::$app->cache->set('test-exp', 'gone', 1);
            $this->assertSame('gone', Yii::$app->cache->get('test-exp'));
            $ttl = $this->redis->ttl(Yii::$app->cache->buildKey('test-exp'));
            $this->assertGreaterThan(0, $ttl);
            $this->assertLessThanOrEqual(1, $ttl);
            Yii::$app->cache->delete('test-exp');
        });
    }

    public function testArrayAccess(): void
    {
        $this->runInCoroutine(function (): void {
            $cache = Yii::$app->cache;
            $cache['arr-key'] = 'value';
            $this->assertTrue($cache->offsetExists('arr-key'));
            $this->assertSame('value', $cache->offsetGet('arr-key'));
            $cache->offsetUnset('arr-key');
            $this->assertFalse($cache->offsetExists('arr-key'));
        });
    }
}
