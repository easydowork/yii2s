<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Dispatches a Swoole HTTP request through the Yii2 application.
 */
interface RequestDispatcherInterface
{
    public function dispatch(Request $request, Response $response, Server $server): void;

    /**
     * Warms up resources (e.g. connection pools) before the server starts
     * accepting traffic. Implementations may be no-op.
     *
     * @param int $connections how many connections to open per pool (0 only
     * instantiates the pooled components)
     */
    public function prewarm(int $connections = 0): void;

    /**
     * Runs one pass of connection-pool upkeep (max-lifetime recycling, idle
     * keepalive, min-idle replenishment). Implementations may be a no-op.
     *
     * @return int total number of connections recycled during this pass
     */
    public function maintainPools(): int;

    /**
     * Returns the registered raw routes, keyed by `METHOD path`.
     *
     * @return array<string, callable>
     */
    public function getRawRoutes(): array;

    /**
     * Returns the web application config file path, or null when the dispatcher
     * is not file-based.
     */
    public function getAppConfig(): ?string;
}
