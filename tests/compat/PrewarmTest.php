<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

/**
 * `HttpServer::$prewarmConnections` must open the configured number of
 * connections per pool at worker startup, so the first real requests do not
 * pay the connect/handshake latency. The warm-up is asynchronous, so poll the
 * metrics endpoint until every pool reports the expected count.
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
            $this->startServer('app-compat.php', [
                'dispatcher' => ['enablePoolMetricsEndpoint' => true],
                'server' => ['prewarmConnections' => 2],
            ]);
            try {
                $this->waitFor(fn (): bool => $this->allPoolsPreWarmed(2), 15.0);

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

    /**
     * True when the db and redis pools both report at least `$count` existing
     * connections. The metrics endpoint never borrows, so it is safe to poll.
     */
    private function allPoolsPreWarmed(int $count): bool
    {
        $res = $this->http('GET', '/__pool');
        if ($res['status'] !== 200) {
            return false;
        }

        $pools = $res['json']['pools'] ?? [];
        foreach (['db', 'redis'] as $id) {
            if (($pools[$id]['size'] ?? 0) < $count) {
                return false;
            }
        }

        return true;
    }
}
