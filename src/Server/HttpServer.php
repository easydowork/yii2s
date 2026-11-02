<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
use Swoole\ExitException;
use Swoole\Process;
use Yii;
use Yii2S\Swoole\Runtime\RequestStateManager;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\di\Instance;

class HttpServer extends Component
{
    public const EVENT_BEFORE_START = 'beforeStart';

    public const EVENT_AFTER_START = 'afterStart';

    public const EVENT_AFTER_STOP = 'afterStop';

    public string $host = '127.0.0.1';

    public int $port = 9501;

    /** @var array<string, mixed> coroutine server settings: backlog, open_tcp_nodelay, max_coroutine, log_level */
    public array $settings = [];

    /** @var RequestDispatcherInterface|array|string */
    public $dispatcher;

    /** @var int coroutine hook flags applied when the server starts */
    public int $hookFlags = SWOOLE_HOOK_ALL;

    /** @var bool whether SIGINT/SIGTERM trigger a graceful shutdown */
    public bool $enableSignalHandling = true;

    /** @var string|null pid file path; null resolves to the system temp directory */
    public $pidFile = null;

    /** @var string|null stop flag file path; null resolves next to the pid file */
    public $stopFlagFile = null;

    /** @var bool whether to poll the stop flag file - a stop mechanism that
     *  works from any process (e.g. `swoole/stop` from a separate console)
     *  without depending on signal permissions */
    public bool $enableStopFlagWatcher = true;

    /** @var bool development-only: watch application files and gracefully
     *  reload the server when they change. NEVER enable in production -
     *  the reload respawns the whole process. */
    public bool $watch = false;

    /** @var float seconds between watch scans (mtime polling, zero deps) */
    public float $watchInterval = 0.5;

    /** @var array<string> extra directories to watch (Yii aliases); defaults
     *  to @app when empty */
    public array $watchDirs = [];

    /** @var array<string> directory names skipped by the watch scan */
    public array $watchExcludes = ['runtime', 'vendor', 'node_modules', '.git'];

    /** @var bool set when the *watchdog* (not an external `swoole/reload`)
     *  requested the current shutdown; consumed by `swoole/start --watch` */
    public bool $watchReloadPending = false;

    /** @var string|null static file root directory (supports Yii aliases);
     *  when set, Swoole serves existing files directly and missing paths
     *  fall through to the Yii2 router */
    public $documentRoot = null;

    /** @var float seconds to wait for in-flight requests to finish during a
     *  graceful stop before shutting down regardless */
    public float $gracefulTimeout = 10.0;

    /** @var callable|null optional factory: function (string $host, int $port): SwooleCoroutineHttpServer */
    public $serverFactory = null;

    private ?SwooleCoroutineHttpServer $server = null;

    private bool $isRunning = false;

    private ?PidFile $pidFileManager = null;

    private ?PidFile $stopFlagFileManager = null;

    /** @var string|null the action ('stop'|'reload') that triggered the shutdown */
    private ?string $lastStopAction = null;

    /** @var bool whether stop() has been called; lets the watcher coroutine
     *  exit so that Coroutine\run() is not kept alive by it */
    private bool $stopRequested = false;

    public function init(): void
    {
        parent::init();

        if (empty($this->dispatcher)) {
            // Sensible default for web applications: the standard config
            // location next to the entry script. Console-only applications
            // without that file keep getting the explicit error.
            $webConfig = Yii::getAlias('@app/config/web.php');
            if (is_file($webConfig)) {
                $this->dispatcher = new RequestDispatcher($webConfig);
            } else {
                throw new InvalidConfigException(
                    'Property "dispatcher" must be set (no default app config found at @app/config/web.php).'
                );
            }
        }
        $this->dispatcher = Instance::ensure($this->dispatcher, RequestDispatcherInterface::class);

        $this->pidFile = $this->resolveAlias($this->pidFile);
        $this->documentRoot = $this->resolveAlias($this->documentRoot);
        $this->stopFlagFile = $this->resolveAlias($this->stopFlagFile);

        // The coroutine Http\Server ignores Swoole's native static handler,
        // so static files are served at the PHP level via the dispatcher.
        if ($this->documentRoot !== null
            && $this->dispatcher instanceof RequestDispatcher
            && $this->dispatcher->staticRoot === null
        ) {
            $this->dispatcher->staticRoot = $this->documentRoot;
        }

        if ($this->serverFactory === null) {
            $this->serverFactory = static function (string $host, int $port): SwooleCoroutineHttpServer {
                return new SwooleCoroutineHttpServer($host, $port, false, true);
            };
        }
    }

