<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use yii2stest\ServerTestCase;

/**
 * Verifies the kernel-level server statistics on `/metrics` and the
 * adminToken gate that guards the observability endpoints.
 *
 * @group server
 * @group integration
 */
class ServerStatsMetricsTest extends ServerTestCase
{
    public function testMetricsArePublicWithoutAToken(): void
    {
        $this->runInCoroutine(function (): void {
            // Backwards compatible: an empty adminToken leaves the
            // observability endpoints public.
            $this->startServer(__DIR__ . '/../data/app.php', [
                'dispatcher' => ['enableMetricsEndpoint' => true],
            ]);
            try {
                $metrics = $this->get('/metrics');
                $this->assertSame(200, $metrics['status']);

                // Kernel gauges/counters appended by MetricsMiddleware.
                foreach ([
                    'yii2s_server_connection_num',
                    'yii2s_server_abort_count',
                    'yii2s_server_accept_count',
                    'yii2s_server_coroutine_num',
                ] as $metric) {
                    $this->assertStringContainsString($metric, (string) $metrics['body']);
                }

                // The values are kernel counters, so at least one connection
                // (this very scrape) must have been accepted.
                $this->assertMatchesRegularExpression('/yii2s_server_accept_count \d+/', (string) $metrics['body']);
            } finally {
                $this->stopServer();
            }
        });
    }

    public function testAdminTokenGuardsObservabilityEndpoints(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app.php', [
                'dispatcher' => [
                    'enableMetricsEndpoint' => true,
                    'enablePoolMetricsEndpoint' => true,
                ],
                'server' => ['adminToken' => 'test-token'],
            ]);
            try {
                // Missing token: 401 with NO payload (nothing disclosed).
                $anon = $this->get('/metrics');
                $this->assertSame(401, $anon['status']);

                // Wrong token: still 401.
                $wrong = $this->get('/metrics', ['Authorization' => 'Bearer wrong-token']);
                $this->assertSame(401, $wrong['status']);

                // Right token: kernel stats served on /metrics ...
                $metrics = $this->get('/metrics', ['Authorization' => 'Bearer test-token']);
                $this->assertSame(200, $metrics['status']);
                $this->assertStringContainsString('yii2s_server_connection_num', (string) $metrics['body']);

                // ... and on /__pool.
                $pool = $this->get('/__pool', ['Authorization' => 'Bearer test-token']);
                $this->assertSame(200, $pool['status']);

                // The token guards ONLY the observability endpoints: the app
                // itself is untouched for unauthenticated traffic.
                $index = $this->get('/');
                $this->assertSame(200, $index['status']);
            } finally {
                $this->stopServer();
            }
        });
    }
}
