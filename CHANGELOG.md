# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Dev tooling no longer hard-requires `swoole-cli`**: composer scripts pick
  a PHP 8.4+ runtime (system `php` first, `swoole-cli` as fallback) and
  `scripts/e2e.sh` falls back to `php`. The CI e2e job runs on the pecl-swoole
  `php` binary and now also initialises the `yii2s_demo` database.
- **Crontab worker integration**: the dispatcher now ticks from a reactor
  timer plus a short-lived coroutine instead of a permanent
  `while (true) { sleep() }` coroutine, which could trap a worker on
  shutdown/reload ("all coroutines asleep" deadlock).
- **Static file conditional requests**: `If-None-Match: *` and weak (`W/`)
  validators now revalidate, and 304 is limited to GET/HEAD; `serveRange()`
  documentation covers the 416 branch.
- **Debug logging**: full exception stack traces are written only for 5xx,
  so 4xx traffic no longer floods the log.
- **`CoroutineRequest` PHPDoc** attached to the right methods; removed a
  misattributed `getRemoteIP()` entry from the v1.1.1 changelog section.

- **`CoroutineRedisCache::flushValues()` flushed nothing** (phpredis `scan()`
  ArgumentCountError via the command-forwarding call) - rewritten on
  `rawCommand()` with explicit failure handling.
- **`adminToken` bearer gate bypass**: the gate lived in HttpServer with
  different path semantics than the middleware, so `/metrics/`, `//metrics`
  and `/__pool/` disclosed metrics unauthenticated. The gate now lives inside
  the metrics middleware with the same path matching; 401 rejections are
  recorded with their real status instead of a fake 200.
- **Multi-value response headers** (Vary, Cache-Control, ...) were silently
  overwritten by Swoole's `header()` - SwooleResponseSender now comma-merges
  repeatable headers.
- **RateLimiter atomicity**: INCR + EXPIRE moved into one Lua script (a worker
  death between the two rate-limited the caller forever).
- **CircuitBreaker state machine**: only the HALF_OPEN probe closes the
  breaker; in-flight calls no longer ride over an OPEN state.
- **Middleware short-circuits no longer record a fake HTTP 200**: each
  short-circuit reports its real status through `Server\HandledStatus` and
  the dispatcher records exactly once per request.
- **CoroutineRedisConnection**: releasing a connection disarms un-executed
  MULTI/PIPELINE queues with `discard()`.
- **Static files**: unsatisfiable Range requests answer 416 with
  `Content-Range: bytes */size` (previously a silent full 200), conditional
  requests get ETag / 304 support.
- **YII_DEBUG stack traces** are written to the log only, never into the
  error response body.
- `CoroutineRequest::getRemoteIP()/getRemoteHost()` implemented (stock
  versions read the never-populated `$_SERVER`).

### Added

- **`Server\HandledStatus`**: single source for short-circuit status
  reporting (report()/consume() with the context key defined once).
- **crontab integration point**: `HttpServer::$crontab` (instance or config
  array) starts the dispatcher in every worker at `onWorkerStart`.
- Regression coverage: flushValues, the token boundary matrix (trailing
  slash / duplicate slashes / query variants), multi-value header merging,
  and the short-circuit status series (206/401/500).

## [v1.1.1] - 2026-11-10

### Fixed

- **Fallback `swooleHttpServer` serves `documentRoot`** (`@app/web`):
  one-line integrations without their own server block kept answering 404
  for every static asset (verified on a real third-party Yii2 app behind
  nginx). With nginx serving the common suffixes, the fallback covers the
  rest (svg/ttf/woff/woff2) so new asset types need zero nginx config.

### Docs

- README: console.php bootstrap registration for `swoole/start`, the
  `adminToken` custom-dispatcher scope, and a full README review (rendering
  fix, v1.1.x feature coverage, upgrade/rollback runbook).

## [v1.1.0] - 2026-11-10

### Removed

