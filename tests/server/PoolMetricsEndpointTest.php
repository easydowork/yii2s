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
 * @group server
 * @group integration
 */
class PoolMetricsEndpointTest extends ServerTestCase
{
    public function testMetricsEndpointServesJsonWhenEnabled(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-pool-metrics.php', [
                'dispatcher' => ['enablePoolMetricsEndpoint' => true],
            ]);
            try {
                $response = $this->http('GET', '/__pool');
                $this->assertSame(200, $response['status']);
                $this->assertSame('application/json', (string) ($response['headers']['content-type'] ?? ''));

                $body = $response['json'];
                $this->assertIsArray($body);
                $this->assertArrayHasKey('pools', $body);
                $this->assertArrayHasKey('redis', $body['pools']);

                $redisMetrics = $body['pools']['redis'];
                $this->assertSame(5, $redisMetrics['max_active']);
                $this->assertSame(0, $redisMetrics['total_timeouts']);
                $this->assertFalse($redisMetrics['closed']);
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
                $response = $this->http('GET', '/__pool');
                $this->assertSame(404, $response['status']);
            } finally {
                $this->stopServer();
            }
        });
    }
}
