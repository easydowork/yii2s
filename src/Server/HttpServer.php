<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Process;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\di\Instance;

/**
 * HTTP server built on the classic multi-process [[Swoole\Http\Server]].
 *
 * One master process manages `workerNum` worker processes; each worker runs
 * its own coroutine scheduler and owns its own copy of the Yii2 application
 * and its connection pools (so `poolMaxActive` applies **per worker**). A
 * worker crash is recovered by the master, and graceful reload/shutdown are
 * provided natively by Swoole (SIGUSR1 / SIGTERM).
 *
 * The request lifecycle is otherwise identical to the coroutine server this
 * class used to wrap: every request is handled inside a coroutine, the
 * dispatcher rebuilds request-scoped state per request and pooled connections
 * stay sticky to their coroutine.
 */
class HttpServer extends Component
{
    public const EVENT_BEFORE_START = 'beforeStart';

    public const EVENT_AFTER_START = 'afterStart';

    public const EVENT_AFTER_STOP = 'afterStop';

    public string $host = '127.0.0.1';

    public int $port = 9501;

    /** @var array<string, mixed> Swoole server settings: worker_num, backlog,
     *  open_tcp_nodelay, max_wait_time, package_max_length, log_level, ... */
    public array $settings = [];

    /**
     * @var int number of worker processes; 0 lets Swoole default to the
     * number of CPU cores. Set explicitly to bound total connections
     * (each worker owns its own `poolMaxActive`-sized pools).
     */
    public int $workerNum = 0;

    /**
     * @var int how many connections to open per pool before each worker starts
     * accepting traffic (DB/Redis warm-up). 0 only instantiates the pools.
     * Keep it at or below the component's `poolMaxActive`.
     */
    public int $prewarmConnections = 0;

    /**
     * @var float seconds between connection-pool upkeep passes (max-lifetime
     * recycling, idle keepalive, min-idle replenishment). 0 disables the
     * periodic keeper; the pools then only recycle on borrow/return. Only
     * meaningful together with a component's `poolMinIdle` / `poolMaxLifetime`
     * / `poolIdleTimeout` settings.
     */
    public float $poolMaintenanceInterval = 0.0;

    /** @var RequestDispatcherInterface|array|string */
    public $dispatcher;

    /** @var bool kept for backward compatibility: [[Swoole\Http\Server]]
     *  always handles SIGTERM/SIGINT/SIGUSR1 natively. */
    public bool $enableSignalHandling = true;

    /** @var string|null pid file path; null resolves to a per-user directory
     *  inside the system temp directory (see [[getPidFile()]]) */
    public $pidFile = null;

    /** @var bool development-only: watch application files and gracefully
     *  reload the workers when they change. NEVER enable in production. */
    public bool $watch = false;

    /** @var float seconds between watch scans (mtime polling, zero deps) */
    public float $watchInterval = 0.5;

    /** @var array<string> extra directories to watch (Yii aliases); defaults
     *  to @app when empty */
    public array $watchDirs = [];

    /** @var array<string> directory names skipped by the watch scan */
    public array $watchExcludes = ['runtime', 'vendor', 'node_modules', '.git'];

    /** @var string|null static file root directory (supports Yii aliases);
     *  when set, static files are served at the PHP level and missing paths
     *  fall through to the Yii2 router */
    public $documentRoot = null;

    /** @var float seconds Swoole waits for workers to finish in-flight
     *  requests during a reload/shutdown (mapped to `max_wait_time`) */
    public float $gracefulTimeout = 10.0;

    private ?SwooleHttpServer $server = null;

    private bool $isRunning = false;

    private ?PidFile $pidFileManager = null;

    private ?Process $watchdog = null;

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

