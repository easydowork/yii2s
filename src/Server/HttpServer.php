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
use Swoole\WebSocket\Server as SwooleWebSocketServer;
use Throwable;
use Yii;
use yii\base\Component;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use Yii2S\Swoole\Scheduling\CrontabDispatcher;
use Yii2S\Swoole\Websocket\WebsocketBroadcasterInterface;
use Yii2S\Swoole\Websocket\WebsocketHandlerInterface;

/**
 * HTTP server built on the classic multi-process [[Swoole\Http\Server]].
 *
 * One master process manages `workerNum` worker processes; each worker runs
 * its own coroutine scheduler and owns its own copy of the Yii2 application
 * and its connection pools (so `poolMaxActive` applies **per worker**). A
 * worker crash is recovered by the master, and graceful reload/shutdown are
 * provided natively by Swoole (SIGUSR1 / SIGTERM).
 *
 * Every request runs in a coroutine: the dispatcher rebuilds request-scoped
 * state per request and pooled connections stay sticky to their coroutine.
 *
 * With [[enableWebSocket]] the internal server is a
 * [[Swoole\WebSocket\Server]] instead - a subclass of Http\Server, so plain
 * HTTP keeps flowing through [[dispatcher]] on the same port while
 * `Upgrade: websocket` requests are bridged to the user-provided
 * [[websocketHandler]] (see [[Yii2S\Swoole\Websocket\WebsocketHandlerInterface]]).
 */
class HttpServer extends Component
{
    public const EVENT_BEFORE_START = 'beforeStart';

    public const EVENT_AFTER_START = 'afterStart';

    public const EVENT_AFTER_STOP = 'afterStop';

    /** Manager-process callback when a worker crashed (segfault / fatal / OOM). */
    public const EVENT_WORKER_ERROR = 'workerError';

    /** Normal shutdown begins; coroutine APIs are still usable here. */
    public const EVENT_BEFORE_SHUTDOWN = 'beforeShutdown';

    /** Worker is draining (reload_async); keep event handles minimal to exit. */
    public const EVENT_WORKER_EXIT = 'workerExit';

    /** Worker stopped; no coroutine/event-loop APIs are usable here. */
    public const EVENT_WORKER_STOP = 'workerStop';

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

    /** @var string|bool|null Swoole log file (`log_file`). null = per-user
     *  runtime dir with daily rotation; false = stdout only. A
     *  `settings[log_file]` key wins. */
    public $logFile = null;

    /** @var int|null Swoole log level (`SWOOLE_LOG_*`); null keeps the
     *  default. A `settings[log_level]` key wins. */
    public ?int $logLevel = null;

    /** @var int|null coroutine hook flags applied via Coroutine::set()
     *  before start(). SWOOLE_HOOK_ALL by default: the async Http\Server does
     *  not enable hooks itself, so PDO/file IO would block the worker loop and
     *  serialize the pools. null keeps a caller-supplied value. */
    public ?int $hookFlags = SWOOLE_HOOK_ALL;

    /** @var bool serve WebSocket upgrades on the same port. */
    public bool $enableWebSocket = false;

    /** @var object|null [[WebsocketHandlerInterface]] implementation. Required
     *  when [[enableWebSocket]] is true; owns handshake auth, allowed paths,
     *  message format and connection registry. Instance, config or id. */
    public $websocketHandler = null;

    /** @var object|null [[WebsocketBroadcasterInterface]] implementation;
     *  `start()` is called from onWorkerStart and owns its transport. */
    public $websocketBroadcaster = null;

    /** @var string bearer token for `/metrics` and `/__pool`; empty (default)
     *  leaves them public, non-empty requires `Authorization: Bearer <token>`. */
    public string $adminToken = '';

    /**
     * @var CrontabDispatcher|array|null optional dispatcher, ticked once a
     * second from a reactor timer in every worker (worker 0 executes by
     * default; a Redis lock coordinates multiple instances). Accepts an
     * instance or a config array resolved by init().
     */
    public $crontab = null;

    private ?SwooleHttpServer $server = null;

    private bool $isRunning = false;

    private ?PidFile $pidFileManager = null;

    private ?Process $watchdog = null;