    /**
     * Resolves a Yii path alias, passing through non-string and relative values.
     */
    private function resolveAlias(mixed $path): mixed
    {
        if (is_string($path) && str_starts_with($path, '@')) {
            return Yii::getAlias($path);
        }

        return $path;
    }

    /**
     * Resolves and creates the file managers. Called lazily so that console
     * commands can reuse exactly the same paths as the server process.
     */
    public function getPidFile(): PidFile
    {
        if ($this->pidFileManager === null) {
            $path = $this->pidFile
                ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf('yii2s-swoole-%s-%d.pid', $this->host, $this->port);
            $this->pidFileManager = new PidFile($path);
        }

        return $this->pidFileManager;
    }

    public function getStopFlagFile(): PidFile
    {
        if ($this->stopFlagFileManager === null) {
            $path = $this->stopFlagFile ?? $this->getPidFile()->getPath() . '.stop';
            $this->stopFlagFileManager = new PidFile($path);
        }

        return $this->stopFlagFileManager;
    }

    /**
     * Requests a graceful shutdown of a running server by creating the stop
     * flag file, which the server watches for. Used by the `swoole/stop` and
     * `swoole/reload` console commands; works cross-platform.
     *
     * @param string $action 'stop' or 'reload'
     */
    public function requestStop(string $action = 'stop'): void
    {
        $this->getStopFlagFile()->write(['action' => $action, 'requested_at' => time()]);
    }

    /**
     * The action that triggered the last shutdown ('stop', 'reload' or null).
     */
    public function getLastStopAction(): ?string
    {
        return $this->lastStopAction;
    }

    public function start(): void
    {
        if ($this->isRunning) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_START);

        Coroutine::set([
            'hook_flags' => $this->hookFlags,
            'max_coroutine' => $this->settings['max_coroutine'] ?? 100000,
        ]);

        $this->lastStopAction = null;
        $this->stopRequested = false;
        $this->writePidFile();
        $this->isRunning = true;

        try {
            if (\Swoole\Coroutine::getCid() !== -1) {
                // Already inside a coroutine (embedding/tests): run in place.
                $this->runServer();
            } else {
                Coroutine\run(function (): void {
                    // The server must own a dedicated coroutine: shutdown()
                    // called from another coroutine (stop flag watcher) does
                    // not unblock start() when it runs on the root coroutine.
                    Coroutine::create(function (): void {
                        $this->runServer();
                    });
                });
            }
        } catch (ExitException $e) {
            // Expected during graceful shutdown.
        }

