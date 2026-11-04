<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server\Middleware;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;
use Yii2S\Swoole\Pools\ConnectionPool;
use Yii2S\Swoole\Server\MetricsAggregator;
use Yii2S\Swoole\Server\RequestMetrics;

/**
 * Serves the Prometheus metrics endpoint. Only reads counters and pool state
 * and never borrows a connection.
 */
final class MetricsMiddleware implements Middleware
{
    /**
     * @param \Closure(): array<string, ConnectionPool> $poolCollector
     */
    public function __construct(
        private bool $enabled,
        private string $path,
        private \Closure $poolCollector,
        private RequestMetrics $requestMetrics,
        private ?MetricsAggregator $aggregator = null,
    ) {
    }

    public function process(Request $request, Response $response, string $path): bool
    {
        if (!$this->enabled || rtrim($path, '/') !== $this->path) {
            return false;
        }

        $pools = [];
        foreach (($this->poolCollector)() as $id => $pool) {
            try {
                $pools[$id] = $pool->getMetrics();
            } catch (Throwable) {
                // A broken pool must not break the metrics endpoint.
            }
        }

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

            $response->end($body);
        }

        return true;
    }
}