- **Same-port `SO_REUSEPORT` multi-instance**: the `enableReusePort` option is
  gone. Swoole ignores `enable_reuse_port` on macOS, which made the previous
  "default on" flag silently ineffective during development and only work on
  Linux - an untested, platform-divergent path. Multi-instance now has a single
  supported shape: **multi-port + nginx upstream** (each instance its own port
  and pid file). The `SWOOLE_PID_FILE` env var and the same-port e2e check were
  removed with it.
- **`swoole/reload` docs/semantics drift**: the console command had already been
  switched to Swoole's native SIGUSR1 worker reload (master pid unchanged), but
  README / DEVELOPMENT / e2e.sh still described the old "stop + respawn"
  behaviour. Those are now aligned.

### Fixed

- **Pool-keeper coroutine deadlock on shutdown/reload**: with
  `poolMaintenanceInterval` set, both the keeper's `while (true) { sleep() }`
  loop and the idle-drain `Channel::pop()` wait on the reactor timer, which a
  worker exiting leaves stuck - tripping Swoole's "all coroutines asleep -
  deadlock" fatal and forcing the worker to be killed (breaking the graceful
  drain). The loop now runs on a reactor timer (cleared on worker exit) and the
  idle drain is guarded by `isEmpty()` so it never blocks on an empty channel.
- **Per-coroutine request-scoped components (critical)**: concurrent requests
  used to share the same `request` / `response` / `session` / `user`
  instances, so one request could read another request's parameters, session
  or identity. The dispatcher now instantiates the resident application as
  `Runtime\CoroutineApplication`, which resolves those four ids from the
  coroutine context (one instance per request). Custom application classes
  must extend it; the default `yii\web\Application` is swapped automatically.
- **Login over HTTP**: `Session::getHasSessionId()` read the process-global
  `$_COOKIE` (never populated by Swoole), so every request after `login()`
  was treated as a guest. It now reads the coroutine request cookies.
- **Flash messages**: the whole flash API wrote to `$_SESSION`; reimplemented
  on the coroutine-local payload with the official counter semantics.
- **`RegenerateID()` data loss**: regenerating before the session was opened
  persisted an empty payload, wiping pre-login data (cart, returnUrl).
- **`Response::sendFile()`**: streamed responses produced headers without a
  body; `SwooleResponseSender` now writes the byte range (206/416 support).
- **Upload saving**: `yii\web\UploadedFile::saveAs()` (with the default
  `$deleteTempFile = true`) always returned false under Swoole, because
  `move_uploaded_file()` does not accept temp files written by Swoole itself.
- **One-line integration on the very first request**:
  `yii\web\Application::bootstrap()` resolves `request` before the component
  replacement runs, so the first request after a restart used the stock
  request (failing with "Unable to determine the request URI") and
  `@webroot`/`@web` were derived from the console entry script. The cached
  stock instance is now discarded and both aliases are re-derived.
- **Error pages**: exceptions bypassed Yii's error handler, so
  `errorHandler.errorAction` (e.g. `site/error`) and custom error views never
  rendered - only the dispatcher's plain-text fallback. Exceptions now render
  through the configured error action (HTML, or JSON for AJAX requests).
- **Response framing**: in-memory bodies are now sent with
  `Response::end($content)` (Content-Length) instead of `write()` + `end()`
  (chunked). HTTP/1.0 keep-alive clients (e.g. `ab -k`) no longer stall after
  the first response, and chunked framing overhead is gone.
- **Static files**: an encoded null byte (`%00`) reached `realpath()` and
  threw an uncaught `ValueError` inside the request coroutine (remote DoS).
- **Session fixation**: `useStrictMode` was silently ignored; unknown
  client-supplied session ids are now regenerated.
- **Cookie parsing**: `unserialize()` uses `allowed_classes => false`,
  matching stock `yii\web\Request`.
