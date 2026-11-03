<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use yii2stest\ServerTestCase;

/**
 * Graceful reload: SIGUSR1 (`swoole/reload`, the watch watchdog) must drain
 * in-flight requests before restarting the workers, so a slow request is
 * delivered instead of being cut off mid-flight.
 *
 * @group server
 * @group integration
 */
class HttpServerGracefulStopTest extends ServerTestCase
{
    public function testReloadWaitsForInFlightRequests(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-slow.php', [
                'server' => ['gracefulTimeout' => 5.0],
            ]);
            try {
                // Start a slow request (~1s) and reload while it is in flight.
                $slowDone = false;
                $slowStatus = 0;
                Coroutine::create(function () use (&$slowDone, &$slowStatus): void {
                    $client = new Client('127.0.0.1', $this->port);
                    $ok = $client->get('/slow/index');
                    $slowStatus = (int) $client->statusCode;
                    $client->close();
                    $slowDone = $ok;
                });

                Coroutine::sleep(0.3);
                $this->assertFalse($slowDone, 'slow request should still be running');

                $this->reloadServer(); // SIGUSR1: graceful worker reload

                // A regular closure captures $slowDone by reference (arrow
                // functions capture by value and would never see the update).
                $this->waitFor(function () use (&$slowDone): bool {
                    return $slowDone;
                }, 5.0);
                $this->assertTrue($slowDone, 'in-flight request must complete during graceful reload');
                $this->assertSame(200, $slowStatus);

                // The server stays up after the reload.
                $this->assertSame(404, $this->get('/no-such-route')['status']);
            } finally {
                $this->stopServer();
            }
        });
    }
}