        // Swoole's native static handler is not used: static files are served
        // at the PHP level so cache headers and range support stay ours.
        if ($this->documentRoot !== null
            && $this->dispatcher instanceof RequestDispatcher
            && $this->dispatcher->staticRoot === null
        ) {
            $this->dispatcher->staticRoot = $this->documentRoot;
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
     * Resolves and creates the pid file manager. Called lazily so that console
     * commands can reuse exactly the same paths as the server process.
     */
    public function getPidFile(): PidFile
    {
        if ($this->pidFileManager === null) {
            // Default: a per-user directory inside the system temp dir. A
            // predictable file directly in /tmp could be pre-created (or
            // symlinked) by another local user; a directory we own prevents
            // both, without requiring a writable @app/runtime from console.
            $path = $this->pidFile
                ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR
                    . sprintf('yii2s-swoole-%d', $this->effectiveUid())
                    . DIRECTORY_SEPARATOR
                    . sprintf('%s-%d.pid', $this->host, $this->port);
            $this->pidFileManager = new PidFile($path);
        }

        return $this->pidFileManager;
    }

    /**
     * Effective user id for the default pid file directory; 0 on platforms
     * without posix.
     */
    private function effectiveUid(): int
    {
        return function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
    }

    /**
     * Starts the server. Blocks in the master process until the server shuts
     * down (SIGTERM/SIGINT, or a programmatic [[stop()]]). Must be called in
     * the main thread - never inside a coroutine.
     */
    public function start(): void
    {
        if ($this->isRunning) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_START);

        $this->isRunning = true;
        $this->writePidFile();

        // Explicit SWOOLE_PROCESS: a master + worker_num workers. The default
        // on some builds is SWOOLE_BASE (single process), which neither scales
        // across cores nor supports graceful reload().
        $server = new SwooleHttpServer($this->host, $this->port, SWOOLE_PROCESS);
        $this->server = $server;
        $this->applySettings($server);

        $dispatcher = $this->dispatcher;
        $prewarm = $this->prewarmConnections;
        $maintenanceInterval = $this->poolMaintenanceInterval;

        // Every request runs inside a coroutine (Swoole enables this by
        // default); the dispatcher therefore keeps working unchanged.
        $server->on('request', static function ($request, $response) use ($dispatcher, $server): void {
            $dispatcher->dispatch($request, $response, $server);
        });

        // Each worker owns its own application/pools, so warm them up per
        // worker, not in the master (channels cannot survive fork). The borrow
        // loop needs a coroutine; workerStart runs outside one. The warm-up is
        // best-effort and asynchronous: the worker serves requests immediately
        // and pays the connect latency only if the warm-up has not finished.
        $server->on('workerStart', static function () use ($dispatcher, $prewarm, $maintenanceInterval): void {
            if ($prewarm > 0) {
                \Swoole\Coroutine::create(static function () use ($dispatcher, $prewarm): void {
                    $dispatcher->prewarm($prewarm);
                });
            }

            if ($maintenanceInterval > 0) {
                // Periodic pool upkeep (max-lifetime recycling, idle keepalive,
                // min-idle replenishment). Runs forever alongside the worker;
                // the worker shuts it down on exit.
                \Swoole\Coroutine::create(static function () use ($dispatcher, $maintenanceInterval): void {
                    // @phpstan-ignore-next-line intentional infinite loop, runs until the worker exits
                    while (true) {
                        \Swoole\Coroutine::sleep($maintenanceInterval);
                        $dispatcher->maintainPools();
                    }
                });
            }
        });

        if ($this->watch) {
            $this->startWatchdog();
        }

        $this->trigger(self::EVENT_AFTER_START);

        try {
            $server->start();
        } finally {
            $this->isRunning = false;
            $this->server = null;
            if ($this->watchdog !== null) {
                // The watchdog may already have exited after sending SIGUSR1.
                @Process::kill($this->watchdog->pid, SIGTERM);
                $this->watchdog = null;
            }
            $this->getPidFile()->remove();
            $this->trigger(self::EVENT_AFTER_STOP);
        }
    }

    /**
     * Requests a graceful shutdown. Only meaningful when called in the master
     * process; external callers should send SIGTERM (see `swoole/stop`).
     */
    public function stop(): void
    {
        if (!$this->isRunning || $this->server === null) {
            return;
        }

        // Give in-flight requests a bounded amount of time to finish; Swoole's
        // shutdown already waits for workers, this just mirrors the intent of
        // gracefulTimeout for programmatic stops.
        $this->server->shutdown();
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * Applies the configured Swoole settings. Worker count and the graceful
     * wait are derived from the dedicated properties; user keys win.
     */
    private function applySettings(SwooleHttpServer $server): void
    {
        // The multipart parser defaults differ across Swoole builds; pin them
        // explicitly so uploads never silently produce an empty $request->files.
        $this->settings += [
            'http_parse_cookie' => true,
            'http_parse_post' => true,
            'http_parse_files' => true,
        ];

        // workerNum 0 means "one worker per CPU core". Swoole's own default
        // is 1 on some builds, so auto-detect explicitly to honour the
        // multi-process intent of this server.
        $this->settings += [
            'worker_num' => $this->workerNum > 0 ? $this->workerNum : max(1, swoole_cpu_num()),
        ];
        if ($this->gracefulTimeout > 0) {
            // reload_async + max_wait_time together make reload/shutdown wait
            // for in-flight requests: without reload_async a worker is killed
            // immediately on the stop signal, dropping requests mid-flight.
            $this->settings += [
                'reload_async' => true,
                'max_wait_time' => (int) $this->gracefulTimeout,
            ];
        }

        $server->set($this->settings);
    }

    /**
     * Development-only hot reload: a dedicated process polls watched
     * directories and sends SIGUSR1 (Swoole's native graceful reload) to the
     * master when a PHP file changes. Reload restarts the workers, which pick
     * up the fresh code on the next request.
     */
    private function startWatchdog(): void
    {
        $masterPid = getmypid();
        $watchDirs = $this->watchDirs;
        $watchExcludes = $this->watchExcludes;
        $interval = $this->watchInterval;

        $this->watchdog = new Process(
            static function () use ($masterPid, $watchDirs, $watchExcludes, $interval): void {
                $scan = static function () use ($watchDirs, $watchExcludes): array {
                    return HttpServer::scanWatchFiles($watchDirs, $watchExcludes);
                };

                $snapshot = $scan();
                $sleepMicros = (int) (max(0.1, $interval) * 1_000_000);
                while (true) {
                    usleep($sleepMicros);

                    if (!PidFile::isProcessAlive($masterPid)) {
                        return; // master exited; nothing left to reload
                    }

                    if ($scan() !== $snapshot) {
                        @posix_kill($masterPid, SIGUSR1);

                        return;
                    }

                    $snapshot = $scan();
                }
            },
            false,
            0,
            false
        );

        $this->watchdog->start();
    }

    /**
     * @param array<string> $watchDirs
     * @param array<string> $watchExcludes
     * @return array<string, int> path => mtime snapshot of watched PHP files
     */
    private static function scanWatchFiles(array $watchDirs, array $watchExcludes): array
    {
        if ($watchDirs === []) {
            $appAlias = Yii::getAlias('@app', false);
            if (!is_string($appAlias)) {
                return [];
            }
            $watchDirs = [$appAlias];
        }

        $snapshot = [];
        foreach ($watchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
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
                    foreach ($watchExcludes as $name) {
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
