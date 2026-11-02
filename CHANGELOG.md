# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  scripts (`scripts/wsl-e2e.sh`, `scripts/wsl-bench.sh`).

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
