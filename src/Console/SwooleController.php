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
use Yii2S\Swoole\Server\RequestDispatcherInterface;
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

    /** @var bool development-only: watch application files and auto-reload
     *  the server when they change (`swoole/start --watch`) */
    public bool $watch = false;

    private ?HttpServer $server = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['serverComponent', 'host', 'port', 'watch']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'serverComponent',
            'H' => 'host',
            'p' => 'port',
            'w' => 'watch',
        ]);
    }

    /**
     * Starts the Swoole HTTP server (blocks until stopped with Ctrl+C or a
     * SIGTERM, e.g. sent by `swoole/stop`).
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
            "Swoole HTTP server listening on %s:%d (pid: %d)%s\n",
            $server->host,
            $server->port,
            getmypid(),
            $this->watch ? ' [watch mode]' : ''
        ));

        if ($this->watch) {
            $server->watch = true;
        }

        try {
            $server->start();
        } catch (\Swoole\ExitException $e) {
            // Expected during graceful shutdown.
        }

        $this->stdout("Swoole HTTP server stopped.\n");

        return ExitCode::OK;
    }

    /**
     * Stops a running Swoole HTTP server by sending SIGTERM to its master
     * process (Swoole then gracefully drains the workers).
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

        if (!$this->signal($pidFile, SIGTERM)) {
            $this->stderr("Failed to signal the server; is it still running?\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->waitForExit($pidFile)) {
            $this->stderr("Server did not stop within the timeout.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Swoole HTTP server stopped.\n");
        return ExitCode::OK;
    }

    /**
     * Reloads a running server by sending SIGUSR1 - Swoole's native graceful
     * worker reload (no process respawn needed).
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

        if (!$this->signal($pidFile, SIGUSR1)) {
            $this->stderr("Failed to signal the server; is it still running?\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Swoole HTTP server reload signal sent.\n");
        return ExitCode::OK;
    }

    /**
     * Validates the application configuration the Swoole server will use:
     * server wiring, runtime paths, and the web app config (read statically
     * from the dispatcher's config file - `swoole/check` runs in the console
     * application, whose components are not the web ones). Component
     * reachability is probed through temporary connections built from the
     * config definitions, outside any coroutine (detached mode).
     */
    public function actionCheck(): int
    {
        $server = $this->resolveServer(false);
        $failures = 0;
        $report = static function (bool $ok, string $message) use (&$failures): void {
            if (!$ok) {
                $failures++;
            }
            printf("  [%s] %s\n", $ok ? ' OK ' : 'FAIL', $message);
        };

        printf("Swoole configuration check (%s):\n", $this->serverComponent);

        // --- server component wiring ---
        if ($server === null) {
            $report(false, "server component '{$this->serverComponent}' is not defined");
            printf("\n%d failure(s).\n", $failures);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $report(true, sprintf('server component "%s" resolved (%s:%d)', $this->serverComponent, $server->host, $server->port));

        // --- runtime paths writable ---
        $pidDir = dirname($server->getPidFile()->getPath());
        $report(is_dir($pidDir) && is_writable($pidDir), sprintf('pid file directory writable: %s', $pidDir));

        // --- dispatcher app config ---
        $appConfig = $this->getDispatcher($server)?->getAppConfig();
        if (!is_string($appConfig) || !is_file($appConfig)) {
            $report(
                false,
                $appConfig !== null
                    ? sprintf('app config file missing: %s', $appConfig)
                    : 'dispatcher is not a file-based RequestDispatcher; skipping web config checks'
            );
            printf("\n%d failure(s).\n", $failures);

            return $failures === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }
        $report(true, sprintf('app config file: %s', $appConfig));

        $webConfig = require $appConfig;
        if (!is_array($webConfig)) {
            $report(false, 'app config file does not return an array');
            printf("\n%d failure(s).\n", $failures);

            return ExitCode::UNSPECIFIED_ERROR;
        }
        $components = $webConfig['components'] ?? [];

        // --- application class: per-coroutine request-scoped components ---
        $appClass = is_string($webConfig['class'] ?? null) ? $webConfig['class'] : \yii\web\Application::class;
        $isolated = $appClass === \yii\web\Application::class
            || is_a($appClass, \Yii2S\Swoole\Runtime\CoroutineApplication::class, true);
        $report(
            $isolated,
            $isolated
                ? sprintf('application class "%s" isolates request-scoped components per coroutine', $appClass)
                : sprintf(
                    'application class "%s" must extend %s, otherwise request/response/session/user/view/errorHandler are shared between concurrent requests',
                    $appClass,
                    \Yii2S\Swoole\Runtime\CoroutineApplication::class
                )
        );

        // --- web request requirements ---
        $requestDef = $components['request'] ?? null;
        $key = is_array($requestDef) ? ($requestDef['cookieValidationKey'] ?? '') : null;
        $report(
            is_string($key) && $key !== '',
            $key === null
                ? 'no request component configured'
                : 'request.cookieValidationKey is configured'
        );

        // --- pretty URL requirement ---
        $urlDef = $components['urlManager'] ?? null;
        $pretty = is_array($urlDef) ? ($urlDef['enablePrettyUrl'] ?? false) : false;
        $report(
            (bool) $pretty,
            $urlDef === null
                ? 'no urlManager component configured (pretty routing must be on)'
                : 'urlManager.enablePrettyUrl = true (required: ?r= routing relies on $_GET)'
        );

        // --- db reachability through a temporary connection ---
        $this->checkDbReachable($components['db'] ?? null, $report);

        // --- redis reachability through a temporary connection ---
        $this->checkRedisReachable($components['redis'] ?? null, $report);

        printf("\n%s\n", $failures === 0 ? 'All checks passed.' : sprintf("%d failure(s).\n", $failures));

        return $failures === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * @param mixed $dbDef
     * @param callable(bool, string): void $report
     */
    private function checkDbReachable($dbDef, callable $report): void
    {
        if ($dbDef === null) {
            printf("  [SKIP] no db component configured\n");

            return;
        }

        try {
            /** @var \yii\db\Connection $db */
            $db = Yii::createObject($dbDef);
            $value = $db->createCommand('SELECT 1')->queryScalar();
            $report($value !== false, 'db reachable (SELECT 1)');
            $db->close();
        } catch (\Throwable $e) {
            $report(false, 'db unreachable: ' . substr($e->getMessage(), 0, 90));
        }
    }

    /**
     * @param mixed $redisDef
     * @param callable(bool, string): void $report
     */
    private function checkRedisReachable($redisDef, callable $report): void
    {
        if ($redisDef === null) {
            printf("  [SKIP] no redis component configured\n");

            return;
        }

        try {
            /** @var \Yii2S\Swoole\Redis\CoroutineRedisConnection $redis */
            $redis = Yii::createObject($redisDef);
            $pong = $redis->executeCommand('PING');
            // phpredis returns true for PING; other clients return the
            // literal '+PONG'/'PONG' string.
            $report(
                $pong === true || strcasecmp((string) $pong, 'PONG') === 0,
                'redis reachable (PING)'
            );
            $redis->close();
        } catch (\Throwable $e) {
            $report(false, 'redis unreachable: ' . substr($e->getMessage(), 0, 90));
        }
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
        $pid = (int) ($info['pid'] ?? 0);
        $host = (string) ($info['host'] ?? '127.0.0.1');
        $port = (string) ($info['port'] ?? '0');
        $startedAt = (int) ($info['started_at'] ?? 0);

        $this->stdout(sprintf(
            "Server is running: pid %d, listening on %s:%s, started %s (%s ago)\n",
            $pid,
            $host,
            $port,
            $startedAt > 0 ? date('Y-m-d H:i:s', $startedAt) : 'unknown',
            $startedAt > 0 ? $this->formatDuration(time() - $startedAt) : '?'
        ));

        // Memory + worker count come from the process tree (cross-process and
        // always available, unlike the HTTP endpoints).
        $tree = (new ProcessInspector())->processTreeInfo($pid);
        if ($tree !== null) {
            $this->stdout(sprintf(
                "  workers: %d, memory: %.1f MB (process tree RSS)\n",
                $tree['workers'],
                $tree['rssMb']
            ));
        }

        // Runtime metrics + connections come from the /metrics and /__pool
        // endpoints; these reflect a single worker's snapshot (each worker has
        // its own counters/pool), not the aggregate across all workers.
        $renderer = new MetricsSnapshotRenderer();
        foreach ($renderer->renderMetrics($renderer->fetch("http://{$host}:{$port}/metrics")) as $line) {
            $this->stdout($line . "\n");
        }
        foreach ($renderer->renderPools($renderer->fetch("http://{$host}:{$port}/__pool")) as $line) {
            $this->stdout($line . "\n");
        }

        return ExitCode::OK;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm' . ($seconds % 60) . 's';
        }
        return floor($seconds / 3600) . 'h' . floor(($seconds % 3600) / 60) . 'm';
    }

    /**
     * Lists the routes served by this server: the raw routes registered on the
     * dispatcher and the urlManager rules from the application config.
     */
    public function actionRoutes(): int
    {
        $server = $this->resolveServer(false);
        if ($server === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $dispatcher = $this->getDispatcher($server);
        if ($dispatcher === null) {
            $this->stdout("Dispatcher is not introspectable.\n");
            return ExitCode::OK;
        }

        $rawRoutes = $dispatcher->getRawRoutes();
        if ($rawRoutes !== []) {
            $this->stdout("Raw routes:\n");
            foreach ($rawRoutes as $key => $_handler) {
                $this->stdout("  {$key}\n");
            }
        }

        $configFile = $dispatcher->getAppConfig();
        if (is_string($configFile) && is_file($configFile)) {
            $config = require $configFile;
            $rules = is_array($config) ? ($config['components']['urlManager']['rules'] ?? []) : [];
            if (is_array($rules) && $rules !== []) {
                $this->stdout("\nurlManager rules:\n");
                foreach ($rules as $pattern => $route) {
                    $this->stdout(sprintf("  %-40s => %s\n", (string) $pattern, (string) $route));
                }
            }
        }

        return ExitCode::OK;
    }

    /**
     * Returns the dispatcher of the resolved server, or null when it is not a
     * [[RequestDispatcherInterface]] implementation.
     */
    private function getDispatcher(HttpServer $server): ?RequestDispatcherInterface
    {
        $dispatcher = $server->dispatcher;

        return $dispatcher instanceof RequestDispatcherInterface ? $dispatcher : null;
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
            return null;
        }

        return $pidFile;
    }

    /**
     * Sends a signal to the master process recorded in the pid file.
     */
    private function signal(PidFile $pidFile, int $signal): bool
    {
        $info = $pidFile->read();
        $pid = (int) ($info['pid'] ?? 0);
        if ($pid <= 0) {
            return false;
        }

        return @posix_kill($pid, $signal);
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

}
