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
class PoolMetricsEndpointTest extends TestCase
{
    public function testMetricsEndpointServesJsonWhenEnabled(): void
    {
        $this->runInCoroutine(function (): void {
            $port = random_int(26000, 30000);
            $dispatcher = new RequestDispatcher(__DIR__ . '/../data/app-pool-metrics.php');
            $dispatcher->enablePoolMetricsEndpoint = true;

            $server = Yii::createObject([
                'class' => HttpServer::class,
                'host' => '127.0.0.1',
                'port' => $port,
                'enableSignalHandling' => false,
                'dispatcher' => $dispatcher,
            ]);

            Coroutine::create(function () use ($server): void {
                try {
                    $server->start();
                } catch (\Throwable) {
                }
            });

            try {
                $this->waitForServer($port);

                $response = $this->get($port, '/__pool');
                $this->assertSame(200, $response['status']);
                $this->assertSame('application/json', $response['contentType']);

                $body = json_decode($response['body'], true);
                $this->assertIsArray($body);
                $this->assertArrayHasKey('pools', $body);
                $this->assertArrayHasKey('redis', $body['pools']);

                $redisMetrics = $body['pools']['redis'];
                $this->assertSame(5, $redisMetrics['max_active']);
                $this->assertSame(0, $redisMetrics['total_timeouts']);
                $this->assertFalse($redisMetrics['closed']);
            } finally {
                $server->stop();
                $this->waitFor(fn (): bool => !$server->isRunning());
            }

            $this->assertFalse($server->isRunning());
        });
    }

    public function testMetricsEndpointIsDisabledByDefault(): void
    {
        $this->runInCoroutine(function (): void {
            $port = random_int(26000, 30000);
            $server = Yii::createObject([
                'class' => HttpServer::class,
                'host' => '127.0.0.1',
                'port' => $port,
                'enableSignalHandling' => false,
                'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app-pool-metrics.php'),
            ]);

            Coroutine::create(function () use ($server): void {
                try {
                    $server->start();
                } catch (\Throwable) {
                }
            });

            try {
                $this->waitForServer($port);

                // Without the flag the path falls through to the Yii router.
                $response = $this->get($port, '/__pool');
                $this->assertSame(404, $response['status']);
            } finally {
                $server->stop();
                $this->waitFor(fn (): bool => !$server->isRunning());
            }

            $this->assertFalse($server->isRunning());
        });
    }

    private function get(int $port, string $path): array
    {
        $client = new Client('127.0.0.1', $port);
        $this->assertTrue($client->get($path), "request to {$path} must succeed");

        $result = [
            'status' => $client->statusCode,
            'body' => (string) $client->body,
            'contentType' => (string) ($client->headers['content-type'] ?? ''),
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
