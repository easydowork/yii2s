<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Yii2S\Swoole\Coroutine\CoroutineContext;

/**
 * Single source of truth for the "handled status" of middleware short-circuits.
 *
 * Swoole\Http\Response cannot be read back after status()/end(), so every
 * middleware that short-circuits outside the Yii lifecycle (static files, raw
 * routes, the observability gate) reports the real status here and
 * RequestDispatcher::dispatch() consumes it in its finally block - guaranteeing
 * exactly one record() per request with the true status and wall duration.
 *
 * All state lives in the coroutine context, so concurrent requests cannot
 * interfere.
 */
final class HandledStatus
{
    private const CONTEXT_KEY = 'yii2s:handled-status';

    /**
     * Reports the status a short-circuiting middleware answered with. Only
     * statuses that differ from the dispatcher's 200 default NEED reporting
     * (401, 404, 206, 500, ...).
     */
    public static function report(int $status): void
    {
        CoroutineContext::set(self::CONTEXT_KEY, $status);
    }

    /**
     * Consumes the reported status (and clears it for the next request in the
     * same worker). Returns null when no short-circuit reported - the caller
     * then falls back to its own status source.
     */
    public static function consume(): ?int
    {
        $status = CoroutineContext::get(self::CONTEXT_KEY);
        if ($status === null) {
            return null;
        }

        CoroutineContext::remove(self::CONTEXT_KEY);

        return (int) $status;
    }
}
