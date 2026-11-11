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
    /** @var string|null bearer token for this endpoint; null = public (backwards compatible) */
    public ?string $adminToken = null;

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

    /** @var \Yii2S\Swoole\Server\RequestMetrics|null set by RequestDispatcher: 401 short-circuits report themselves here */
    public ?\Yii2S\Swoole\Server\RequestMetrics $requestMetrics = null;

    public function process(Request $request, Response $response, string $path): bool
    {
        if (!$this->enabled || rtrim($path, '/') !== $this->path) {
            return false;
        }

        // Single-source access gate. The bearer token is checked HERE, with
        // the exact same path matching as above - a second, divergent
        // comparison elsewhere (HttpServer) drifted out of sync and let
        // //metrics and /metrics/ disclose metrics unauthenticated. Empty
        // token keeps the endpoint public (backwards compatible).
        $auth = (string) ($request->header['authorization'] ?? '');
        if ($this->adminToken !== null && $this->adminToken !== ''
            && !hash_equals('Bearer ' . $this->adminToken, $auth)
        ) {
            $response->status(401);
            $response->end();
            // Swoole\Http\Response cannot be read back, so the rejected
            // scrape reports its own 401 (otherwise every gate rejection
            // would be silently counted as HTTP 200 in the metrics).
            $this->requestMetrics?->record(
                0.0,
                401,
                strtoupper((string) ($request->server['request_method'] ?? 'GET'))
            );
            return true; // handled: nothing is disclosed
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
