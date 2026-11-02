<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Console;

use Yii;
use Yii2S\Swoole\Server\HttpServer;
use Yii2S\Swoole\Server\PidFile;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\di\Instance;

/**
 * Console entry point for the Swoole HTTP server.
 */
class SwooleController extends Controller
{
    public string $serverComponent = 'swooleHttpServer';

    private ?HttpServer $server = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['serverComponent']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'serverComponent',
        ]);
    }

    /**
     * Starts the Swoole HTTP server (blocks until stopped with Ctrl+C, a stop
     * signal or the stop flag file, e.g. created by `swoole/stop`).
     */
    public function actionStart(?string $host = null, ?string $port = null): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($host !== null) {
            $server->host = $host;
        }

        if ($port !== null) {
            if (!is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
                $this->stderr("Invalid port specified.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $server->port = (int) $port;
        }

        // Guard against starting a second server on the same pid file (which
        // would race over the port and the pid file itself).
        $pidFile = $server->getPidFile();
        if ($pidFile->exists() && $pidFile->isAlive()) {
            $this->stderr(sprintf(
                "A server is already running on %s:%d (pid %s, pid file %s).\n" .
                "Stop it first with: php yii swoole/stop\n",
                $server->host,
                $server->port,
                (string) ($pidFile->read()['pid'] ?? '?'),
                $pidFile->getPath()
            ));
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf(
            "Swoole HTTP server listening on %s:%d (pid: %d)\n",
            $server->host,
            $server->port,
            getmypid()
        ));

        try {
            $server->start();
        } catch (\Swoole\ExitException $e) {
            // Expected during graceful shutdown.
        }

        $this->stdout("Swoole HTTP server stopped.\n");

        return ExitCode::OK;
    }

    /**
     * Stops a running Swoole HTTP server through its stop flag file.
     */
    public function actionStop(?string $host = null, ?int $port = null): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $pidFile = $this->resolvePidFile($server, $host, $port);
        if (!$pidFile->exists()) {
            $this->stdout("Server is not running (pid file not found).\n");
            return ExitCode::OK;
        }
        if (!$pidFile->isAlive()) {
            $this->stdout("Stale pid file found (process is gone); cleaning up.\n");
            $pidFile->remove();
            $server->getStopFlagFile()->remove();
            return ExitCode::OK;
        }

        $server->requestStop('stop');

        if (!$this->waitForExit($pidFile)) {
            $this->stderr("Server did not stop within the timeout.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Swoole HTTP server stopped.\n");
        return ExitCode::OK;
    }

    /**
     * Reloads a running server: graceful stop plus respawn using the original
     * command line stored in the pid file. On Linux, prefer a process manager
     * (supervisor/systemd) which restarts the server on SIGTERM exit.
     */
    public function actionReload(?string $host = null, ?int $port = null): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $pidFile = $this->resolvePidFile($server, $host, $port);
        $info = $pidFile->read();
        if (!$pidFile->exists() || $info === null) {
            $this->stdout("Server is not running (pid file not found).\n");
            return ExitCode::OK;
        }
        if (!$pidFile->isAlive()) {
            $this->stdout("Stale pid file found (process is gone); cleaning up.\n");
            $pidFile->remove();
            return ExitCode::OK;
        }

        $command = is_array($info['command'] ?? null) ? $info['command'] : [];
        if ($command === []) {
            $this->stderr("Pid file does not contain the original command line; cannot respawn.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $server->requestStop('reload');
        if (!$this->waitForExit($pidFile)) {
            $this->stderr("Server did not stop within the timeout.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->respawn($command)) {
            $this->stdout("Swoole HTTP server reloaded.\n");
        } else {
            $this->stdout("Server stopped. The process could not be respawned\n");
            $this->stdout("from PHP (exec/popen are restricted on this system).\n");
            $this->stdout("Start it again with: " . implode(' ', $command) . "\n");
        }
        return ExitCode::OK;
    }

    /**
     * Shows the state of a running Swoole HTTP server.
     */
    public function actionStatus(?string $host = null, ?int $port = null): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $pidFile = $this->resolvePidFile($server, $host, $port);
        $info = $pidFile->read();
        if (!$pidFile->exists() || $info === null) {
            $this->stdout("Server is not running.\n");
            return ExitCode::OK;
        }

        if (!$pidFile->isAlive()) {
            $this->stdout("Stale pid file found (process is gone).\n");
            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "Server is running: pid %s, listening on %s:%s, started %s\n",
            (string) ($info['pid'] ?? '?'),
            (string) ($info['host'] ?? '?'),
            (string) ($info['port'] ?? '0'),
            isset($info['started_at']) ? date('Y-m-d H:i:s', (int) $info['started_at']) : 'unknown'
        ));
        return ExitCode::OK;
    }

    protected function resolveServer(bool $ensure = true): ?HttpServer
    {
        if ($this->server !== null) {
            return $this->server;
        }

        if (!Yii::$app->has($this->serverComponent)) {
            if ($ensure) {
                $this->stderr(sprintf("Unable to locate server component '%s'.\n", $this->serverComponent));
            }
            return null;
        }

        return $this->server = Instance::ensure(
            Yii::$app->get($this->serverComponent),
            HttpServer::class
        );
    }

    /**
     * Pid file of the target server; console options override the component
     * configuration, mirroring how `swoole/start` applies its options.
     */
    private function resolvePidFile(HttpServer $server, ?string $host, ?int $port): PidFile
    {
        if ($host !== null) {
            $server->host = $host;
        }
        if ($port !== null) {
            $server->port = $port;
        }

        return $server->getPidFile();
    }

    /**
     * Polls the liveness of the server process recorded in the pid file.
     */
    private function waitForExit(PidFile $pidFile, int $timeoutSeconds = 15): bool
    {
        $deadline = time() + $timeoutSeconds;
        while (time() < $deadline) {
            if (!$pidFile->exists() || !$pidFile->isAlive()) {
                return true;
            }
            sleep(1);
        }

        return false;
    }

    /**
     * Respawns the server detached from the current process using the original
     * command line. Returns false when process spawning is unavailable (some
     * systems restrict exec/popen/proc_open from PHP); the caller then tells
     * the user to start the server manually.
     *
     * @param array<int, string> $command
     */
    private function respawn(array $command): bool
    {
        $commandLine = implode(' ', array_map(
            static fn (string $part): string => escapeshellarg($part),
            $command
        ));

        set_error_handler(static fn (): bool => true);
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $process = @popen('start /B "" ' . $commandLine, 'r');
                if (is_resource($process)) {
                    pclose($process);
                    return true;
                }
                return false;
            }

            // cygwin/msys2 builds report PHP_OS_FAMILY as 'Unknown'/'Linux'
            // but exec() may fail to fork; detect it via the exit code.
            exec($commandLine . ' > /dev/null 2>&1 &', $output, $exitCode);

            return $exitCode === 0;
        } finally {
            restore_error_handler();
        }
    }
}
