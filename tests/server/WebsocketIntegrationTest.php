<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Swoole\Coroutine\Http\Client;
use yii2stest\ServerTestCase;

/**
 * Same-port HTTP + WebSocket integration test (enableWebSocket=true): plain
 * HTTP keeps flowing through the dispatcher, upgrades are served by Swoole's
 * automatic handshake and the user handler authorizes in `open()` (Swoole
 * 6.2.x never calls `onHandShake` when an `onRequest` handler is registered).
 *
 * @group server
 * @group integration
 */
class WebsocketIntegrationTest extends ServerTestCase
{
    public function testHttpAndWebsocketShareTheSamePort(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-ws.php');
            try {
                // Plain HTTP keeps working on the WebSocket-enabled port.
                $index = $this->get('/');
                $this->assertSame(200, $index['status']);
                $this->assertSame('yii2stest', $index['json']['app'] ?? null);

                // Upgrade on the whitelisted path, then echo a message.
                $ws = new Client('127.0.0.1', $this->port);
                $this->assertTrue($ws->upgrade('/ws'));
                $ws->push('hello');
                $frame = $ws->recv();
                $this->assertNotFalse($frame);
                $this->assertSame('echo:hello', (string) $frame->data);
                $ws->close();
            } finally {
                $this->stopServer();
            }
        });
    }

    public function testOpenAuthorizationDisconnectsForeignPaths(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-ws.php');
            try {
                // The upgrade itself succeeds (Swoole serves it automatically);
                // the handler closes the connection in open() with code 4001.
                // The Swoole client answers the close frame and closes on its
                // side, so recv() reports the closed connection instead of
                // handing out the close frame.
                $ws = new Client('127.0.0.1', $this->port);
                $this->assertTrue($ws->upgrade('/ws/other'));

                $closed = false;
                for ($i = 0; $i < 5; $i++) {
                    if ($ws->recv() === false) {
                        $closed = true;
                        break;
                    }
                }
                $this->assertTrue($closed, 'expected the server to close the connection');
                $ws->close();
            } finally {
                $this->stopServer();
            }
        });
    }

    public function testOpenAuthorizationRegistersMetadata(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-ws.php');
            try {
                $ws = new Client('127.0.0.1', $this->port);
                $this->assertTrue($ws->upgrade('/ws/meta'));
                $ws->push('{"op":"meta"}');
                $frame = $ws->recv();
                $this->assertNotFalse($frame);
                $meta = json_decode((string) $frame->data, true);
                $this->assertSame(['user' => 'tester'], $meta['meta'] ?? null);
                $ws->close();
            } finally {
                $this->stopServer();
            }
        });
    }

    public function testServerInitiatedDisconnect(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-ws.php');
            try {
                $ws = new Client('127.0.0.1', $this->port);
                $this->assertTrue($ws->upgrade('/ws'));
                $ws->push('{"op":"kick"}');

                // Same as the auth case: the close frame is answered by the
                // client protocol layer, so recv() reports the closure.
                $closed = false;
                for ($i = 0; $i < 5; $i++) {
                    if ($ws->recv() === false) {
                        $closed = true;
                        break;
                    }
                }
                $this->assertTrue($closed, 'expected the server to close the connection');
                $ws->close();
            } finally {
                $this->stopServer();
            }
        });
    }
}
