<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Websocket;

use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

/**
 * User-implemented WebSocket lifecycle handler.
 *
 * The server only bridges the Swoole WebSocket events to this interface:
 * every strategy - authentication (session / token / JWT / none), allowed
 * upgrade paths, message formats and routing, per-worker connection
 * registries - belongs to the implementation. The server guarantees only the
 * environment: each callback runs inside a request coroutine, so coroutine
 * pools (DB / Redis) and the runtime hooks apply here like anywhere else.
 *
 * Important Swoole semantics verified on 6.2.x:
 * - when an `onRequest` handler is registered (plain HTTP on the same port),
 *   `Upgrade: websocket` requests are routed through **onRequest** - the
 *   automatic handshake never fires, `onHandShake` is never called and the
 *   connection would NOT be established. The server therefore performs the
 *   upgrade manually (101 + Sec-WebSocket-Accept) inside the request
 *   coroutine, which correctly establishes the WebSocket state (`open()`
 *   fires, `push()`/`isEstablished()` work, verified);
 * - `handshake()` is the authorization point BEFORE any connection exists:
 *   return `false`/throw to reject with a 403, `true` to complete the
 *   upgrade. Richer rejections (401, redirect) can be written by ending the
 *   response yourself and returning `false`;
 * - client `ping` frames are answered automatically by Swoole and never
 *   reach `message()`;
 * - after a manual upgrade `open()` fires - per-connection metadata owned
 *   by the implementation should be registered there.
 */
interface WebsocketHandlerInterface
{
    /**
     * Called when an `Upgrade: websocket` request arrives, before the 101
     * response is sent (inside the request coroutine).
     *
     * Return `true` to complete the upgrade, `false` or throw to reject it
     * with a plain 403. For richer rejection responses (401, redirect, ...)
     * end the response yourself and return `false` - the server detects an
     * already-ended response and leaves it untouched.
     */
    public function handshake(Request $request): bool;
    /**
     * Called right after the upgrade completes, inside a request coroutine.
     *
     * This is the authorization point: `$request` carries the handshake HTTP
     * request (cookies, token headers, uri, ...). On failure call
     * `$server->disconnect($fd, 4001, 'unauthorized')` (or any 4xxx code)
     * from inside this callback. Per-connection metadata owned by the
     * implementation should be registered here.
     */
    public function open(Server $server, Request $request, int $fd): void;

    /**
     * Called for every text/binary frame, inside a request coroutine.
     *
     * The payload is passed through untouched - JSON envelopes, binary
     * protocols and per-message routing belong to the implementation.
     */
    public function message(Server $server, Frame $frame, int $fd): void;

    /**
     * Called when the connection is closed (client side or via
     * `Server->disconnect()`), inside a request coroutine.
     */
    public function close(Server $server, int $fd): void;
}
