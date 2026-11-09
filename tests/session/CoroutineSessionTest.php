<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\session;

use Yii2S\Swoole\Session\CoroutineSession;
use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use Yii;
use yii2stest\TestCase;

/**
 * @group session
 * @group redis
 */
class CoroutineSessionTest extends TestCase
{
    private CoroutineRedisConnection $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new CoroutineRedisConnection(self::getParam('redis'));
        $this->mockWebApplication([
            'components' => [
                'request' => ['enableCookieValidation' => false],
                'redis' => $this->redis,
                'session' => [
                    'class' => CoroutineSession::class,
                    'redis' => $this->redis,
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->runInCoroutine(function (): void {
            $keys = $this->redis->keys('yii2s:session:*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del(...$keys);
            }
        });
        parent::tearDown();
    }

    public function testSetGetRemove(): void
    {
        $this->runInCoroutine(function (): void {
            $session = Yii::$app->session;
            $session->open();

            $this->assertFalse($session->has('visits'));
            $session->set('visits', 1);
            $this->assertTrue($session->has('visits'));
            $this->assertSame(1, $session->get('visits'));

            $session->set('visits', 2);
            $this->assertSame(2, $session->get('visits'));

            $removed = $session->remove('visits');
            $this->assertSame(2, $removed);
            $this->assertFalse($session->has('visits'));
        });
    }

    public function testDataPersistsAcrossSessions(): void
    {
        $this->runInCoroutine(function (): void {
            $session = Yii::$app->session;
            $session->open();
            $sessionId = $session->getId();
            $session->set('user', ['id' => 42, 'name' => 'alice']);
            $session->close();
            $this->destroyApplication();
            $this->mockWebApplication([
                'components' => [
                    'request' => ['enableCookieValidation' => false],
                    'redis' => $this->redis,
                    'session' => [
                        'class' => CoroutineSession::class,
                        'redis' => $this->redis,
                    ],
                ],
            ]);

            // Simulate the next request carrying the session cookie.
            $_COOKIE[Yii::$app->session->getName()] = $sessionId;

            $session = Yii::$app->session;
            $session->open();
            $this->assertSame($sessionId, $session->getId());
            $this->assertSame(['id' => 42, 'name' => 'alice'], $session->get('user'));
        });
    }

    public function testClientSuppliedUnknownSessionIdIsRejected(): void
    {
        $this->runInCoroutine(function (): void {
            // Strict mode (Yii2 default): an id for a non-existent session
            // must never be adopted - otherwise an attacker can fixate the
            // session id before the victim logs in.
            $_COOKIE[Yii::$app->session->getName()] = 'attackerchosenid';
            $session = Yii::$app->session;
            $session->open();

            $newId = $session->getId();
            $this->assertNotSame('attackerchosenid', $newId);

            $session->set('user', 1);
            $this->assertSame($newId, $session->getId(), 'the regenerated id must stay stable');
        });
    }

    public function testRegenerateId(): void
    {
        $this->runInCoroutine(function (): void {
            $session = Yii::$app->session;
            $session->open();
            $oldId = $session->getId();
            $session->set('keep', 'yes');
            $session->regenerateID();

            $this->assertNotSame($oldId, $session->getId());
            $this->assertSame('yes', $session->get('keep'));
        });
    }

    public function testCountAndIterator(): void
    {
        $this->runInCoroutine(function (): void {
            $session = Yii::$app->session;
            $session->open();
            $session->removeAll();
            $session->set('a', 1);
            $session->set('b', 2);

            $this->assertCount(2, $session);
            $data = iterator_to_array($session);
            $this->assertSame(['a' => 1, 'b' => 2], $data);

            $session->removeAll();
            $this->assertCount(0, $session);
        });
    }

    public function testConcurrentWritersDoNotOverwriteEachOther(): void
    {
        $this->runInCoroutine(function (): void {
            $id = 'sess-lock-' . bin2hex(random_bytes(4));
            $first = new CoroutineSession(['redis' => $this->redis]);
            $second = new CoroutineSession(['redis' => $this->redis]);
            $ready = new \Swoole\Coroutine\Channel(1);

            \Swoole\Coroutine::create(function () use ($first, $id, $ready): void {
                $first->setId($id);
                $first->open();
                $first->set('n', 1);
                $ready->push(true);
                \Swoole\Coroutine::sleep(0.15);
                $first->close();
            });
            $ready->pop(2);

            $started = microtime(true);
            $second->setId($id);
            $second->open();
            $this->assertGreaterThan(0.05, microtime(true) - $started);
            $this->assertSame(1, $second->get('n'));
            $second->set('extra', 'kept');
            $second->close();

            $check = new CoroutineSession(['redis' => $this->redis]);
            $check->setId($id);
            $check->open();
            $this->assertSame(1, $check->get('n'));
            $this->assertSame('kept', $check->get('extra'));
            $check->close();
        });
    }

    public function testForgedObjectInSessionIsNotInstantiated(): void
    {
        $this->runInCoroutine(function (): void {
            $id = 'sess-object-' . bin2hex(random_bytes(4));
            $this->redis->set(
                'yii2s:session:' . $id,
                serialize(['ok' => 'yes', 'bad' => new \stdClass()])
            );

            $session = Yii::$app->session;
            $session->setId($id);
            $session->open();

            $this->assertSame('yes', $session->get('ok'));
            $this->assertNotInstanceOf(\stdClass::class, $session->get('bad'));
        });
    }
}
