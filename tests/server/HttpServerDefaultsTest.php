<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Yii2S\Swoole\Server\HttpServer;
use Yii2S\Swoole\Server\RequestDispatcher;
use yii2stest\TestCase;

/**
 * The home for every default-server-settings assertion (the "opinionated
 * defaults" applied by HttpServer::applySettings()), plus the property
 * defaults of the component itself.
 *
 * @group server
 */
class HttpServerDefaultsTest extends TestCase
{
    private function makeServer(array $config = []): HttpServer
    {
        return new HttpServer(array_merge([
            'dispatcher' => new RequestDispatcher(__DIR__ . '/../data/app.php'),
        ], $config));
    }

    private function apply(HttpServer $server): void
    {
        $ref = new \ReflectionMethod($server, 'applySettings');
        $ref->setAccessible(true);
        $ref->invoke($server, new \Swoole\Http\Server('127.0.0.1', 0, SWOOLE_PROCESS));
    }

    private function runtimeDir(): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . sprintf('yii2s-swoole-%d', function_exists('posix_geteuid') ? posix_geteuid() : 0);
    }

    public function testDefaultsWriteLogFileWithDailyRotation(): void
    {
        $server = $this->makeServer();
        $this->runInCoroutine(function () use ($server): void {
            $this->apply($server);
        });

        $expected = $this->runtimeDir() . DIRECTORY_SEPARATOR . 'swoole-127.0.0.1-9501.log';
        $this->assertSame($expected, $server->settings['log_file'] ?? null);
        $this->assertSame(SWOOLE_LOG_ROTATION_DAILY, $server->settings['log_rotation'] ?? null);
        $this->assertDirectoryExists($this->runtimeDir());
        $this->assertArrayNotHasKey('log_level', $server->settings);
    }

    public function testExplicitLogFileAndLevelAreUsed(): void
    {
        $logFile = sys_get_temp_dir() . '/yii2s-log-test/explicit.log';
        $server = $this->makeServer([
            'logFile' => $logFile,
            'logLevel' => SWOOLE_LOG_WARNING,
        ]);
        $this->runInCoroutine(function () use ($server): void {
            $this->apply($server);
        });

        $this->assertSame($logFile, $server->settings['log_file'] ?? null);
        $this->assertSame(SWOOLE_LOG_ROTATION_DAILY, $server->settings['log_rotation'] ?? null);
        $this->assertSame(SWOOLE_LOG_WARNING, $server->settings['log_level'] ?? null);
        $this->assertDirectoryExists(dirname($logFile));
    }

    public function testFalseKeepsStdoutOnly(): void
    {
        $server = $this->makeServer(['logFile' => false]);
        $this->runInCoroutine(function () use ($server): void {
            $this->apply($server);
        });

        $this->assertArrayNotHasKey('log_file', $server->settings);
        $this->assertArrayNotHasKey('log_rotation', $server->settings);
    }

    public function testRequestBodyAndBacklogDefaults(): void
    {
        $server = $this->makeServer();
        $this->runInCoroutine(function () use ($server): void {
            $this->apply($server);
        });

        // Swoole already behaves this way (2 MB / 413 verified); the defaults
        // pin the limits so they are discoverable and pair with nginx.
        $this->assertSame(2 * 1024 * 1024, $server->settings['package_max_length'] ?? null);
        $this->assertSame(2048, $server->settings['backlog'] ?? null);
    }

    public function testLogMicrosecondsAndDisplayErrorsDefaults(): void
    {
        $server = $this->makeServer();
        $this->runInCoroutine(function () use ($server): void {
            $this->apply($server);
        });

        // Official key spelling is log_date_with_microseconds (not "microtime").
        $this->assertSame(true, $server->settings['log_date_with_microseconds'] ?? null);
        $this->assertSame(false, $server->settings['display_errors'] ?? null);
    }

    public function testHookFlagsPropertyDefaultsToAll(): void
    {
        $this->assertSame(SWOOLE_HOOK_ALL, $this->makeServer()->hookFlags);
    }

    public function testHookFlagsNullIsAllowedForSelfManagedHooks(): void
    {
        $this->assertNull($this->makeServer(['hookFlags' => null])->hookFlags);
    }

    public function testUserSettingsWin(): void
    {
        $server = $this->makeServer([
            'settings' => [
                'log_file' => '/tmp/yii2s-user-override.log',
                'package_max_length' => 8 * 1024 * 1024,
                'backlog' => 512,
            ],
        ]);
        $this->runInCoroutine(function () use ($server): void {
            $this->apply($server);
        });

        $this->assertSame('/tmp/yii2s-user-override.log', $server->settings['log_file'] ?? null);
        $this->assertSame(8 * 1024 * 1024, $server->settings['package_max_length'] ?? null);
        $this->assertSame(512, $server->settings['backlog'] ?? null);
        // rotation is still defaulted when the user only overrides log_file
        $this->assertSame(SWOOLE_LOG_ROTATION_DAILY, $server->settings['log_rotation'] ?? null);
    }
}
