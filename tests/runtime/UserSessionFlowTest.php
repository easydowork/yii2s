<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\runtime;

use Yii;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\web\IdentityInterface;
use yii\web\User;
use Yii2S\Swoole\Session\CoroutineSession;
use yii2stest\TestCase;

/**
 * Login/restore flow over the coroutine session: the path every real
 * application takes (login() -> session persistence -> identity restore on
 * the next request -> logout).
 *
 * @group runtime
 * @group redis
 */
class UserSessionFlowTest extends TestCase
{
    public function testLoginPersistsIdentityAcrossRequestRebuilds(): void
    {
        $this->runInCoroutine(function (): void {
            // "Request 1": login writes the identity id into the session.
            $sessionId = 'sess-user-flow-' . bin2hex(random_bytes(8));

            $app1 = $this->buildWebApp($sessionId);
            $user1 = $app1->get('user');
            $this->assertTrue($user1->getIsGuest(), 'no identity before login');

            $user1->login(UserFlowIdentity::findIdentity(2));
            $this->assertSame(2, $user1->getId());
            $this->assertFalse($user1->getIsGuest());

            // login() regenerates the session id (session-fixation defence,
            // yii\web\User semantics); the client receives the new id via
            // Set-Cookie and sends it on the next request.
            $sessionIdAfterLogin = Yii::$app->session->getId();
            $this->assertNotSame($sessionId, $sessionIdAfterLogin, 'login must regenerate the session id');
            Yii::$app->session->close();

            // "Request 2": a rebuilt application + session with the cookie id
            // (what RequestStateManager produces per request) must restore
            // the identity from the Redis-backed session.
            $app2 = $this->buildWebApp($sessionIdAfterLogin);
            $user2 = $app2->get('user');
            $this->assertFalse($user2->getIsGuest(), 'identity must survive across request rebuilds');
            $this->assertSame(2, $user2->getId());
            $this->assertSame('bob', $user2->identity->name);

            // Logout clears it; a third request sees a guest again.
            $user2->logout();
            Yii::$app->session->close();

            $app3 = $this->buildWebApp($sessionId);
            $user3 = $app3->get('user');
            $this->assertTrue($user3->getIsGuest(), 'logout must persist across request rebuilds');
            $this->assertNull($user3->getId());
        });
    }

    /**
     * Builds a fresh web application with a coroutine session carrying the
     * given session id - simulating one request of a resident server.
     */
    private function buildWebApp(string $sessionId): \yii\web\Application
    {
        $dbConfig = $this->getParam('databases')['mysql'];
        unset($dbConfig['fixture']);
        $db = Yii::createObject(array_merge([
            'class' => \Yii2S\Swoole\Db\CoroutineDbConnection::class,
        ], $dbConfig));

        $redisConfig = $this->getParam('redis');
        unset($redisConfig['fixture']);

        $this->mockWebApplication([
            'components' => [
                'db' => $db,
                'redis' => array_merge([
                    'class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class,
                ], $redisConfig),
                'session' => [
                    'class' => CoroutineSession::class,
                    'redis' => 'redis',
                    'timeout' => 60,
                ],
                'user' => [
                    'class' => User::class,
                    'identityClass' => UserFlowIdentity::class,
                    'enableSession' => true,
                    'enableAutoLogin' => false,
                ],
            ],
        ]);

        $session = Yii::$app->session;
        $session->setId($sessionId);
        $session->open();

        return Yii::$app;
    }
}

/**
 * Identity backed by the customer fixture table.
 *
 * @property int $id
 * @property string $name
 */
class UserFlowIdentity extends ActiveRecord implements IdentityInterface
{
    public static function tableName(): string
    {
        return '{{%customer}}';
    }

    public static function findIdentity($id): ?self
    {
        return static::findOne($id);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?self
    {
        return null;
    }

    public function getId(): ?int
    {
        return (int) $this->getAttribute('id');
    }

    public function getAuthKey(): ?string
    {
        return null;
    }

    public function validateAuthKey($authKey): bool
    {
        return false;
    }
}
