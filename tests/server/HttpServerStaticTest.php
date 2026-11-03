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
class HttpServerStaticTest extends TestCase
{
    private string $docRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docRoot = sys_get_temp_dir() . '/yii2stest-docroot-' . bin2hex(random_bytes(4));
        mkdir($this->docRoot . '/css', 0777, true);
        file_put_contents($this->docRoot . '/css/app.css', 'body { color: red; }');
    }

    protected function tearDown(): void
    {
        @unlink($this->docRoot . '/css/app.css');
        @rmdir($this->docRoot . '/css');
        @rmdir($this->docRoot);
        parent::tearDown();
    }

    public function testStaticFilesAreServedAndMissingPathsFallThrough(): void
    {
        $this->runInCoroutine(function (): void {
            $server = Yii::createObject([
                'class' => HttpServer::class,
                'host' => '127.0.0.1',
                'port' => 23490,
                'documentRoot' => $this->docRoot,
                'enableSignalHandling' => false,
                'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app.php'),
            ]);

            // The dispatcher must have picked up the document root.
            $dispatcher = $server->dispatcher;
            $this->assertInstanceOf(RequestDispatcher::class, $dispatcher);
            $this->assertSame($this->docRoot, $dispatcher->staticRoot);

            Coroutine::create(function () use ($server): void {
                try {
                    $server->start();
                } catch (\Throwable) {
                }
            });

            try {
                $this->waitForServer(23490);

                $css = $this->request(23490, '/css/app.css');
                $this->assertSame(200, $css['status']);
                $this->assertSame('body { color: red; }', $css['body']);
                $this->assertSame('text/css; charset=utf-8', $css['contentType']);

                // Missing paths fall through to the Yii2 router (404).
                $missing = $this->request(23490, '/site/missing');
                $this->assertSame(404, $missing['status']);

                // Yii routes keep working alongside static files.
                $index = $this->request(23490, '/');
                $this->assertSame(200, $index['status']);
                $indexBody = json_decode($index['body'], true);
                $this->assertSame('yii2stest', $indexBody['app'] ?? null);
            } finally {
                $server->stop();
                $this->waitFor(fn (): bool => !$server->isRunning());
            }

            $this->assertFalse($server->isRunning());
        });
    }

    public function testExecutableAndHiddenFilesAreNotServed(): void
    {
        file_put_contents($this->docRoot . '/secret.php', '<?php echo "leak";');
        file_put_contents($this->docRoot . '/.env', "SECRET=1\n");

        try {
            $this->runInCoroutine(function (): void {
                $port = random_int(26000, 30000);
                $server = Yii::createObject([
                    'class' => HttpServer::class,
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'documentRoot' => $this->docRoot,
                    'enableSignalHandling' => false,
                    'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app.php'),
                ]);

                Coroutine::create(function () use ($server): void {
                    try {
                        $server->start();
                    } catch (\Throwable) {
                    }
                });

                try {
                    $this->waitForServer($port);

                    $php = $this->request($port, '/secret.php');
                    $this->assertSame(404, $php['status'], 'PHP source must never be served as a static file');

                    $env = $this->request($port, '/.env');
                    $this->assertSame(404, $env['status'], 'hidden files must never be served as a static file');

                    // An encoded null byte must be rejected *after* decoding,
                    // before it reaches realpath() (which throws a ValueError
                    // on null bytes - an uncaught exception in the request
                    // coroutine takes the worker down).
                    $nullByte = $this->request($port, '/css/app.css%00.png');
                    $this->assertSame(404, $nullByte['status'], 'encoded null bytes must not reach the filesystem');
                } finally {
                    $server->stop();
                    $this->waitFor(fn (): bool => !$server->isRunning());
                }
            });
        } finally {
            @unlink($this->docRoot . '/secret.php');
            @unlink($this->docRoot . '/.env');
        }
    }

    private function request(int $port, string $path): array
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