        $this->isRunning = false;
        $this->server = null;
        $this->getPidFile()->remove();
        $this->getStopFlagFile()->remove();
        $this->trigger(self::EVENT_AFTER_STOP);
    }

    /**
     * Creates the Swoole server, registers the request handler and blocks
     * until shutdown. Must be called inside a coroutine.
     */
    private function runServer(): void
    {
        $this->server = ($this->serverFactory)($this->host, $this->port);
        $this->applySettings();

        $this->trigger(self::EVENT_AFTER_START);

        $dispatcher = $this->dispatcher;
        $server = $this->server;

        if ($this->enableSignalHandling) {
            $this->registerSignalHandlers($server);
        }
        if ($this->enableStopFlagWatcher) {
            $this->startStopFlagWatcher();
        }
        if ($this->watch) {
            $this->startWatchdog();
        }

        $server->handle('/', static function (
            \Swoole\Http\Request $request,
            \Swoole\Http\Response $response
        ) use ($dispatcher, $server): void {
            $dispatcher->dispatch($request, $response, $server);
        });

        $server->start();
    }

    public function stop(): void
    {
        if (!$this->isRunning || $this->server === null) {
            return;
        }
        $this->stopRequested = true;

        // Give in-flight requests a bounded amount of time to finish before
        // the server stops accepting and processing. Skipping the wait when
        // not inside a coroutine (signal callbacks on some platforms).
        if (Coroutine::getCid() !== -1) {
            $deadline = microtime(true) + $this->gracefulTimeout;
            while (RequestStateManager::getActiveRequests() > 0 && microtime(true) < $deadline) {
                Coroutine::sleep(0.05);
            }
        }

        $this->server->shutdown();
    }

    /**
     * Whether the server is currently running.
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Applies the configured Swoole settings. The coroutine Http\Server
     * accepts only a subset of the Swoole settings (e.g. backlog,
     * open_tcp_nodelay, package_max_length for large request bodies);
     * unknown keys are ignored by Swoole with a warning. Notably
     * `enable_static_handler` has no effect here - static files are served
     * by the dispatcher at the PHP level (see [[documentRoot]]).
     */
    private function applySettings(): void
    {
        // Unlike the stock Swoole\Http\Server, Coroutine\Http\Server defaults
        // `http_parse_files` to *false* (swoole_http.h) - multipart uploads
        // would silently produce an empty $request->files. Also, whether the
        // other parsers default on varies across builds. Pin them explicitly;
        // user-configured keys win over these defaults.
        $this->settings += [
            'http_parse_cookie' => true,
            'http_parse_post' => true,
            'http_parse_files' => true,
        ];

        $this->server->set($this->settings);
    }

    private function registerSignalHandlers(SwooleCoroutineHttpServer $server): void
    {
        try {
            foreach ([SIGINT, SIGTERM] as $signal) {
                Process::signal($signal, function () use ($server): void {
                    $server->shutdown();
                });
            }
        } catch (\Throwable $e) {
            // Signal handling may be unavailable (pcntl missing); the server
            // still works, it just cannot shut down gracefully. The stop flag
            // watcher provides the graceful path in that case.
        }
    }

    /**
     * Development-only hot reload: polls watched directories for PHP file
     * changes and triggers a graceful shutdown marked as a watchdog reload.
     * `swoole/start --watch` consumes [[watchReloadPending]] afterwards and
     * respawns itself, so the new process picks up the fresh code.
     *
     * Plain mtime polling (no inotify/fswatch dependency) works identically
     * on Linux and macOS; the stat() calls are coroutine-hooked (HOOK_FILE),
     * so scanning never blocks the event loop.
     */
    private function startWatchdog(): void
    {
        Coroutine::create(function (): void {
            $snapshot = $this->scanWatchFiles();
            while ($this->isRunning && !$this->stopRequested) {
                Coroutine::sleep($this->watchInterval);
                $current = $this->scanWatchFiles();
                if ($current !== $snapshot) {
                    $this->stdout(sprintf(
                        "[%s] file change detected, reloading...\n",
                        date('H:i:s')
                    ));
                    $this->watchReloadPending = true;
                    $this->stop();
                    return;
                }
                $snapshot = $current;
            }
        });
    }

    /**
     * @return array<string, int> path => mtime snapshot of watched PHP files
     */
    private function scanWatchFiles(): array
    {
        $dirs = $this->watchDirs;
        if ($dirs === []) {
            $appAlias = Yii::getAlias('@app', false);
            if (!is_string($appAlias)) {
                return [];
            }
            $dirs = [$appAlias];
        }

        $snapshot = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            // The scan runs concurrently with active requests and dev tools
            // writing files; every filesystem access may hit a file that
            // vanished (or a warning that Yii's error handler escalates into
            // an ErrorException mid-request). Never let it fail.
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                /** @var \SplFileInfo $file */
                foreach ($iterator as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $path = $file->getPathname();
                    $excluded = false;
                    foreach ($this->watchExcludes as $name) {
                        if (str_contains($path, DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR)) {
                            $excluded = true;
                            break;
                        }
                    }
                    if (!$excluded) {
                        $snapshot[$path] = (int) @filemtime($path);
                    }
                }
            } catch (\Throwable) {
                continue; // unreadable directory: skip it this round
            }
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function stdout(string $message): void
    {
        fwrite(STDOUT, $message);
    }

    /**
     * Watches for the stop flag file - the cross-process shutdown mechanism
     * used by `swoole/stop` and `swoole/reload` from a separate console
     * process. The flag carries the requested action ('stop' or 'reload').
     *
     * Implemented as a coroutine polling loop: shutdown() must not be called
     * from a timer callback (it blocks).
     */
    private function startStopFlagWatcher(): void
    {
        Coroutine::create(function (): void {
            // The loop must terminate *before* Coroutine\run() waits for it:
            // isRunning only flips false after run() returns, so the loop
            // exits on stopRequested instead to avoid a circular wait.
            while (!$this->stopRequested && $this->isRunning) {
                Coroutine::sleep(0.5);

                $flagFile = $this->getStopFlagFile();
                $data = $flagFile->read();
                if ($data === null) {
                    continue;
                }

                $flagFile->remove();
                $this->lastStopAction = is_string($data['action'] ?? null) ? $data['action'] : 'stop';
                $this->stop();
            }
        });
    }

    private function writePidFile(): void
    {
        $this->getPidFile()->write([
            'pid' => (int) getmypid(),
            'host' => $this->host,
            'port' => $this->port,
            'command' => $_SERVER['argv'] ?? [],
            'started_at' => time(),
        ]);
    }
}
