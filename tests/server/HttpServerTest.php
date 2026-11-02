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
class HttpServerTest extends TestCase
{
    public function testServerServesRequestsAndStopsGracefully(): void
    {
        $this->runInCoroutine(function (): void {
            $port = random_int(20000, 25000);
            $server = $this->createServer($port);

            // The server blocks its own coroutine until stopped.
            Coroutine::create(function () use ($server): void {
                try {
                    $server->start();
                } catch (\Throwable) {
                    // Server internals may throw on shutdown.
                }
            });

            try {
                $this->waitForServer($port);

                $index = $this->request($port, '/');
                $this->assertSame(200, $index['status']);
                $this->assertSame('yii2stest', $index['body']['app'] ?? null);
                $this->assertGreaterThan(0, $index['body']['cid'] ?? 0);

                $echo = $this->request($port, '/site/echo?x=1&y=hello');
                $this->assertSame(200, $echo['status']);
                $this->assertSame(['x' => '1', 'y' => 'hello'], $echo['body']['get'] ?? null);
                $this->assertSame('GET', $echo['body']['method'] ?? null);

                $missing = $this->request($port, '/site/missing');
                $this->assertSame(404, $missing['status']);
            } finally {
                // The server coroutine must never outlive the test, otherwise
                // Coroutine\run() would hang waiting for it.
                $server->stop();
                $this->waitForShutdown($server);
            }

            $this->assertFalse($server->isRunning());
        });
    }

    /**
     * Waits until the server coroutine has fully returned.
     */
    private function waitForShutdown(HttpServer $server, int $timeoutSeconds = 10): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while ($server->isRunning() && microtime(true) < $deadline) {
            Coroutine::sleep(0.05);
        }
    }

    private function createServer(int $port): HttpServer
    {
        return Yii::createObject([
            'class' => HttpServer::class,
            'host' => '127.0.0.1',
            'port' => $port,
            'enableSignalHandling' => false,
            'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app.php'),
        ]);
    }

    /**
     * Performs a single HTTP request through the Swoole coroutine client.
     */
    private function request(int $port, string $path): array
    {
        $client = new Client('127.0.0.1', $port);
        $this->assertTrue($client->get($path), "request to {$path} must succeed");

        $result = [
            'status' => $client->statusCode,
            'body' => json_decode((string) $client->body, true),
        ];
        $client->close();

        return $result;
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
}
