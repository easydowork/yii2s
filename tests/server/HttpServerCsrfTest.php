<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Swoole\Coroutine\Http\Client;
use yii2stest\ServerTestCase;

/**
 * @group server
 * @group integration
 */
class HttpServerCsrfTest extends ServerTestCase
{
    public function testCsrfValidationAcceptsValidTokenAndRejectsMissing(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-csrf.php');
            try {
                // 1. Fetch a token; the server sets the _csrf cookie.
                $client = new Client('127.0.0.1', $this->port);
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
                $client = new Client('127.0.0.1', $this->port);
                $this->assertTrue($client->post('/site/echo', ['x' => '1']));
                $this->assertSame(400, $client->statusCode, 'POST without CSRF token must be rejected');
                $client->close();

                // 3. POST with the token and cookie: accepted.
                $client = new Client('127.0.0.1', $this->port);
                foreach ($cookies as $cookie) {
                    $client->setHeaders(['Cookie' => trim(explode(';', (string) $cookie)[0])]);
                }
                $this->assertTrue($client->post('/site/echo', [$paramName => $token, 'data' => 'hello']));
                $this->assertSame(200, $client->statusCode, 'POST with a valid CSRF token must pass');
                $postBody = json_decode((string) $client->body, true);
                $this->assertSame('hello', $postBody['post']['data'] ?? null);
                $client->close();
            } finally {
                $this->stopServer();
            }
        });
    }
}
