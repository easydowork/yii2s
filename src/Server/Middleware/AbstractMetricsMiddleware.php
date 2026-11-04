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

/**
 * Base for the metrics endpoints: path matching and pool-metrics collection.
 * Subclasses only decide the output format (Prometheus text vs JSON).
 */
abstract class AbstractMetricsMiddleware implements Middleware
{
    /**
     * @param \Closure(): array<string, ConnectionPool> $poolCollector
     * @param bool $keepPoolErrors whether a broken pool is reported as an error
     * payload (rather than skipped)
     */
    public function __construct(
        protected bool $enabled,
        protected string $path,
        protected \Closure $poolCollector,
        protected bool $keepPoolErrors = false,
    ) {
    }

    public function process(Request $request, Response $response, string $path): bool
    {
        if (!$this->enabled || rtrim($path, '/') !== $this->path) {
            return false;
        }

        return $this->render($response, $this->collectPools());
    }

    /**
     * @return array<string, mixed> pool id => metrics (or error payload)
     */
    protected function collectPools(): array
    {
        $pools = [];
        foreach (($this->poolCollector)() as $id => $pool) {
            try {
                $pools[$id] = $pool->getMetrics();
            } catch (Throwable $e) {
                if ($this->keepPoolErrors) {
                    $pools[$id] = ['error' => $e->getMessage()];
                }
            }
        }

        return $pools;
    }

    /**
     * @param array<string, mixed> $pools
     */
    abstract protected function render(Response $response, array $pools): bool;
}
