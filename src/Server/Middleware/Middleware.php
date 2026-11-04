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

/**
 * One pre-dispatch step in the request pipeline.
 *
 * A middleware answers a request directly (and short-circuits the chain) or
 * declines it (returning false) so the next step runs. These steps run before
 * the Yii request lifecycle: static files, raw routes and the metrics
 * endpoints never create the Yii request/response objects.
 */
interface Middleware
{
    /**
     * Processes the request.
     *
     * @param Request $request the raw Swoole request
     * @param Response $response the raw Swoole response
     * @param string $path the already-parsed request path (no query string)
     * @return bool true when the request was fully handled and the chain must
     * short-circuit; false to hand the request to the next middleware
     */
    public function process(Request $request, Response $response, string $path): bool;
}