- **pid file**: per-user temp directory, symlink refusal, 0600 permissions.
- **SIGTERM** now drains in-flight requests (dedicated shutdown coroutine).
- **Unhandled request exceptions** are logged instead of only returned.
- **Dead pooled DB connection poisons the pool**: when MySQL went away
  (restart / idle timeout), the dead `PDO` was returned to the pool and reused
  by every following request, so a single restart broke all DB traffic until
  the process restarted. A new `Db\CoroutineCommand` detects connection-loss
  errors (SQLSTATE class `08`, or MySQL/MariaDB codes 2006/2013), discards the
  broken connection and rethrows - the request fails once, the pool recovers
  on the next borrow (verified by killing the server-side connection mid-test).

### Changed

- **Production deployment docs corrected**: same-port multi-instance works
  only with a per-instance pid file (the default is keyed by host-port, so the
  start guard rejects the second process). The example config now reads
  `SWOOLE_PID_FILE`, `scripts/e2e.sh` starts two instances on one port and
  asserts both serve, and the docs present both recipes (multi-port + nginx
  upstream as the default, same-port `SO_REUSEPORT` with supervisor-owned
  pid files). Also added the `stopwaitsecs > gracefulTimeout`,
  `client_max_body_size` / `package_max_length`, pool-sizing and health-check
  notes.
- **`scripts/e2e.sh` / `scripts/bench.sh` are portable**: `CLI`,
  `PORT`, `APP_DIR`, `CONCURRENCY`, `DURATION` are configurable and both run on
  macOS and Linux.
- **Per-request hot-path trimming**: the dispatcher parses `request_uri` once
  and shares the path across the raw/static/metrics handlers; `RequestStateManager`
  caches the list of pool-owning component ids (rebuilt only when a new
  component is instantiated) and only resets `$_FILES` when the request actually
  had file uploads.

### Added

- **`adminToken` bearer gate for the observability endpoints**: `/metrics` and
  `/__pool` accept `HttpServer::$adminToken` - empty (default) keeps them
  public (backwards compatible); a configured token makes unauthenticated
  scrapes answer 401 with no payload (`hash_equals`, Prometheus
  `authorization` supported natively). Business routes are unaffected.
- **Same-port WebSocket support (interface-first)**: `HttpServer::$enableWebSocket`
  (default `false`) runs the internal server as `Swoole\WebSocket\Server` - plain
  HTTP keeps working on the same port while upgrades are bridged to a
  user-implemented `WebsocketHandlerInterface` (`handshake/open/message/close`).
  Auth strategy, allowed paths, message formats, connection registries and
  broadcast transports are entirely user-defined; an optional
  `WebsocketBroadcasterInterface` (`start()` per worker) is the extension point
  for cross-worker pushes (e.g. Redis pub/sub). Verified on 6.2.x semantics:
  upgrades are served **inside onRequest** (manual 101 + Sec-WebSocket-Accept),
  `onHandShake` is never called once an onRequest handler exists.
- **Lifecycle events + kernel stats**: `HttpServer` now forwards
  `onWorkerError / onBeforeShutdown / onWorkerExit / onWorkerStop` as Yii events
  (`EVENT_WORKER_ERROR / EVENT_BEFORE_SHUTDOWN / EVENT_WORKER_EXIT /
  EVENT_WORKER_STOP`) - worker crashes (segfault/fatal/OOM) surface in the
  Swoole log with a structured entry. The `/metrics` endpoint appends
  kernel-level gauges from `Swoole\Server->stats()` (connection_num,
  abort_count, accept/close counts, coroutine_num, send/recv bytes).
- **Coroutine runtime hooks enabled by default (behavior change)**: the
  async-style `Swoole\Http\Server` does not enable the runtime hook on its
  own, so PDO/mysqli/file IO **blocked the worker event loop** and the
  coroutine pool degraded to serialized queries. `HttpServer` now applies
  `Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])` before start
  (`$hookFlags`, default `SWOOLE_HOOK_ALL`, `null` keeps your own
  `Coroutine::set()` configuration). Baseline: PDO `SLEEP(0.2)` at 20
  concurrency with 4 workers - 92 qps with hooks vs 19 without.
