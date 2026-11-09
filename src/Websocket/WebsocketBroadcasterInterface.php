<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Websocket;

use Swoole\WebSocket\Server;

/**
 * Optional cross-worker broadcast entry point.
 *
 * With `dispatch_mode=2` a WebSocket connection is pinned to one worker, so
 * pushing to an fd works only from the worker that owns the connection.
 * Delivering a message to a connection owned by *another* worker (or another
 * instance) needs an external transport - typically Redis pub/sub: each
 * worker runs a subscriber coroutine and pushes locally to the connections it
 * owns.
 *
 * The implementation owns the whole transport: it may hold its own Redis
 * connection, its own envelope format and its own fan-out strategy. The
 * server calls `start()` once per worker after the app is booted and never
 * touches it again.
 */
interface WebsocketBroadcasterInterface
{
    /**
     * Called inside a request coroutine from `onWorkerStart`, after the
     * pools/prewarm are wired up. Spawn subscriber coroutines here (they
     * keep running for the worker's lifetime); return when the setup is
     * done - Swoole clears timers/coroutines on worker exit.
     */
    public function start(Server $server): void;
}
