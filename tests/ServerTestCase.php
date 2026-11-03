<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest;

use Swoole\Coroutine\Http\Client;

/**
 * Base class for integration tests that boot a real HTTP server as a
 * subprocess and speak HTTP to it. Wraps the [[ServerProcessTrait]] helpers
 * with short, intention-revealing names.
 */
abstract class ServerTestCase extends TestCase
{
    use ServerProcessTrait;

    /**
     * Starts the server subprocess on a random port and waits until it accepts
     * requests. Must be called inside a coroutine (runInCoroutine).
     *
     * @param array<string, mixed> $options "dispatcher" / "rawRoutes" / "server" keys
     */
    protected function startServer(string $configFile, array $options = []): void
    {
        $this->startServerProcess($configFile, $options);
    }

    protected function stopServer(): void
    {
        $this->stopServerProcess();
    }

    /**
     * Performs one HTTP request against the spawned server.
     *
     * @return array{status: int, headers: array<string, mixed>, body: string, json: mixed}
     */
    protected function http(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $client = new Client('127.0.0.1', $this->port);
        if ($headers !== []) {
            $client->setHeaders($headers);
        }

        $method = strtoupper($method);
        if ($method === 'GET') {
            $client->get($path);
        } elseif ($method === 'POST') {
            $client->post($path, $body ?? '');
        } else {
            $client->setMethod($method);
            if ($body !== null && $body !== '') {
                $client->setData($body);
            }
            $client->execute($path);
        }

        $result = [
            'status' => (int) $client->statusCode,
            'headers' => is_array($client->headers) ? $client->headers : [],
            'body' => (string) $client->body,
            'json' => json_decode((string) $client->body, true),
        ];
        $client->close();

        return $result;
    }

    protected function get(string $path, array $headers = []): array
    {
        return $this->http('GET', $path, $headers);
    }

    /**
     * Polls a condition until it holds or the timeout elapses. Must be called
     * inside a coroutine.
     */
    protected function waitFor(callable $condition, float $timeoutSeconds = 10.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            \Swoole\Coroutine::sleep(0.05);
        }

        $this->fail('Condition was not met within the timeout.');
    }
}
