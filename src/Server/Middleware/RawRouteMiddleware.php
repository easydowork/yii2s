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
use Yii;

/**
 * Answers raw routes: exact `METHOD /path` matches served without creating the
 * Yii request/response objects. See [[RequestDispatcher::addRawRoute()]].
 */
final class RawRouteMiddleware implements Middleware
{
    /**
     * @param array<string, callable> $rawRoutes keyed by "METHOD /path"
     */
    public function __construct(private array $rawRoutes)
    {
    }

    public function process(Request $request, Response $response, string $path): bool
    {
        $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
        $handler = $this->rawRoutes[$method . ' ' . $path] ?? null;
        if ($handler === null) {
            return false;
        }

        try {
            $result = $handler($request, $response);
            if (!$response->isWritable() || $result === null) {
                return true; // the handler owns the response
            }

            if (is_array($result)) {
                $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new \RuntimeException('Raw route returned data that cannot be JSON-encoded.');
                }
                $response->header('Content-Type', 'application/json; charset=UTF-8');
                $response->end($json);

                return true;
            }

            $response->header('Content-Type', 'text/plain; charset=UTF-8');
            $response->end(is_scalar($result) ? (string) $result : '');

            return true;
        } catch (Throwable $exception) {
            Yii::error('Raw route ' . $method . ' ' . $path . ' failed: ' . $exception->getMessage(), __METHOD__);
            if ($response->isWritable()) {
                $response->status(500);
                $response->header('Content-Type', 'text/plain; charset=UTF-8');
                $response->end('Internal Server Error');
            }

            return true;
        }
    }
}