- **Opinionated server limits**: `package_max_length` (2 MB, matches Swoole's
  own behavior and pairs with nginx `client_max_body_size`) and `backlog`
  (2048) are pinned; `log_date_with_microseconds` (multi-worker triage) and
  `display_errors=false` (no PHP noise in responses/stdout) are defaulted.
  Explicit `settings` keys still win; `dispatch_mode` stays untouched (2 vs 3
  measured identical throughput for this workload - see ROADMAP §6.2).
- **Swoole server log defaults**: `HttpServer` now maps `log_file` to a
  per-user runtime file next to the pid file (`swoole-<host>-<port>.log`)
  with `SWOOLE_LOG_ROTATION_DAILY`, so internal Swoole logs (worker crashes,
  port conflicts, send failures) survive the session and cannot grow
  unbounded. `$logFile = false` keeps stdout-only logging (containers), and
  `$logLevel` exposes the Swoole level. Explicit `settings` keys still win.

- **Connection pool pre-warming**: `HttpServer::$prewarmConnections` (default 0)
  opens that many connections per pool before the server accepts traffic, so a
  restart no longer pays connect/handshake latency on the first requests. Each
  connection is borrowed and held so `borrow()` has to create it, then returned
  to the idle queue.
- **Per-request `request`/`response` prototype clone**: `CoroutineApplication`
  builds one prototype of each and serves `clone` per request instead of a full
  DI build (~1.5µs saved per object). The prototype is invalidated when
  `Bootstrap` swaps the component class, so the first request still gets the
  coroutine class.
- **`scripts/soak.php`**: a sustained-load leak detector - drives the server
  with keep-alive traffic while sampling `/__pool` metrics and (optionally)
  process RSS, then flags borrowed-connection leaks, pool timeouts and memory
  drift. Run it after any pool/runtime change to catch leaks before they reach
  production.
- **`examples/blog`**: cover uploads (CoroutineUploadedFile), comments with
  moderation, Redis view counter, cached front page with version invalidation,
  queue worker + audit log, CSV export through `sendFile()`, API-key
  authenticated REST API, and `scripts/smoke.sh` (52 end-to-end checks).
- **Strict URL parsing**: with `enableStrictParsing`, an unmatched path made
  `UrlManager::parseRequest()` return `false`, which the coroutine request
  destructured into a 500. It now throws a 404 like the stock request.
- **Raw routes**: `RequestDispatcher::addRawRoute()` answers an exact
  `METHOD /path` without creating the Yii request/response (string ->
  text/plain, array -> JSON, null -> handler owns the Swoole response).
  Measured 55-62k QPS per process vs ~14-15k for the full Yii pipeline on the
  same machine. Bypasses routing/session/CSRF/auth by design, so it is meant
  for public stateless endpoints (health checks, metrics, tiny APIs).
- **Real client IP behind a reverse proxy**: `CoroutineRequest::$trustedProxies`
  accepts IPs/CIDR blocks; when the direct peer is trusted, `getUserIP()`
  resolves the right-most untrusted `X-Forwarded-For` hop. Empty by default, so
  forwarding headers stay untrusted (stock Yii behaviour).
- **`Runtime\CoroutineUploadedFile`**: coroutine-local replacement for
  `yii\web\UploadedFile`. It reads the file table of the current request
  instead of the global `$_FILES` (no cross-request mixups) and saves via
  rename/copy instead of `move_uploaded_file()`.
- **`tests/compat/`**: end-to-end Yii2 feature-compatibility suite (routing,
  request inputs, response formats, cookies, CSRF, session, auth, flash,
  uploads, downloads, error handling, resident services, concurrency
  isolation) driven through a real Swoole server; the feature matrix lives in
  `doc/DEVELOPMENT.md` 5.4.

