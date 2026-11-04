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
            // Type round-trips the official CacheTest scope covers - the
            // serializer must survive every one of them.
            $cases = [
                'int' => 42,
                'float' => 3.14159,
                'string' => 'hello 世界',
                'bool-false' => false,
                'null' => null,
                'array' => ['a' => 1, 'nested' => ['deep' => true]],
                'object' => (object) ['prop' => 'v'],
            ];
            foreach ($cases as $key => $value) {
                Yii::$app->cache->set("type-{$key}", $value);
                // Objects deserialize into new instances - equality, not
                // identity, is the official CacheTest semantic for them.
                if (is_object($value)) {
                    $this->assertEquals($value, Yii::$app->cache->get("type-{$key}"), "type round-trip failed: {$key}");
                } else {
                    $this->assertSame($value, Yii::$app->cache->get("type-{$key}"), "type round-trip failed: {$key}");
                }
            }

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

    public function testAddWithDurationSetsExpiration(): void
    {
        $this->runInCoroutine(function (): void {
            Yii::$app->cache->delete('test-add-exp');
            $this->assertTrue(Yii::$app->cache->add('test-add-exp', 'value', 60));
            $this->assertSame('value', Yii::$app->cache->get('test-add-exp'));

            $ttl = $this->redis->ttl(Yii::$app->cache->buildKey('test-add-exp'));
            $this->assertGreaterThan(0, $ttl, 'add() with a duration must set a TTL');
            $this->assertLessThanOrEqual(60, $ttl);

            Yii::$app->cache->delete('test-add-exp');
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

    public function testGetOrSetSingleFlightUnderConcurrency(): void
    {
        $this->runInCoroutine(function (): void {
            // A unique key avoids a warm-up delete(), which would hold a pooled
            // connection in this (root) coroutine and leave only 19 for the 20
            // workers - tripping the pool's max on a false positive.
            $key = 'single-flight-' . uniqid();

            $counter = 0;
            $done = new \Swoole\Coroutine\Channel(20);

            for ($i = 0; $i < 20; $i++) {
                \Swoole\Coroutine::create(function () use ($done, $key, &$counter): void {
                    $value = Yii::$app->cache->getOrSet($key, function () use (&$counter): string {
                        $counter++;
                        \Swoole\Coroutine::sleep(0.1); // simulate a slow callback

                        return 'computed';
                    }, 30);
                    $done->push($value);
                });
            }

            for ($i = 0; $i < 20; $i++) {
                $this->assertSame('computed', $done->pop(5));
            }

            $this->assertSame(1, $counter, 'the callback must run only once under a concurrent miss');
        });
    }
}
