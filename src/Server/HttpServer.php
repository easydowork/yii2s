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
 * The request lifecycle is otherwise identical to the coroutine server this
 * class used to wrap: every request is handled inside a coroutine, the
 * dispatcher rebuilds request-scoped state per request and pooled connections
 * stay sticky to their coroutine.
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

    /** @var string|bool|null Swoole server log file (mapped to `log_file`).
     *  null (default) resolves to a per-user runtime directory next to the
     *  pid file, with daily rotation, so internal Swoole logs (worker
     *  crashes, port conflicts, send failures) survive the session instead
     *  of vanishing with stdout. Set to `false` to keep stdout-only logging
     *  (e.g. containers collecting stdout). A `settings[log_file]` key still
     *  wins over any of this. */
    public $logFile = null;

    /** @var int|null Swoole log level (one of the `SWOOLE_LOG_*` constants);
     *  null keeps Swoole's default (INFO). A `settings[log_level]` key wins. */
    public ?int $logLevel = null;

    /** @var int|null coroutine runtime hook flags (see the Swoole handbook
     *  "one-click coroutine" chapter), applied via Coroutine::set() right
     *  before start(). Default SWOOLE_HOOK_ALL: the async-style
     *  Swoole\Http\Server does NOT enable the runtime hook on its own
     *  (settings['hook_flags'] is ignored on 6.2.x and Coroutine\run enables
     *  it only for its own path), so without this, PDO/mysqli/file IO block
     *  the worker event loop and the coroutine pool degrades to serialized
     *  queries. Set to null to keep whatever you configured yourself via
     *  Coroutine::set(). Baseline (PDO SLEEP(0.2), 20 concurrency, 4
     *  workers): 92 qps with hooks vs 19 without. */
    public ?int $hookFlags = SWOOLE_HOOK_ALL;

    /** @var bool whether to run the internal server as a
     *  [[Swoole\WebSocket\Server]] (a subclass of Http\Server). Plain HTTP
     *  keeps flowing through [[dispatcher]] on the same port; `Upgrade:
     *  websocket` requests are bridged to [[websocketHandler]]. */
    public bool $enableWebSocket = false;

    /** @var object|null user-implemented WebSocket lifecycle handler
     *  ([[WebsocketHandlerInterface]]). Required when [[enableWebSocket]] is
     *  true. Accepts an instance, a config array or a component id. Every
     *  strategy - handshake authentication, allowed upgrade paths, message
     *  formats, per-worker connection registries - belongs to the
     *  implementation; the server only bridges the Swoole events. */
    public $websocketHandler = null;

    /** @var object|null optional cross-worker broadcast entry point
     *  ([[WebsocketBroadcasterInterface]]); `start()` is invoked from
     *  `onWorkerStart` and the implementation runs its own transport
     *  (e.g. a Redis pub/sub subscriber coroutine). Accepts an instance,
     *  a config array or a component id. */
    public $websocketBroadcaster = null;

    /** @var string bearer token guarding the observability endpoints
     *  (`/metrics`, `/__pool`). Empty (default) = the endpoints are public
     *  (backwards compatible); non-empty = requests must carry
     *  `Authorization: Bearer <token>` (`hash_equals` compared) or they are
     *  answered with 401 and NO payload. Send the header from Prometheus via
     *  `scrape_configs[].authorization`. */
    public string $adminToken = '';

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

        // Coroutine runtime hooks MUST be configured before start() (the
        // handbook-equivalent of ini_set). See the $hookFlags property for
        // why this is a life-saving default rather than an optimization.
        if ($this->hookFlags !== null) {
            \Swoole\Coroutine::set(['hook_flags' => $this->hookFlags]);
        }

        // Explicit SWOOLE_PROCESS: a master + worker_num workers. The default
        // on some builds is SWOOLE_BASE (single process), which neither scales
        // across cores nor supports graceful reload().
        // With enableWebSocket the internal server is a Swoole\WebSocket\Server
        // - a subclass of Http\Server, so every setting, pool and lifecycle
        // hook below keeps working unchanged; plain HTTP requests still flow
        // through onRequest on the same port.
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

        // Observability access control lives INSIDE the metrics middleware
        // (see AbstractMetricsMiddleware): the bearer-token gate uses the
        // exact same path matching as the endpoint itself, so normalization
        // drift between the two can never re-open a bypass (//metrics and
        // trailing-slash leaks were exactly that class of bug). Forward the
        // configured token to the first-party dispatcher only.
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
                    trigger_error('websocket handshake handler failed: ' . $e->getMessage(), E_USER_WARNING);
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
                    trigger_error('websocket open handler failed: ' . $e->getMessage(), E_USER_WARNING);
                }
            });
            $server->on('message', static function ($serv, $frame) use ($wsHandler): void {
                try {
                    $wsHandler->message($serv, $frame, (int) $frame->fd);
                } catch (Throwable $e) {
                    trigger_error('websocket message handler failed: ' . $e->getMessage(), E_USER_WARNING);
                }
            });
            $server->on('close', static function ($serv, $fd) use ($wsHandler): void {
                try {
                    $wsHandler->close($serv, (int) $fd);
                } catch (Throwable $e) {
                    trigger_error('websocket close handler failed: ' . $e->getMessage(), E_USER_WARNING);
                }
            });
        }

        // Each worker owns its own application/pools, so warm them up per
        // worker, not in the master (channels cannot survive fork). The borrow
        // loop needs a coroutine; workerStart runs outside one. The warm-up is
        // best-effort and asynchronous: the worker serves requests immediately
        // and pays the connect latency only if the warm-up has not finished.
        $server->on('workerStart', static function ($workerServer, int $workerId) use ($dispatcher, $prewarm, $maintenanceInterval, $server, $broadcaster): void {
            // Crontab ticks from every worker must run on worker 0 only,
            // unless the dispatcher also holds a Redis lock across instances.
            CrontabDispatcher::setWorkerId($workerId);
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
                // Periodic pool upkeep (max-lifetime recycling, idle keepalive,
                // min-idle replenishment). A reactor-level timer is used instead
                // of a bare `while (true) { sleep() }` coroutine: Swoole clears
                // its timers when a worker exits, so upkeep stops cleanly on
                // shutdown/reload instead of leaving a coroutine stuck in
                // sleep()/pop() and tripping the "all coroutines asleep -
                // deadlock" fatal (which forced the worker to be killed).
                \Swoole\Timer::tick(
                    (int)($maintenanceInterval * 1000),
                    static function () use ($dispatcher): void {
                        // Timer callbacks are not guaranteed to run inside a
                        // coroutine; the pool channel API requires one, so run
                        // the pass in a short-lived coroutine (it always exits).
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

        // --- request body / connection limits ---
        // package_max_length: Swoole already rejects bodies over 2 MB with
        // 413 (verified on 6.2.x). Pinning the value makes the limit
        // discoverable and pairs with nginx `client_max_body_size`: keep both
        // aligned, or nginx accepts bodies Swoole then rejects. Note the
        // handbook limits: HTTP GET caps at 8 KB (not configurable), POST
        // over the limit is cut off with the connection.
        // backlog: the stock listen backlog drops SYNs during connection
        // bursts (bench: 50+ new connections at once). 2048 is clamped by
        // the kernel somaxconn, so raising it is safe on every platform.
        // Overrides: settings['package_max_length'] / settings['backlog'].
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

        // Swoole's own logs (worker crashes, port conflicts, send failures)
        // go to stdout by default: lost with the session when run in the
        // foreground, buried in the supervisor log otherwise, and unbounded
        // in either direction. Default to a per-user log file next to the
        // pid file with daily rotation; `false` keeps stdout-only (e.g.
        // containers collecting stdout), and user `settings` keys win.
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

        // Microsecond timestamps: multi-worker logs interleave, so events
        // can only be attributed during incident triage with sub-second
        // precision. Note the official key spelling
        // (log_date_with_microseconds). Override: settings[log_date_with_microseconds].
        // display_errors=false: PHP notices/warnings must not leak into
        // responses or stdout; errors still reach log_file (SWOOLE_LOG_ERROR)
        // and Yii's log target. Override: settings['display_errors'].
        $this->settings += [
            'log_date_with_microseconds' => true,
            'display_errors' => false,
        ];

        $server->set($this->settings);

        // Blind-spot defence: a settings key can be stored but never consumed
        // by Swoole (hook_flags was ignored by 6.2.x until Runtime::set took
        // over). Flag keys that did not survive into the applied settings so
        // misconfigurations surface instead of failing silently.
        $ignored = [];
        foreach (array_keys($this->settings) as $settingsKey) {
            if (!array_key_exists($settingsKey, $server->setting)) {
                $ignored[] = $settingsKey;
            }
        }
        if ($ignored !== []) {
            trigger_error(
                'settings keys not applied by Swoole (verify they are consumed): ' . implode(', ', $ignored),
                E_USER_WARNING
            );
        }
    }

    /**
     * Development-only hot reload: a dedicated process polls watched
     * directories and sends SIGUSR1 (Swoole's native graceful reload) to the
     * master when a PHP file changes. Reload restarts the workers, which pick
     * up the fresh code on the next request. The process keeps polling, so
     * later edits reload again without restarting the server.
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