## [v1.0.0] - 2026-11-07

### Added

- **`examples/blog/`**: a complete real-world scenario — classic MVC front
  pages, an authenticated admin panel (CSRF + Redis-backed sessions +
  `data-method` CRUD links), a JSON REST API consumed by a Vue3 SPA,
  migrations and seed data — running unmodified under
  `swoole/start --watch`. Doubles as the integration fixture that surfaced
  the `@webroot` and asset-URL issues fixed below.

### Changed

- **Documentation consolidated into a single `doc/DEVELOPMENT.md`** (the
  separate ROADMAP.md / REVIEW.md / SECURITY-REVIEW.md are merged there,
  reorganized by usage journey: quick start -> resident-memory guidelines ->
  architecture -> ops/deployment -> testing -> extension roadmap -> pitfall
  log -> review appendices).

### Added

- **Development hot reload**: `swoole/start --watch` polls application PHP
  files (mtime, 0.5s, zero dependencies) and gracefully reloads the server
  on change - the new process picks up fresh code within a second while
  in-flight requests drain. Tunables: `watchDirs`, `watchInterval`,
  `watchExcludes`. Dev-only; respawns the whole process.
- **`swoole/check` configuration doctor**: statically reads the web app
  config (from the dispatcher's file) and validates cookieValidationKey /
  prettyUrl / pid directory writability, then probes db (`SELECT 1`) and
  redis (`PING`) through temporary connections. Failures surface in
  development instead of on the first production request.
- **Lifecycle slots documented** (`EVENT_BEFORE_START` / `EVENT_AFTER_START`
  / `EVENT_AFTER_STOP`): the official mount point for periodic tasks and
  other long-lived coroutine features - worked example in doc/ROADMAP.md.
- **doc/ROADMAP.md**: connection-pool expansion plan (PostgreSQL P1,
  RabbitMQ P2 with producer-pool/consumer-exclusive shapes, Kafka assessed
  not coroutine-suitable) on top of the generic factory/validator/destroyer
  pool.

### Fixed

- **Multipart uploads depended on the Swoole build**: `Coroutine\Http\Server`
  defaults `http_parse_files` to *false* (swoole_http.h) - on builds honoring
  that default, `$request->files` would be silently empty. The request-body
  parsers (`http_parse_files`/`http_parse_post`/`http_parse_cookie`) are now
  pinned explicitly on; user-configured keys still win.
- **Multi-file upload shape normalization now covers all parser paths**: with
  the standard C++ parser, array-style fields arrive as the attribute-grouped
  `$_FILES` structure; some build paths produce entry groups instead. Both
  plus single-file entries normalize to what `UploadedFile::getInstancesByName()`
  expects.
- **Removed an invalid `Coroutine::set(['log_level' => ...])` key** - the
  coroutine options parser never accepted it (verified against
  `php_swoole_set_coroutine_option` in swoole-src 6.2.2).

### Added

- **Multi-file uploads were dropped entirely**: Swoole represents
  array-style fields (`files[0]`, `files[1]`) as an entry *group* under one
  field key, which the converter skipped. Such groups are now converted to
  the attribute-grouped `$_FILES` structure PHP produces natively, feeding
  `UploadedFile::getInstancesByName()` correctly (both files sha1-verified
  byte-identical through the real server).
- **The official `UploadedFile` API never saw any upload**: it reads the
  `$_FILES` superglobal directly, which the coroutine runtime never
  populated. `$_FILES` is now filled per request from
  `CoroutineRequest::getFileParams()` (bound to the request lifecycle,
  cleared in cleanup, `UploadedFile::reset()` prevents the static cache from
  leaking files across requests).
- **Cookie signatures were not validated on the way in**:
  `CoroutineRequest::getCookies()` skipped the official
  `loadCookies()` validation, so a tampered identity cookie could impersonate
  any user. Signed cookies are now hash-validated through
  `cookieValidationKey`, unwrapped, and invalid ones dropped (official
  semantics, tampering covered by a regression test).
- **Signed cookies were never hashed on the way out**:
  `SwooleResponseSender` emitted raw values; now
  `Response::sendCookies()` semantics apply (hash name+value through the
  request's validation key). URL encoding stays with Swoole's `cookie()`,
  which does it by itself - a manual encode would double-encode the
  signature (verified by round-trip probing).

### Added

- **Multipart file uploads were silently dropped**: `CoroutineRequest` never
  implemented `getFileParams()` (the `$_FILES` replacement), so
  `yii\web\UploadedFile` could not see any upload. Swoole's `$request->files`
  are now converted to the PHP-standard structure with error-code mapping;
  covered by a real-server integration test (field + 64 KiB binary upload
  verified byte-identical by sha1).
- **Session component crashed on rebuild in the resident server**: the
  parent's `setTimeout()`/`setUseStrictMode()`/`setGcProbability()` write
  native PHP ini settings, which throw once a later request rebuilds the
  component with output already started. All three are overridden - garbage
  collection is handled by the Redis TTL, strict mode is meaningless without
  the native session machinery.
- **`Session::setId()` was silently overridden by `open()`**: cookie
  resolution now only runs when no id was explicitly assigned, matching
  `yii\web\Session` semantics (API sessions, session migration, tests).

### Added

- One-line integration: `Bootstrap::autoReplaceComponents` (default on)
  swaps stock Yii2 component classes (request/response/db/redis) for their
  coroutine counterparts keeping every configured option, and registers a
  default `swooleHttpServer` component (127.0.0.1:9501, dispatcher pointing
  at `@app/config/web.php`) when the application has none. `HttpServer` also
  defaults its dispatcher to `@app/config/web.php` when configured without
  one.
- Full ActiveRecord lifecycle test suite: CRUD, lazy/eager/joinWith
  relations, model events (beforeSave/afterFind), TimestampBehavior, batch
  operations, transaction rollback interop and repeated pool borrows
  (schema consistency under coroutine churn).

- Coroutine HTTP server for Yii2 (`swoole/start`, `stop`, `status`, `reload`
  console commands with pid-file daemonization, cross-platform graceful stop
  and Linux respawn).
- Coroutine-safe connection pool (`Pools/ConnectionPool`) with per-coroutine
  sticky borrows, health checks, wait timeouts, leak sweep and a `/__pool`
  metrics debug endpoint.
- Pool-aware components: `CoroutineDbConnection` (full transaction support),
  `CoroutineRedisConnection` (MULTI/PIPELINE support), `CoroutineRedisCache`,
  `CoroutineSession`.
- Coroutine Redis queue (`CoroutineRedisQueue`) with delayed delivery,
  retry-after-ttr, dead-letter list and the `queue/serve` worker command.
- Buffered log target (`CoroutineFileTarget`) with cross-coroutine mutex.
- PHP-level static file serving (`documentRoot`) with sensitive-file
  protection; production deployments use nginx.
- Request hardening: `secureProtocolHeaders` support, `SameSite` cookie
  forwarding, sensitive-header isolation from process superglobals.
- Coroutine load generator (`scripts/bench.php`) and Linux verification
  scripts (`scripts/e2e.sh`, `scripts/bench.sh`).

### Fixed

- Queue worker crash on empty queues outside a coroutine context.
- Cache `add()` TTL silently dropped by phpredis option array format.
- Sub-coroutine connection leak (parallel DB queries) via pool sweep.
- Cookie `SameSite` attribute not forwarded to Swoole responses.
- `X-Forwarded-Proto` trust now honors `secureProtocolHeaders` configuration.
- Static file serving blocked for executable/sensitive extensions and dotfiles.
- Concurrent log buffer race (lost/duplicated messages).
- Console commands `--host/--port` options for `stop`/`status`/`reload`.
- Pool prewarm iterated only instantiated components (never warmed anything).
