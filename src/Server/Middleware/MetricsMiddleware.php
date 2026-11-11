<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server\Middleware;

use Swoole\Http\Response;
use Throwable;
use Yii2S\Swoole\Pools\ConnectionPool;
use Yii2S\Swoole\Server\MetricsAggregator;
use Yii2S\Swoole\Server\RequestMetrics;

/**
 * Serves the Prometheus metrics endpoint. Only reads counters and pool state
 * and never borrows a connection.
 */
final class MetricsMiddleware extends AbstractMetricsMiddleware
{
    /**
     * @param \Closure(): array<string, ConnectionPool> $poolCollector
     * @param \Closure(): ?array<string, int>|null $serverStatsProvider optional
     * kernel-level `Swoole\Server->stats()` provider (connection_num,
     * abort_count, coroutine_num, ...). These are global counters shared by
     * all workers, so they are appended once per scrape and never aggregated.
     */
    public function __construct(
        bool $enabled,
        string $path,
        \Closure $poolCollector,
        RequestMetrics $requestMetrics,
        private ?MetricsAggregator $aggregator = null,
        private ?\Closure $serverStatsProvider = null,
    ) {
        parent::__construct($enabled, $path, $poolCollector, false);
        // Assign the parent's public property: a promoted private here would
        // override AbstractMetricsMiddleware::$requestMetrics with an
        // incompatible visibility/type.
        $this->requestMetrics = $requestMetrics;
    }

    /**
     * @param array<string, mixed> $pools
     */
    protected function render(Response $response, array $pools): bool
    {
        if ($response->isWritable()) {
            $response->status(200);
            $response->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

            if ($this->aggregator !== null) {
                try {
                    $body = $this->aggregator->render($this->requestMetrics, $pools);
                } catch (Throwable) {
                    // Degraded: the shared store is unavailable, fall back to
                    // this worker's metrics instead of failing the scrape.
                    $body = $this->requestMetrics->render($pools);
                }
            } else {
                $body = $this->requestMetrics->render($pools);
            }

            $body .= $this->renderServerStats();

            $response->end($body);
        }

        return true;
    }

    /**
     * Appends the kernel-level gauges/counters from `Swoole\Server->stats()`.
     * They are not aggregated across workers on purpose: these numbers are
     * already global (shared server state), so every scrape carries one
     * consistent copy.
     */
    private function renderServerStats(): string
    {
        if ($this->serverStatsProvider === null) {
            return '';
        }

        try {
            $stats = ($this->serverStatsProvider)();
        } catch (Throwable) {
            // stats() can fail while the server is shutting down; a metrics
            // scrape must never become the thing that breaks a request.
            return '';
        }

        if ($stats === null) {
            return '';
        }

        $gauges = [
            'connection_num' => ['yii2s_server_connection_num', 'gauge'],
            'abort_count' => ['yii2s_server_abort_count', 'counter'],
            'accept_count' => ['yii2s_server_accept_count', 'counter'],
            'close_count' => ['yii2s_server_close_count', 'counter'],
            'request_count' => ['yii2s_server_request_count', 'counter'],
            'response_count' => ['yii2s_server_response_count', 'counter'],
            'coroutine_num' => ['yii2s_server_coroutine_num', 'gauge'],
            'total_recv_bytes' => ['yii2s_server_total_recv_bytes', 'counter'],
            'total_send_bytes' => ['yii2s_server_total_send_bytes', 'counter'],
        ];

        // Re-type into a generic map: the ide-helper declares stats() with a
        // fixed shape (all keys always present), which would make the runtime
        // guards below look like dead code to the analyser.
        /** @var array<string, mixed> $values */
        $values = $stats;

        $lines = [];
        foreach ($gauges as $statKey => [$metric, $type]) {
            if (!isset($values[$statKey]) || !is_numeric($values[$statKey])) {
                continue;
            }
            $lines[] = "# HELP {$metric} Swoole server {$statKey}.";
            $lines[] = "# TYPE {$metric} {$type}";
            $lines[] = "{$metric} " . (string) $values[$statKey];
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }
}
