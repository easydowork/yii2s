<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use Yii2S\Swoole\Server\MetricsAggregator;
use Yii2S\Swoole\Server\RequestMetrics;
use yii2stest\ServerTestCase;

/**
 * @group server
 * @group integration
 */
class RequestMetricsTest extends ServerTestCase
{
    public function testRecordAccumulatesCountersAndHistogram(): void
    {
        $metrics = new RequestMetrics();
        $metrics->beginRequest();
        $metrics->record(0.002, 200, 'GET');
        $metrics->beginRequest();
        $metrics->record(0.3, 200, 'GET');
        $metrics->beginRequest();
        $metrics->record(1.5, 500, 'POST');

        $out = $metrics->render();

        // Rate + status codes.
        $this->assertStringContainsString('yii2s_http_requests_total{method="GET",status="200"} 2', $out);
        $this->assertStringContainsString('yii2s_http_requests_total{method="POST",status="500"} 1', $out);

        // Histogram buckets are cumulative.
        $this->assertStringContainsString('yii2s_http_request_duration_seconds_bucket{method="GET",le="0.005"} 1', $out);
        $this->assertStringContainsString('yii2s_http_request_duration_seconds_bucket{method="GET",le="0.5"} 2', $out);
        $this->assertStringContainsString('yii2s_http_request_duration_seconds_bucket{method="GET",le="+Inf"} 2', $out);
        $this->assertStringContainsString('yii2s_http_request_duration_seconds_count{method="GET"} 2', $out);

        // The in-progress gauge returns to zero once every request is done.
        $this->assertStringContainsString('yii2s_http_requests_in_progress 0', $out);

        // Process metrics are always present.
        $this->assertStringContainsString('yii2s_process_memory_bytes', $out);
        $this->assertStringContainsString('yii2s_process_coroutines', $out);
    }

    public function testMetricsEndpointServesPrometheusFormat(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-pool-metrics.php', [
                'dispatcher' => ['enableMetricsEndpoint' => true],
            ]);
            try {
                // Generate one real request so the metrics have something to show.
                $this->get('/');

                $response = $this->http('GET', '/metrics');
                $this->assertSame(200, $response['status']);
                $this->assertStringContainsString('text/plain', (string) ($response['headers']['content-type'] ?? ''));

                $this->assertStringContainsString('yii2s_http_requests_total{method="GET",status="200"}', $response['body']);
                $this->assertStringContainsString('# TYPE yii2s_http_request_duration_seconds histogram', $response['body']);
                $this->assertStringContainsString('yii2s_pool_size{pool="redis"}', $response['body']);
                $this->assertStringContainsString('yii2s_process_memory_bytes', $response['body']);
            } finally {
                $this->stopServer();
            }
        });
    }

    public function testMetricsEndpointIsDisabledByDefault(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-pool-metrics.php');
            try {
                // Without the flag the path falls through to the Yii router.
                $response = $this->http('GET', '/metrics');
                $this->assertSame(404, $response['status']);
            } finally {
                $this->stopServer();
            }
        });
    }

    public function testAggregatorMergesWorkerSnapshots(): void
    {
        $redis = new CoroutineRedisConnection(['hostname' => '127.0.0.1', 'port' => 6379, 'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null]);
        $aggregator = new MetricsAggregator($redis, 'yii2s:test:metrics:');

        $this->runInCoroutine(function () use ($redis, $aggregator): void {
            foreach ($redis->keys('yii2s:test:metrics:*') as $key) {
                $redis->del($key);
            }

            $metrics = new RequestMetrics();
            $metrics->beginRequest();
            $metrics->record(0.01, 200, 'GET');

            $out = $aggregator->render($metrics, [
                'db' => ['size' => 1, 'idle' => 1, 'active' => 0, 'waiting' => 0, 'total_created' => 1, 'total_borrowed' => 1, 'total_timeouts' => 0, 'total_discarded' => 0],
            ]);

            $published = new RequestMetrics();
            $published->beginRequest();
            $published->record(0.01, 200, 'GET');
            $aggregator->publish($published, [
                'db' => ['size' => 1, 'idle' => 1, 'active' => 0, 'waiting' => 0, 'total_created' => 1, 'total_borrowed' => 1, 'total_timeouts' => 0, 'total_discarded' => 0],
            ]);
            $reader = new RequestMetrics();
            $seen = $aggregator->renderPublished($reader);
            $this->assertStringContainsString(
                'yii2s_http_requests_total{method="GET",status="200"} 1',
                $seen,
                'a worker that never served /metrics is still visible after publish()'
            );

            $this->assertStringContainsString('yii2s_http_requests_total{method="GET",status="200"} 1', $out);
            $this->assertStringContainsString('yii2s_process_memory_bytes{worker="', $out);
            $this->assertStringContainsString('yii2s_pool_size{pool="db"} 1', $out);

            $stale = 'yii2s:test:metrics:worker:999999';
            $redis->sAdd('yii2s:test:metrics:workers', $stale);
            $aggregator->render($metrics, []);
            $members = $redis->sMembers('yii2s:test:metrics:workers');
            $this->assertIsArray($members);
            $this->assertNotContains($stale, $members);
        });
    }
}
