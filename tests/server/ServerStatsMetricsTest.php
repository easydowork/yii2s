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
                'rawRoutes' => [
                    ['method' => 'GET', 'path' => '/boom-raw', 'type' => 'throw', 'message' => 'boom'],
                ],
                'server' => [
                    'adminToken' => 'test-token',
                    'documentRoot' => __DIR__ . '/../data/web',
                ],
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

                // Path normalization: trailing slash, duplicate slashes and
                // query variants used to BYPASS the token (the middleware
                // trims the path, the gate did not) and disclosed metrics.
                // Trailing slash: the middleware matches it (rtrim), so it
                // is gated exactly like the base path - 401 without token,
                // real metrics with token.
                $gated = $this->get('/metrics/');
                $this->assertSame(401, $gated['status'], 'trailing slash must stay gated');
                $authed = $this->get('/metrics/', ['Authorization' => 'Bearer test-token']);
                $this->assertSame(200, $authed['status']);
                $this->assertStringContainsString('yii2s_server_connection_num', (string) $authed['body']);

                // Duplicate slashes do not match the metrics middleware, so
                // they fall through to the Yii router and must never contain
                // metric content (with or without a token).
                $dup = $this->get('//metrics', ['Authorization' => 'Bearer test-token']);
                $this->assertStringNotContainsString(
                    'yii2s_server_connection_num',
                    (string) $dup['body'],
                    'duplicate-slash variants must not disclose metrics'
                );

                // Query variants ARE the same endpoint (the middleware strips
                // the query): the token gate applies - 401 without token.
                $qgated = $this->get('/metrics?x=1');
                $this->assertSame(401, $qgated['status'], 'query variant must stay gated');

                // Unified record(): a gate rejection is counted with its REAL
                // status (it used to be recorded as HTTP 200), and every
                // beginRequest() is paired by exactly one record() - so
                // in_progress must stay STABLE across scrapes (a leak would
                // grow it monotonically). The scrape itself is 1 while it is
                // the request being served.
                $inProgress = static function (string $body): string {
                    return (string) (preg_match('/yii2s_http_requests_in_progress (\d+)/', $body, $m) ? $m[1] : 'missing');
                };
                $first = $this->get('/metrics', ['Authorization' => 'Bearer test-token']);
                $v1 = $inProgress((string) $first['body']);
                $this->assertNotSame('missing', $v1);
                $this->assertStringContainsString(
                    'yii2s_http_requests_total{method="GET",status="401"}',
                    (string) $first['body'],
                    'the 401 gate rejection must have its own status series'
                );
                $second = $this->get('/metrics', ['Authorization' => 'Bearer test-token']);
                $this->assertSame($v1, $inProgress((string) $second['body']), 'in_progress must stay paired across requests');

                // Static Range request -> 206, raw-route exception -> 500: the
                // unified record() must give every short-circuit its own series.
                $range = $this->get('/test.txt', ['Authorization' => 'Bearer test-token', 'Range' => 'bytes=0-4']);
                $this->assertSame(206, $range['status']);
                $boom = $this->get('/boom-raw', ['Authorization' => 'Bearer test-token']);
                $this->assertSame(500, $boom['status']);

                // Mixed short-circuits: in_progress stays paired across the mix,
                // and every reported status has its own series.
                $mixed = $this->get('/metrics', ['Authorization' => 'Bearer test-token']);
                $mixedBody = (string) $mixed['body'];
                foreach (['206', '401', '500'] as $status) {
                    $this->assertStringContainsString("status=\"{$status}\"", $mixedBody, "series for {$status} must exist");
                }
                $this->assertSame($v1, $inProgress($mixedBody), 'in_progress must stay paired across mixed short-circuits');

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
