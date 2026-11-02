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
 * Development watchdog: watches a directory for PHP file changes and
 * gracefully stops the server with watchReloadPending set (the console
 * layer then respawns itself - out of scope here).
 *
 * @group server
 * @group integration
 */
class HttpServerWatchTest extends TestCase
{
    private string $watchDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->watchDir = sys_get_temp_dir() . '/yii2stest-watch-' . bin2hex(random_bytes(4));
        mkdir($this->watchDir . '/runtime', 0777, true);
        file_put_contents($this->watchDir . '/seed.php', '<?php return 1;');
    }

    protected function tearDown(): void
    {
        self::removeDir($this->watchDir);
        parent::tearDown();
    }

    public function testFileChangeTriggersGracefulReloadFlag(): void
    {
        $this->runInCoroutine(function (): void {
            $port = random_int(26000, 30000);
            $server = Yii::createObject([
                'class' => HttpServer::class,
                'host' => '127.0.0.1',
                'port' => $port,
                'enableSignalHandling' => false,
                'watch' => true,
                'watchInterval' => 0.2,
                'watchDirs' => [$this->watchDir],
                'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app-form.php'),
            ]);

            Coroutine::create(function () use ($server): void {
                try {
                    $server->start();
                } catch (\Throwable) {
                }
            });

            try {
                $this->waitForServer($port);

                // Changing a file inside an *excluded* directory must NOT
                // trigger the watchdog.
                file_put_contents($this->watchDir . '/runtime/noise.php', '<?php return 2;');
                Coroutine::sleep(0.6);
                $this->assertTrue($server->isRunning(), 'excluded-directory changes must be ignored');

                // A real change (new file in a watched dir) must gracefully
                // stop the server and raise the reload flag.
                file_put_contents($this->watchDir . '/changed.php', '<?php return 3;');
                $deadline = microtime(true) + 5;
                while (microtime(true) < $deadline && $server->isRunning()) {
                    Coroutine::sleep(0.1);
                }

                $this->assertFalse($server->isRunning(), 'watchdog must stop the server on file change');
                $this->assertTrue($server->watchReloadPending, 'watchdog reload must be flagged for respawn');
            } finally {
                if ($server->isRunning()) {
                    $server->stop();
                }
                $this->waitFor(fn (): bool => !$server->isRunning());
            }
        });
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
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
