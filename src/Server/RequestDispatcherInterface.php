<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Dispatches a Swoole HTTP request through the Yii2 application.
 */
interface RequestDispatcherInterface
{
    public function dispatch(Request $request, Response $response, SwooleCoroutineHttpServer $server): void;

    /**
     * Warms up resources (e.g. connection pools) before the server starts
     * accepting traffic. Implementations may be no-op.
     *
     * @param int $connections how many connections to open per pool (0 only
     * instantiates the pooled components)
     */
    public function prewarm(int $connections = 0): void;
}
