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
use Yii2S\Swoole\Server\StaticFileServer;

/**
 * Serves static files from a document root (PHP-level, since Swoole ignores
 * `enable_static_handler`). No-op when the root is null.
 */
final class StaticFileMiddleware implements Middleware
{
    private ?StaticFileServer $server = null;

    public function __construct(private ?string $root)
    {
    }

    public function process(Request $request, Response $response, string $path): bool
    {
        if ($this->root === null) {
            return false;
        }

        $this->server ??= new StaticFileServer($this->root);

        return $this->server->serve($request, $response, $path);
    }
}
