<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use Yii;
use Yii2S\Swoole\Pools\HasConnectionPool;
use Yii2S\Swoole\Session\CoroutineSession;
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

        // Persist and unlock the session before pooled Redis connections are
        // returned. A session left open keeps its lock until the TTL.
        try {
            if ($app->has('session', true)) {
                $session = $app->get('session', false);
                if ($session instanceof CoroutineSession && $session->getIsActive()) {
                    $session->close();
                }
            }
        } catch (\Throwable $e) {
            Yii::error('Failed to close the session: ' . $e->getMessage(), __METHOD__);
        }

        // Clears $_FILES only when this call is outside a coroutine.
        // Coroutine requests never publish that table.
        CoroutineRequest::releaseUploadedFiles();

        // CoroutineApplication resolves request-scoped ids from the coroutine
        // context, so the shared definitions never hold a stale instance and
        // do not need to be reset on every request.
        if (!$app instanceof CoroutineApplication) {
            $definitions = $app->getComponents(true);
            foreach (CoroutineApplication::REQUEST_SCOPED_COMPONENTS as $id) {
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
                if ($component instanceof HasConnectionPool) {
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
            if (!$component instanceof HasConnectionPool) {
                continue;
            }

            try {
                $component->release();
            } catch (\Throwable $e) {
                Yii::error("Failed to release component '{$id}': " . $e->getMessage(), __METHOD__);
            }
            try {
                $component->getPool()->sweep();
            } catch (\Throwable $e) {
                Yii::error("Failed to sweep pool of component '{$id}': " . $e->getMessage(), __METHOD__);
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
