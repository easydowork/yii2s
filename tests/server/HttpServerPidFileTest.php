<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Swoole\Coroutine;
use Yii;
use Yii2S\Swoole\Server\HttpServer;
use Yii2S\Swoole\Server\RequestDispatcher;
use yii2stest\TestCase;

/**
 * @group server
 * @group integration
 */
class HttpServerPidFileTest extends TestCase
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
        @unlink($this->pidFilePath . '.stop');
        parent::tearDown();
    }

    public function testPidFileLifecycleAndStopFlagShutdown(): void
    {
        $this->runInCoroutine(function (): void {
            $server = $this->createServer(23480, $this->pidFilePath);
            $pidFile = $server->getPidFile();
            $stopFlag = $server->getStopFlagFile();

            $this->assertFalse($pidFile->exists());

            Coroutine::create(function () use ($server): void {
                try {
                    $server->start();
                } catch (\Throwable) {
                }
            });

            $this->waitFor($pidFile->exists(...));
            $info = $pidFile->read();
            $this->assertIsArray($info);
            $this->assertSame((int) getmypid(), $info['pid']);
            $this->assertSame(23480, $info['port']);
            $this->assertSame('127.0.0.1', $info['host']);
            $this->assertNotEmpty($info['command']);

            // The stop flag must trigger a graceful shutdown.
            $server->requestStop('reload');
            $this->assertTrue($stopFlag->exists());

            $this->waitFor(fn (): bool => !$server->isRunning());
            $this->assertFalse($server->isRunning());
            $this->assertSame('reload', $server->getLastStopAction());

            // Cleanup must have removed both files.
            $this->waitFor(fn (): bool => !$pidFile->exists());
            $this->assertFalse($pidFile->exists(), 'pid file must be removed on shutdown');
            $this->assertFalse($stopFlag->exists(), 'stop flag must be removed on shutdown');
        });
    }

    private function createServer(int $port, string $pidFile): HttpServer
    {
        return Yii::createObject([
            'class' => HttpServer::class,
            'host' => '127.0.0.1',
            'port' => $port,
            'pidFile' => $pidFile,
            'enableSignalHandling' => false,
            'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app.php'),
        ]);
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