    public function init(): void
    {
        parent::init();

        if (is_array($this->crontab)) {
            $this->crontab = Yii::createObject($this->crontab);
        }

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
        $this->logFile = $this->resolveAlias($this->logFile);

        if ($this->enableWebSocket) {
            if ($this->websocketHandler === null) {
                throw new InvalidConfigException(
                    'Property "websocketHandler" must be set when "enableWebSocket" is true.'
                );
            }
            $this->websocketHandler = Instance::ensure(
                $this->websocketHandler,
                WebsocketHandlerInterface::class
            );
            if ($this->websocketBroadcaster !== null) {
                $this->websocketBroadcaster = Instance::ensure(
                    $this->websocketBroadcaster,
                    WebsocketBroadcasterInterface::class
                );
            }
        }

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
                ?? $this->defaultRuntimeDir()
                    . DIRECTORY_SEPARATOR
                    . sprintf('%s-%d.pid', $this->host, $this->port);
            $this->pidFileManager = new PidFile($path);
        }

        return $this->pidFileManager;
    }

    /**
     * Per-user runtime directory inside the system temp dir, shared by the
     * default pid file and the default Swoole log file.
     */
    private function defaultRuntimeDir(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . sprintf('yii2s-swoole-%d', $this->effectiveUid());
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

        // Hooks must be configured before start(); see $hookFlags.
        if ($this->hookFlags !== null) {
            \Swoole\Coroutine::set(['hook_flags' => $this->hookFlags]);
        }

        // SWOOLE_PROCESS (master + N workers) scales across cores and supports
        // graceful reload; SWOOLE_BASE does not. WebSocket\Server extends
        // Http\Server, so HTTP keeps flowing through the same onRequest.
        $server = $this->enableWebSocket
            ? new SwooleWebSocketServer($this->host, $this->port, SWOOLE_PROCESS)
            : new SwooleHttpServer($this->host, $this->port, SWOOLE_PROCESS);
        $this->server = $server;
        $this->applySettings($server);

        $dispatcher = $this->dispatcher;
        $prewarm = $this->prewarmConnections;
        $maintenanceInterval = $this->poolMaintenanceInterval;
        $broadcaster = $this->websocketBroadcaster;
        $wsHandler = $this->enableWebSocket ? $this->websocketHandler : null;
        $crontab = $this->crontab;

        // The gate lives in the metrics middleware (same path matching as the
        // endpoint); only the first-party dispatcher can receive the token.
        if ($dispatcher instanceof RequestDispatcher) {
            $dispatcher->adminToken = $this->adminToken;
        }

        // Every request runs inside a coroutine (Swoole enables this by
        // default); the dispatcher therefore keeps working unchanged.
        $server->on('request', static function ($request, $response) use ($dispatcher, $server, $wsHandler): void {
            // Swoole 6.2 semantics (verified): when an onRequest handler is
            // registered, `Upgrade: websocket` requests are routed HERE - the
            // automatic handshake never fires, onHandShake is never called
            // and the connection would not be established. Perform the
            // upgrade manually (101 + Sec-WebSocket-Accept) or reject with
            // 403; after the manual upgrade onOpen/onMessage/push all work.
            if ($wsHandler !== null
                && strtolower((string) ($request->header['upgrade'] ?? '')) === 'websocket'
            ) {
                try {
                    $accepted = $wsHandler->handshake($request);
                } catch (Throwable $e) {
                    self::logWebsocketFailure('websocket handshake handler failed', $e);
                    $accepted = false;
                }

                if ($accepted === false || !$response->isWritable()) {
                    if ($response->isWritable()) {
                        $response->status(403);
                        $response->end();
                    }
                    return;
                }

                $accept = base64_encode(sha1(
                    (string) ($request->header['sec-websocket-key'] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
                    true
                ));
                $response->header('Upgrade', 'websocket');
                $response->header('Connection', 'Upgrade');
                $response->header('Sec-WebSocket-Accept', $accept);
                $response->status(101);
                $response->end();

                return;
            }

            $dispatcher->dispatch($request, $response, $server);
        });

        if ($this->enableWebSocket) {
            // open/message/close run inside request coroutines
            // (enable_coroutine), so implementations may use the coroutine
            // pools freely. A handler exception must not take the worker down.
            $server->on('open', static function ($serv, $request) use ($wsHandler): void {
                try {
                    $wsHandler->open($serv, $request, (int) $request->fd);
                } catch (Throwable $e) {
                    self::logWebsocketFailure('websocket open handler failed', $e);
                }
            });
            $server->on('message', static function ($serv, $frame) use ($wsHandler): void {
                try {
                    $wsHandler->message($serv, $frame, (int) $frame->fd);
                } catch (Throwable $e) {
                    self::logWebsocketFailure('websocket message handler failed', $e);
                }
            });
            $server->on('close', static function ($serv, $fd) use ($wsHandler): void {
                try {
                    $wsHandler->close($serv, (int) $fd);
                } catch (Throwable $e) {
                    self::logWebsocketFailure('websocket close handler failed', $e);
                }
            });
        }

        // Each worker owns its app and pools (channels cannot survive fork),
        // so workerStart prepares them asynchronously; requests are served
        // immediately and only pay connect latency until warm-up finishes.
        $server->on('workerStart', static function ($workerServer, int $workerId) use ($dispatcher, $prewarm, $maintenanceInterval, $server, $broadcaster, $crontab): void {
            // Only worker 0 executes crontabs unless a Redis lock coordinates
            // instances. Timers are cleared on worker exit; the Redis lock
            // needs a coroutine to tick in.
            CrontabDispatcher::setWorkerId($workerId);
            if ($crontab !== null) {
                \Swoole\Timer::tick(1000, static function () use ($crontab): void {
                    \Swoole\Coroutine::create(static function () use ($crontab): void {
                        $crontab->tick();
                    });
                });
            }
            // Kernel-level counters for /metrics (connection_num, abort_count,
            // ...). stats() reads the shared server state, so every worker sees
            // the same global numbers - the metrics middleware therefore
            // appends them once per scrape instead of aggregating. Only the
            // first-party dispatcher carries the provider; custom
            // implementations degrade gracefully without it.
            if ($dispatcher instanceof RequestDispatcher) {
                $dispatcher->serverStatsProvider = static fn (): array => $server->stats();
                if ($dispatcher->enableMetricsAggregation) {
                    // Publish from every worker. A scrape sticks to one worker
                    // under the default dispatch mode, so the others would
                    // otherwise disappear from /metrics after the snapshot TTL.
                    $intervalMs = (int) (MetricsAggregator::PUBLISH_INTERVAL * 1000);
                    $publish = static function () use ($dispatcher): void {
                        \Swoole\Coroutine::create(static function () use ($dispatcher): void {
                            $dispatcher->publishWorkerMetrics();
                        });
                    };
                    $publish();
                    \Swoole\Timer::tick($intervalMs, $publish);
                }
            }

            if ($broadcaster !== null) {
                \Swoole\Coroutine::create(static function () use ($broadcaster, $server): void {
                    $broadcaster->start($server);
                });
            }

            if ($prewarm > 0) {
                \Swoole\Coroutine::create(static function () use ($dispatcher, $prewarm): void {
                    $dispatcher->prewarm($prewarm);
                });
            }

            if ($maintenanceInterval > 0) {
                // Reactor timer, not a permanent sleeping coroutine: Swoole
                // clears timers on worker exit, so upkeep cannot trap the
                // worker during shutdown/reload.
                \Swoole\Timer::tick(
                    (int)($maintenanceInterval * 1000),
                    static function () use ($dispatcher): void {
                        // Timer callbacks may run outside a coroutine; the
                        // pool channel API requires one.
                        \Swoole\Coroutine::create(static function () use ($dispatcher): void {
                            $dispatcher->maintainPools();
                        });
                    }
                );
            }
        });

        // Lifecycle bridges: a worker crash (segfault / fatal / OOM-kill) is
        // currently invisible - the manager silently respawns it. Surface it
        // as a structured entry in the Swoole log_file plus an event, so
        // monitoring can pick it up.
        $server->on('WorkerError', function ($serv, $workerId, $workerPid, $exitCode, $signal): void {
            $reason = match (true) {
                $signal === 11 => 'segment fault (likely a Swoole/extension bug)',
                $signal === 9 => 'SIGKILL (manual kill or OOM)',
                $exitCode === 255 => 'PHP fatal error',
                default => 'abnormal exit',
            };
            // onWorkerError fires in the MANAGER process (Swoole docs), with
            // no request lifecycle: buffered Yii log targets would never
            // flush here. E_USER_WARNING goes straight to Swoole's log_file
            // and never reaches an HTTP response.
            trigger_error(
                sprintf('worker crashed: worker_id=%d worker_pid=%d exit_code=%d signal=%d (%s)', $workerId, $workerPid, $exitCode, $signal, $reason),
                E_USER_WARNING
            );
            $this->trigger(self::EVENT_WORKER_ERROR, new Event([
                'data' => ['worker_id' => $workerId, 'worker_pid' => $workerPid, 'exit_code' => $exitCode, 'signal' => $signal],
            ]));
        });

        // Normal shutdown: coroutine APIs are still usable here (handbook),
        // so handlers may flush metrics / close pools gracefully.
        $server->on('BeforeShutdown', function (): void {
            $this->trigger(self::EVENT_BEFORE_SHUTDOWN, new Event(['data' => []]));
        });

        // Draining phase after reload_async: keep event handles minimal so
        // the worker exits as soon as in-flight requests finish.
        $server->on('WorkerExit', function ($serv, $workerId): void {
            $this->trigger(self::EVENT_WORKER_EXIT, new Event(['data' => ['worker_id' => $workerId]]));
        });

        $server->on('WorkerStop', function ($serv, $workerId): void {
            $this->trigger(self::EVENT_WORKER_STOP, new Event(['data' => ['worker_id' => $workerId]]));
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
     * Logs a WebSocket handler failure. These callbacks run before the Yii
     * request lifecycle, so Yii::$app may be null and Yii::error() would drop
     * the message; stderr is the fallback for container log collectors.
     */
    private static function logWebsocketFailure(string $context, Throwable $e): void
    {
        $message = sprintf(
            '%s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        if (Yii::$app !== null && Yii::$app->has('log')) {
            Yii::error($message, __METHOD__);

            return;
        }

        error_log($message);
    }

    /**
     * Applies the configured settings; worker count and graceful wait come
     * from the dedicated properties, user keys win.
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

        // Keep package_max_length aligned with nginx client_max_body_size;
        // backlog 2048 is clamped by the kernel somaxconn. Both can be
        // overridden via settings.
        $this->settings += [
            'package_max_length' => 2 * 1024 * 1024,
            'backlog' => 2048,
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

        // Default Swoole logs to a rotating per-user file instead of stdout;
        // false keeps stdout-only for containers. User settings win.
        if ($this->logFile !== false) {
            $logFile = $this->logFile
                ?? $this->defaultRuntimeDir()
                    . DIRECTORY_SEPARATOR
                    . sprintf('swoole-%s-%d.log', $this->host, $this->port);
            if (@mkdir(dirname($logFile), 0770, true) || is_dir(dirname($logFile))) {
                $this->settings += [
                    'log_file' => $logFile,
                    'log_rotation' => SWOOLE_LOG_ROTATION_DAILY,
                ];
            }
        }
        if ($this->logLevel !== null) {
            $this->settings += ['log_level' => $this->logLevel];
        }

        // Microsecond timestamps disambiguate interleaved worker logs;
        // display_errors stays off so PHP warnings never reach responses.
        $this->settings += [
            'log_date_with_microseconds' => true,
            'display_errors' => false,
        ];

        // Swoole warns about unsupported options (the supported set varies by
        // build). Route those warnings to the log rather than the PHP error
        // channel, where they could reach responses.
        $rejected = [];
        set_error_handler(static function (int $severity, string $message) use (&$rejected): bool {
            if ($severity === E_USER_WARNING) {
                $rejected[] = $message;

                return true;
            }

            return false;
        });
        try {
            $server->set($this->settings);
        } finally {
            restore_error_handler();
        }
        if ($rejected !== []) {
            Yii::warning('Swoole rejected server settings: ' . implode('; ', $rejected), __METHOD__);
        }
    }

    /**
     * Development-only hot reload: a watchdog process polls watched PHP files
     * and sends SIGUSR1 to the master on change; reloaded workers pick up the
     * new code without a restart.
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

                    $current = $scan();
                    if ($current !== $snapshot) {
                        @posix_kill($masterPid, SIGUSR1);
                    }

                    $snapshot = $current;
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
