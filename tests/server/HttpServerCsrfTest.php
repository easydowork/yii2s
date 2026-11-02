<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Yii;
use Yii2S\Swoole\Server\HttpServer;
use Yii2S\Swoole\Server\RequestDispatcher;
use yii2stest\TestCase;

/**
 * @group server
 * @group integration
 */
class HttpServerCsrfTest extends TestCase
{
    public function testCsrfValidationAcceptsValidTokenAndRejectsMissing(): void
    {
        $this->runInCoroutine(function (): void {
            $port = random_int(26000, 30000);
            $server = Yii::createObject([
                'class' => HttpServer::class,
                'host' => '127.0.0.1',
                'port' => $port,
                'enableSignalHandling' => false,
                'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app-csrf.php'),
            ]);

            Coroutine::create(function () use ($server): void {
                try {
                    $server->start();
                } catch (\Throwable) {
                }
            });

            try {
                $this->waitForServer($port);

                // 1. Fetch a token; the server sets the _csrf cookie.
                $client = new Client('127.0.0.1', $port);
                $this->assertTrue($client->get('/site/csrf'));
                $this->assertSame(200, $client->statusCode);
                $body = json_decode((string) $client->body, true);
                $token = $body['token'] ?? null;
                $paramName = $body['name'] ?? '_csrf';
                $this->assertIsString($token);
                $this->assertNotSame('', $token);
                $cookies = $client->headers['set-cookie'] ?? [];
                if (!is_array($cookies)) {
                    $cookies = [$cookies];
                }
                $cookieStr = implode("\n", array_map(static fn ($c) => (string) $c, $cookies));
                $this->assertStringContainsStringIgnoringCase(
                    'samesite',
                    $cookieStr,
                    'the CSRF cookie must carry the SameSite attribute'
                );
                $client->close();

                // 2. POST without the token: rejected with 400 (BadRequestHttpException).
                $client = new Client('127.0.0.1', $port);
                $this->assertTrue($client->post('/site/echo', ['x' => '1']));
                $this->assertSame(400, $client->statusCode, 'POST without CSRF token must be rejected');
                $client->close();

                // 3. POST with the token and cookie: accepted.
                $client = new Client('127.0.0.1', $port);
                foreach ($cookies as $cookie) {
                    $client->setHeaders(['Cookie' => trim(explode(';', (string) $cookie)[0])]);
                }
                $this->assertTrue($client->post('/site/echo', [$paramName => $token, 'data' => 'hello']));
                $this->assertSame(200, $client->statusCode, 'POST with a valid CSRF token must pass');
                $postBody = json_decode((string) $client->body, true);
                $this->assertSame('hello', $postBody['post']['data'] ?? null);
                $client->close();
            } finally {
                $server->stop();
                $this->waitFor(fn (): bool => !$server->isRunning());
            }

            $this->assertFalse($server->isRunning());
        });
    }

    private function waitForServer(int $port, int $timeoutSeconds = 15): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $client = new Client('127.0.0.1', $port);
            $up = $client->get('/');
            $client->close();
            if ($up) {
                return;
            }
            Coroutine::sleep(0.2);
        }

        $this->fail("Integration server did not come up on port {$port}.");
    }

    private function waitFor(callable $condition, int $timeoutSeconds = 10): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            Coroutine::sleep(0.05);
        }

        $this->fail('Condition was not met within the timeout.');
    }
}
