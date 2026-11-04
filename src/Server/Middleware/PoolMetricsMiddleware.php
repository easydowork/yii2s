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
 * Serves the connection pool metrics debug endpoint. Only reads pool state and
 * never borrows a connection.
 */
final class PoolMetricsMiddleware implements Middleware
{
    /**
     * @param \Closure(): array<string, ConnectionPool> $poolCollector
     */
    public function __construct(
        private bool $enabled,
        private string $path,
        private \Closure $poolCollector,
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
            } catch (Throwable $e) {
                $pools[$id] = ['error' => $e->getMessage()];
            }
        }

        if ($response->isWritable()) {
            $response->status(200);
            $response->header('Content-Type', 'application/json');
            $response->end((string) json_encode(
                ['pools' => $pools],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));
        }

        return true;
    }
}
