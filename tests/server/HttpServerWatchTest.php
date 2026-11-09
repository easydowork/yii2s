<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use yii2stest\ServerTestCase;

/**
 * Development watchdog: watches directories for PHP file changes and sends
 * SIGUSR1 to the master, which gracefully reloads the workers (fresh code,
 * new worker pid) without dropping the server.
 *
 * @group server
 * @group integration
 */
class HttpServerWatchTest extends ServerTestCase
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

    public function testFileChangeTriggersWorkerReload(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-form.php', [
                // /pid reports the worker pid so a reload is observable.
                'rawRoutes' => [['method' => 'GET', 'path' => '/pid', 'type' => 'pid']],
                'server' => [
                    'watch' => true,
                    'watchInterval' => 0.2,
                    'watchDirs' => [$this->watchDir],
                ],
            ]);
            try {
                $pid1 = (int) $this->get('/pid')['body'];
                $this->assertGreaterThan(0, $pid1);

                // Excluded-directory change must NOT trigger a reload.
                file_put_contents($this->watchDir . '/runtime/noise.php', '<?php return 2;');
                \Swoole\Coroutine::sleep(1.5);
                $this->assertSame($pid1, (int) $this->get('/pid')['body'], 'excluded-directory changes must be ignored');

                // A real change must reload the worker (new pid, server up).
                file_put_contents($this->watchDir . '/changed.php', '<?php return 3;');
                $this->waitFor(function () use ($pid1): bool {
                    return (int) $this->get('/pid')['body'] !== $pid1;
                }, 8.0);

                // The watcher stays up, so a later edit reloads again.
                $pid2 = (int) $this->get('/pid')['body'];
                file_put_contents($this->watchDir . '/changed-again.php', '<?php return 4;');
                $this->waitFor(function () use ($pid2): bool {
                    return (int) $this->get('/pid')['body'] !== $pid2;
                }, 8.0);
            } finally {
                $this->stopServer();
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
}
