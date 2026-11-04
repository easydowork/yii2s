<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server\Middleware;

use Swoole\Http\Response;
use Yii2S\Swoole\Pools\ConnectionPool;

/**
 * Serves the connection pool metrics debug endpoint. Only reads pool state and
 * never borrows a connection.
 */
final class PoolMetricsMiddleware extends AbstractMetricsMiddleware
{
    /**
     * @param \Closure(): array<string, ConnectionPool> $poolCollector
     */
    public function __construct(
        bool $enabled,
        string $path,
        \Closure $poolCollector,
    ) {
        parent::__construct($enabled, $path, $poolCollector, true);
    }

    /**
     * @param array<string, mixed> $pools
     */
    protected function render(Response $response, array $pools): bool
    {
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
