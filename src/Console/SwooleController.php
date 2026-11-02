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

    /**
     * Optional host/port overrides for the target server. Applied to the
     * server component before resolving its pid file, so stop/status/reload
     * can address a server that was started with non-default options.
     */
    public ?string $host = null;

    public ?int $port = null;

    private ?HttpServer $server = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['serverComponent', 'host', 'port']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'serverComponent',
            'H' => 'host',
            'p' => 'port',
        ]);
    }

    /**
     * Starts the Swoole HTTP server (blocks until stopped with Ctrl+C, a stop
     * signal or the stop flag file, e.g. created by `swoole/stop`).
     */
    public function actionStart(): int
    {
        $server = $this->resolveServer();
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->host !== null) {
            $server->host = $this->host;
        }

        if ($this->port !== null) {
            if ($this->port < 1 || $this->port > 65535) {
                $this->stderr("Invalid port specified.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $server->port = $this->port;
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
    public function actionStop(): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $pidFile = $this->resolveRunningPidFile($server);
        if ($pidFile === null) {
            $this->stdout("Server is not running.\n");
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
    public function actionReload(): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $pidFile = $this->resolveRunningPidFile($server);
        if ($pidFile === null) {
            $this->stdout("Server is not running.\n");
            return ExitCode::OK;
        }

        $info = $pidFile->read();
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
    public function actionStatus(): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $pidFile = $this->resolveRunningPidFile($server);
        if ($pidFile === null) {
            $this->stdout("Server is not running.\n");
            return ExitCode::OK;
        }

        $info = $pidFile->read();
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
    private function resolvePidFile(HttpServer $server): PidFile
    {
        if ($this->host !== null) {
            $server->host = $this->host;
        }
        if ($this->port !== null) {
            $server->port = $this->port;
        }

        return $server->getPidFile();
    }

    /**
     * Returns the pid file of a *live* server, or null when it is not
     * running. Stale entries (process gone) are cleaned up on the spot,
     * including the stop flag file - a leftover flag would make the next
     * start's flag watcher shut the server down immediately.
     */
    private function resolveRunningPidFile(HttpServer $server): ?PidFile
    {
        $pidFile = $this->resolvePidFile($server);
        if (!$pidFile->exists()) {
            return null;
        }
        if (!$pidFile->isAlive()) {
            $pidFile->remove();
            $server->getStopFlagFile()->remove();
            return null;
        }

        return $pidFile;
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
     * The stored command is the script invocation without the interpreter
     * (e.g. `example/yii swoole/start`), so PHP_BINARY is prepended - the
     * entry script has no shebang and would not execute on its own.
     *
     * @param array<int, string> $command
     */
    private function respawn(array $command): bool
    {
        $commandLine = escapeshellarg(PHP_BINARY) . ' ' . implode(' ', array_map(
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
