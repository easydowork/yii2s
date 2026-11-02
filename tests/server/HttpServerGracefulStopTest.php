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
use Yii2S\Swoole\Runtime\RequestStateManager;
use Yii2S\Swoole\Server\HttpServer;
use Yii2S\Swoole\Server\RequestDispatcher;
use yii2stest\TestCase;

/**
 * @group server
 * @group integration
 */
class HttpServerGracefulStopTest extends TestCase
{
    public function testStopWaitsForInFlightRequests(): void
    {
        $this->runInCoroutine(function (): void {
            $port = random_int(26000, 30000);
            $server = Yii::createObject([
                'class' => HttpServer::class,
                'host' => '127.0.0.1',
                'port' => $port,
                'enableSignalHandling' => false,
                'gracefulTimeout' => 5.0,
                'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app-slow.php'),
            ]);

            Coroutine::create(function () use ($server): void {
                try {
                    $server->start();
                } catch (\Throwable) {
                }
            });

            try {
                $this->waitForServer($port);

                // Start a slow request (~1s) and call stop() while it is in
                // flight: the response must still be delivered and the
                // counter must drain before shutdown.
                $slowDone = false;
                Coroutine::create(function () use ($port, &$slowDone): void {
                    $client = new Client('127.0.0.1', $port);
                    $ok = $client->get('/slow/index');
                    $client->close();
                    $slowDone = $ok && $client->statusCode === 200;
                });

                Coroutine::sleep(0.3); // let the slow request start
                $this->assertFalse($slowDone, 'slow request should still be running');
                $this->assertGreaterThan(0, RequestStateManager::getActiveRequests());

                $server->stop();

                $this->assertTrue($slowDone, 'in-flight request must complete before shutdown');
                $this->assertSame(0, RequestStateManager::getActiveRequests());
            } finally {
                if ($server->isRunning()) {
                    $server->stop();
                }
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
