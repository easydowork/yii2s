<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

use Yii2S\Swoole\Server\RequestDispatcher;

/**
 * `HttpServer::$prewarmConnections` must open the configured number of
 * connections per pool at startup, so the first real requests do not pay the
 * connect/handshake latency.
 *
 * @group compat
 * @group integration
 */
class PrewarmTest extends CompatTestCase
{
    /**
     * @testdox Pre-warming opens and returns the configured connections
     */
    public function testPrewarmOpensPoolConnections(): void
    {
        $this->runInCoroutine(function (): void {
            $dispatcher = new RequestDispatcher(
                __DIR__ . '/../data/app-compat.php',
                ['enablePoolMetricsEndpoint' => true]
            );
            $this->startServerWithDispatcher($dispatcher, 2);
            try {
                $res = $this->http('GET', '/__pool');
                $this->assertSame(200, $res['status']);
                $pools = $res['json']['pools'] ?? [];

                foreach (['db', 'redis'] as $id) {
                    $this->assertArrayHasKey($id, $pools);
                    $this->assertGreaterThanOrEqual(2, $pools[$id]['size'] ?? 0, "{$id} connections were opened");
                    $this->assertGreaterThanOrEqual(2, $pools[$id]['idle'] ?? 0, "{$id} connections are idle");
                    $this->assertGreaterThanOrEqual(2, $pools[$id]['total_borrowed'] ?? 0);
                }
            } finally {
                $this->stopServer();
            }
        });
    }
}
