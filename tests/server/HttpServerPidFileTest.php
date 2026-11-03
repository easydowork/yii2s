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
 * @group server
 * @group integration
 */
class HttpServerPidFileTest extends ServerTestCase
{
    private string $pidFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pidFilePath = sys_get_temp_dir() . '/yii2stest-server-' . bin2hex(random_bytes(4)) . '.pid';
    }

    protected function tearDown(): void
    {
        @unlink($this->pidFilePath);
        parent::tearDown();
    }

    public function testPidFileLifecycle(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app.php', [
                'server' => ['pidFile' => $this->pidFilePath],
            ]);

            $this->assertFileExists($this->pidFilePath, 'the server must write its pid file on start');
            $info = json_decode((string) file_get_contents($this->pidFilePath), true);
            $this->assertIsArray($info);
            $this->assertGreaterThan(0, $info['pid'] ?? 0, 'pid file records the master process id');
            $this->assertSame($this->port, $info['port'] ?? null);
            $this->assertSame('127.0.0.1', $info['host'] ?? null);
            $this->assertNotEmpty($info['command'] ?? null);

            $this->stopServer();

            $this->assertFileDoesNotExist($this->pidFilePath, 'pid file must be removed on shutdown');
        });
    }
}
