<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use Yii;
use yii\base\Application;
use yii\web\Application as WebApplication;

/**
 * Resets request-scoped state between HTTP requests in the resident process.
 *
 * The application instance lives for the whole server lifetime, but its
 * request-bound components (request, response, session, user) must be rebuilt
 * for every request, and pooled resources borrowed by the coroutine must be
 * returned.
 */
class RequestStateManager
{
    /**
     * Component ids that are recreated for every request.
     */
    public const REQUEST_SCOPED_COMPONENTS = ['request', 'response', 'session', 'user'];

    private static int $activeRequests = 0;

    /**
     * Captures component definitions right after the application is created so
     * they can be restored on cleanup.
     *
     * @return array<string, mixed> component definitions
     */
    public function captureDefinitions(WebApplication $app): array
    {
        return $app->getComponents(true);
    }

    /**
     * Marks the start of a request; paired with [[cleanup()]] in a
     * try/finally by [[\Yii2S\Swoole\Server\RequestDispatcher]].
     */
    public function beginRequest(): void
    {
        self::$activeRequests++;
    }

    /**
     * Number of requests currently being processed. Used by the server to
     * wait for in-flight requests during graceful shutdown.
     */
    public static function getActiveRequests(): int
    {
        return self::$activeRequests;
    }

    /**
     * Rebuilds request-scoped components and releases pooled resources.
     */
    public function cleanup(Application $app): void
    {
        self::$activeRequests--;
        $definitions = $app->getComponents(true);

        // Drop the per-request $_FILES population and UploadedFile's static
        // cache (see CoroutineRequest::setSwooleRequest()).
        $_FILES = [];
        if (class_exists(\yii\web\UploadedFile::class)) {
            \yii\web\UploadedFile::reset();
        }

        foreach (self::REQUEST_SCOPED_COMPONENTS as $id) {
            if (isset($definitions[$id])) {
                $app->set($id, $definitions[$id]);
            }
        }

        // Return pooled connections borrowed by the current coroutine, then
        // reclaim connections whose borrowing coroutine died during the
        // request (e.g. parallel query sub-coroutines) and would otherwise
        // leak.
        foreach ($app->getComponents(false) as $id => $definition) {
            try {
                $component = $app->get($id, false);
            } catch (\Throwable) {
                continue;
            }
            if (!is_object($component)) {
                continue;
            }

            if (method_exists($component, 'release')) {
                try {
                    $component->release();
                } catch (\Throwable $e) {
                    Yii::error("Failed to release component '{$id}': " . $e->getMessage(), __METHOD__);
                }
            }
            if (method_exists($component, 'getPool')) {
                try {
                    $component->getPool()->sweep();
                } catch (\Throwable $e) {
                    Yii::error("Failed to sweep pool of component '{$id}': " . $e->getMessage(), __METHOD__);
                }
            }
        }

        // Persist buffered log messages; the resident process never runs the
        // FPM-style end-of-request flush.
        Yii::getLogger()->flush(true);
    }
}
