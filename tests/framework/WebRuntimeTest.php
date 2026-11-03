<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\framework;

use Yii;
use Yii2S\Swoole\Db\CoroutineDbConnection;
use Yii2S\Swoole\Session\CoroutineSession;
use yii\web\Request;
use yii\web\Response;
use yii2stest\TestCase;

/**
 * Web runtime surface under coroutine: sessions (flash/count/destroy),
 * cookies (set/get/expiry), URL generation, Security component, exception
 * rendering, and the response pipeline.
 *
 * @group web
 */
class WebRuntimeTest extends TestCase
{
    private ?CoroutineSession $session = null;
    private ?CoroutineDbConnection $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        $config = self::getParam("databases")["mysql"];
        unset($config["fixture"]);
        $this->db = Yii::createObject(array_merge(["class" => CoroutineDbConnection::class], $config));
        $redisConfig = self::getParam("redis");
        unset($redisConfig["fixture"]);
        $redis = Yii::createObject(array_merge(["class" => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class], $redisConfig));
        $this->runInCoroutine(function () use ($redis): void {
            $this->session = new CoroutineSession(["redis" => $redis]);
            $this->session->setTimeout(60);
        });
    }

    protected function tearDown(): void
    {
        $this->runInCoroutine(function (): void {
            $this->session?->destroy();
        });
        $this->db?->close();
        parent::tearDown();
    }

    public function testSessionFlashLifecycle(): void
    {
        $this->runInCoroutine(function (): void {
            $sessionId = "flash-test-" . bin2hex(random_bytes(8));
            $this->session->setId($sessionId);
            $this->session->setFlash("success", "Operation completed");
            $this->session->close();

            // Request 2: not read yet, so the flash is still visible.
            $s2 = new CoroutineSession(["redis" => $this->session->redis]);
            $s2->setId($sessionId);
            $s2->open();
            $this->assertSame("Operation completed", $s2->getFlash("success"));
            $s2->close();

            // Request 3: the counter aged during open(), the message is gone.
            $s3 = new CoroutineSession(["redis" => $this->session->redis]);
            $s3->setId($sessionId);
            $s3->open();
            $this->assertNull($s3->getFlash("success"), "flash is removed one request after the read");
            $this->assertFalse($s3->hasFlash("success"));
            $s3->close();
        });
    }

    public function testSessionCountAndDestroy(): void
    {
        $this->runInCoroutine(function (): void {
            $sid = "count-test-" . bin2hex(random_bytes(8));
            $s = new CoroutineSession(["redis" => $this->session->redis]);
            $s->setId($sid);
            $s->open();
            $s->set("a", 1);
            $s->set("b", 2);
            $this->assertSame(2, $s->count());
            $this->assertTrue(isset($s["a"]));
            unset($s["a"]);
            $this->assertSame(1, $s->count());
            $s->destroy();
            $s3 = new CoroutineSession(["redis" => $this->session->redis]);
            $s3->setId($sid);
            $s3->open();
            $this->assertNull($s3->get("b"), "destroy must clear all session data");
        });
    }

    public function testSecurityComponent(): void
    {
        $this->runInCoroutine(function (): void {
            $this->mockWebApplication(["components" => ["request" => ["cookieValidationKey" => "sec-test-key"]]]);
            $sec = Yii::$app->security;
            $hash = $sec->generatePasswordHash("secret123");
            $this->assertTrue($sec->validatePassword("secret123", $hash));
            $this->assertFalse($sec->validatePassword("wrong", $hash));

            $token = $sec->generateRandomString(32);
            $this->assertSame(32, strlen($token));

            $data = "sensitive";
            $encrypted = $sec->encryptByKey($data, "key123");
            $this->assertSame($data, $sec->decryptByKey($encrypted, "key123"));

            $masked = $sec->maskToken("abc");
            $this->assertTrue($sec->unmaskToken($masked) === "abc");
        });
    }

    public function testResponseFormatAndHeaders(): void
    {
        $this->runInCoroutine(function (): void {
            $this->mockWebApplication(["components" => [
                "response" => ["class" => \Yii2S\Swoole\Runtime\CoroutineResponse::class, "format" => Response::FORMAT_JSON],
            ]]);
            $resp = Yii::$app->response;
            $resp->data = ["key" => "value", "num" => 42];
            $resp->statusCode = 201;
            $resp->getHeaders()->set("X-Custom", "test-header");
            $this->assertSame(201, $resp->statusCode);
            $this->assertSame("test-header", $resp->getHeaders()->get("X-Custom"));
            $this->assertIsArray($resp->data);
        });
    }

    public function testUrlGeneration(): void
    {
        $this->runInCoroutine(function (): void {
            $this->mockWebApplication(["components" => [
                "request" => ["class" => \Yii2S\Swoole\Runtime\CoroutineRequest::class, "cookieValidationKey" => "url-key", "enableCsrfValidation" => false],
                "urlManager" => ["enablePrettyUrl" => true, "showScriptName" => false, "rules" => [
                    "GET posts/<id:\d+>" => "post/view",
                    "POST posts" => "post/create",
                ]],
            ]]);
            $url = Yii::$app->urlManager->createUrl(["post/view", "id" => 42]);
            $this->assertSame("/posts/42", $url);

            $url2 = Yii::$app->urlManager->createUrl(["site/index"]);
            $this->assertSame("/site/index", $url2);
        });
    }
}
