<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Yii;
use Yii2S\Swoole\Server\HttpServer;
use Yii2S\Swoole\Server\RequestDispatcher;
use yii2stest\DatabaseTestCase;

/**
 * Boots a real Swoole HTTP server around tests/data/app-compat.php and speaks
 * HTTP to it, keeping a browser-like cookie jar so multi-request flows
 * (session, login, CSRF, flash) can be verified end to end.
 *
 * Every helper must run inside a coroutine - the tests wrap their body in
 * [[TestCase::runInCoroutine()]].
 */
abstract class CompatTestCase extends DatabaseTestCase
{
    protected $driverName = 'mysql';

    /** @var array<string, string> cookie jar: name => raw value */
    protected array $cookies = [];

    private ?HttpServer $server = null;

    protected int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Applies the shared fixture (the `customer` table) the DB cases use.
        $this->getConnection();
        $this->cookies = [];
    }

    protected function tearDown(): void
    {
        $this->server = null;
        $this->cookies = [];
        parent::tearDown();
    }

    /**
     * Starts the server inside the current coroutine.
     */
    protected function startServer(string $config = 'app-compat.php'): void
    {
        $this->startServerWithDispatcher(new RequestDispatcher(__DIR__ . '/../data/' . $config));
    }

    /**
     * Starts the server with a prepared dispatcher (e.g. with raw routes).
     */
    protected function startServerWithDispatcher(RequestDispatcher $dispatcher): void
    {
        $this->port = random_int(31000, 39999);
        $this->server = Yii::createObject([
            'class' => HttpServer::class,
            'host' => '127.0.0.1',
            'port' => $this->port,
            'enableSignalHandling' => false,
            'dispatcher' => $dispatcher,
        ]);

        $server = $this->server;
        Coroutine::create(static function () use ($server): void {
            try {
                $server->start();
            } catch (\Throwable) {
                // Server internals may throw while shutting down.
            }
        });

        $this->waitForServer();
    }

    protected function stopServer(): void
    {
        $server = $this->server;
        if ($server === null) {
            return;
        }

        $server->stop();
        $this->waitFor(static fn (): bool => !$server->isRunning());
        $this->server = null;
    }

    /**
     * Performs one HTTP request through the Swoole coroutine client.
     *
     * @param array{headers?: array<string, string>, body?: mixed, cookies?: string|null} $options
     * @return array{status: int, headers: array<string, mixed>, body: string, json: mixed}
     */
    protected function http(string $method, string $path, array $options = []): array
    {
        $client = new Client('127.0.0.1', $this->port);
        $headers = $options['headers'] ?? [];

        $cookieHeader = array_key_exists('cookies', $options) ? $options['cookies'] : $this->cookieHeader();
        if (is_string($cookieHeader) && $cookieHeader !== '') {
            $headers['Cookie'] = $cookieHeader;
        }
        if ($headers !== []) {
            $client->setHeaders($headers);
        }

        $method = strtoupper($method);
        $body = $options['body'] ?? null;
        if ($method === 'GET') {
            $client->get($path);
        } elseif ($method === 'POST') {
            $client->post($path, $body ?? '');
        } else {
            $client->setMethod($method);
            if (is_string($body) && $body !== '') {
                $client->setData($body);
            }
            $client->execute($path);
        }

        $rawHeaders = is_array($client->headers) ? $client->headers : [];
        $result = [
            'status' => (int) $client->statusCode,
            'headers' => $rawHeaders,
            'body' => (string) $client->body,
            'json' => json_decode((string) $client->body, true),
        ];
        $this->absorbCookies($rawHeaders['set-cookie'] ?? []);
        $client->close();

        return $result;
    }

    /**
     * Posts a multipart form with files through the same cookie jar.
     *
     * @param array<int, array{field: string, path: string, name: string, type?: string}> $files
     * @param array<string, mixed> $fields
     * @return array{status: int, headers: array<string, mixed>, body: string, json: mixed}
     */
    protected function upload(string $path, array $files, array $fields = []): array
    {
        $client = new Client('127.0.0.1', $this->port);
        $headers = [];
        if ($this->cookieHeader() !== '') {
            $headers['Cookie'] = $this->cookieHeader();
        }
        if ($headers !== []) {
            $client->setHeaders($headers);
        }
        foreach ($files as $file) {
            $client->addFile($file['path'], $file['field'], $file['type'] ?? 'application/octet-stream', $file['name']);
        }
        $client->post($path, $fields);

        $rawHeaders = is_array($client->headers) ? $client->headers : [];
        $result = [
            'status' => (int) $client->statusCode,
            'headers' => $rawHeaders,
            'body' => (string) $client->body,
            'json' => json_decode((string) $client->body, true),
        ];
        $this->absorbCookies($rawHeaders['set-cookie'] ?? []);
        $client->close();

        return $result;
    }

    protected function cookieHeader(): string
    {
        return $this->cookieHeaderFrom($this->cookies);
    }

    /**
     * @param array<string, string> $cookies
     */
    protected function cookieHeaderFrom(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode('; ', $parts);
    }

    /**
     * @param mixed $setCookie one Set-Cookie header or a list of them
     */
    private function absorbCookies(mixed $setCookie): void
    {
        if ($setCookie === null || $setCookie === []) {
            return;
        }
        if (!is_array($setCookie)) {
            $setCookie = [$setCookie];
        }

        foreach ($setCookie as $header) {
            $parts = explode(';', (string) $header);
            $pair = (string) array_shift($parts);
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $name = trim($name);

            $expired = false;
            foreach ($parts as $attribute) {
                $attribute = trim($attribute);
                if (stripos($attribute, 'max-age=') === 0 && (int) substr($attribute, 8) <= 0) {
                    $expired = true;
                } elseif (stripos($attribute, 'expires=') === 0) {
                    $timestamp = strtotime(substr($attribute, 8));
                    if ($timestamp !== false && $timestamp < time()) {
                        $expired = true;
                    }
                }
            }

            if ($expired || $value === '') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
    }

    /**
     * @param array<string, mixed> $headers
     */
    protected function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return '';
    }

    protected function waitForServer(int $timeoutSeconds = 15): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $client = new Client('127.0.0.1', $this->port);
            $up = $client->get('/compat/index');
            $client->close();
            if ($up) {
                return;
            }
            Coroutine::sleep(0.05);
        }

        $this->fail("Compat server did not come up on port {$this->port}.");
    }

    protected function waitFor(callable $condition, float $timeoutSeconds = 10.0): void
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
