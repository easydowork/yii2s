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
     * @var \WeakMap<Application, array{count: int, ids: list<string>}>|null
     * cached "components that own a pool" per application, rebuilt whenever
     * the number of instantiated components changes
     */
    private ?\WeakMap $pooledComponents = null;

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

        // Drop the per-request $_FILES population and UploadedFile's static
        // cache (see CoroutineRequest::setSwooleRequest()) - only when this
        // request actually populated them; plain JSON requests skip it.
        if ($_FILES !== []) {
            $_FILES = [];
            if (class_exists(\yii\web\UploadedFile::class)) {
                \yii\web\UploadedFile::reset();
            }
        }

        // CoroutineApplication resolves these four ids from the coroutine
        // context, so the shared definitions never hold a stale instance and
        // do not need to be reset on every request.
        if (!$app instanceof CoroutineApplication) {
            $definitions = $app->getComponents(true);
            foreach (self::REQUEST_SCOPED_COMPONENTS as $id) {
                if (isset($definitions[$id])) {
                    $app->set($id, $definitions[$id]);
                }
            }
        }

        // Return pooled connections borrowed by the current coroutine, then
        // reclaim connections whose borrowing coroutine died during the
        // request (e.g. parallel query sub-coroutines) and would otherwise
        // leak. The id list is cached: the method_exists() scan only runs
        // again when a new component was instantiated.
        $components = $app->getComponents(false);
        $componentCount = count($components);
        $this->pooledComponents ??= new \WeakMap();
        $cache = $this->pooledComponents[$app] ?? null;
        if ($cache === null || $cache['count'] !== $componentCount) {
            $ids = [];
            foreach (array_keys($components) as $id) {
                try {
                    $component = $app->get($id, false);
                } catch (\Throwable) {
                    continue;
                }
                // Match connection-pool components by `getPool()` only. The
                // `release` method name is ambiguous: CoroutineRedisQueue has a
                // release() that re-queues a failed job (and requires a job
                // argument), so it must not be treated as a pooled component.
                if (is_object($component) && method_exists($component, 'getPool')) {
                    $ids[] = (string) $id;
                }
            }
            $cache = ['count' => $componentCount, 'ids' => $ids];
            $this->pooledComponents[$app] = $cache;
        }

        foreach ($cache['ids'] as $id) {
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
        // FPM-style end-of-request flush. Skipping the dispatch when nothing
        // was logged saves the whole target pipeline on quiet requests.
        $logger = Yii::getLogger();
        if ($logger->messages !== []) {
            $logger->flush(true);
        }
    }
}
