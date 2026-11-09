<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use Swoole\Coroutine;
use Yii;
use Yii2S\Swoole\Coroutine\CoroutineContext;
use yii\web\Application;

/**
 * Web application whose request-scoped components are resolved per coroutine.
 *
 * A resident application normally caches every component instance, so two
 * concurrent requests would share the same `request`, `response`, `session`
 * and `user` objects: one coroutine would overwrite the request the other one
 * is reading (and could even observe its session/identity). Rebuilding those
 * components only after a request is not enough - the race happens while both
 * requests are in flight.
 *
 * This subclass resolves the request-scoped ids from the coroutine context
 * instead of the shared component cache, creating one instance per coroutine
 * from the configured definition. Everything else (db, redis, cache, pools,
 * log, ...) stays shared, which is what makes the server fast.
 *
 * `view` and `errorHandler` are in that set because a resident worker renders
 * and reports errors for several requests at once. A shared view leaks
 * `title` / `params` / asset registrations across those requests, and a shared
 * error handler leaks `exception` into another request's error page.
 *
 * Applications are instantiated as this class by
 * [[\Yii2S\Swoole\Server\RequestDispatcher]] when they do not configure their
 * own application class.
 */
class CoroutineApplication extends Application
{
    /**
     * Component ids that hold per-request state.
     */
    public const REQUEST_SCOPED_COMPONENTS = ['request', 'response', 'session', 'user', 'view', 'errorHandler'];

    private const CONTEXT_KEY_PREFIX = 'yii2s.app.component.';

    /**
     * @var array<string, object> configured prototypes for the two hot
     * request-scoped components; each request gets a cheap `clone` instead of
     * a full DI build (~1.5µs each).
     */
    private array $scopedPrototypes = [];

    /**
     * Drops the instance cached for the current coroutine, so the next
     * [[get()]] recreates it from the (possibly just updated) definition.
     *
     * Used by [[\Yii2S\Swoole\Bootstrap]]: `yii\web\Application::bootstrap()`
     * resolves `request` before the component classes are swapped, and that
     * stock instance must not survive into the request.
     */
    public function forgetComponent(string $id): void
    {
        CoroutineContext::remove(self::CONTEXT_KEY_PREFIX . $id);
        // The prototype was built from the pre-swap definition; drop it so the
        // next get() rebuilds it from the (now coroutine) class.
        unset($this->scopedPrototypes[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function get($id, $throwException = true)
    {
        if (!in_array($id, self::REQUEST_SCOPED_COMPONENTS, true) || Coroutine::getCid() === -1) {
            return parent::get($id, $throwException);
        }

        $key = self::CONTEXT_KEY_PREFIX . $id;
        $component = CoroutineContext::get($key);
        if ($component !== null) {
            return $component;
        }

        $definitions = $this->getComponents(true);
        $definition = $definitions[$id] ?? null;
        if (!is_array($definition) && !is_string($definition) && !$definition instanceof \Closure) {
            // Closures/objects were never supported by the shared cache path
            // here; let the parent produce its usual error or instance.
            return parent::get($id, $throwException);
        }

        $component = null;
        if (($id === 'request' || $id === 'response') && is_array($definition) && isset($definition['class'])) {
            try {
                $prototype = $this->scopedPrototypes[$id] ??= Yii::createObject($definition);
                $component = clone $prototype;
                if ($component instanceof \yii\web\Response) {
                    // Shallow clone shares the header/cookie collections and
                    // keeps the previous status; clear() resets all of it.
                    $component->clear();
                }
            } catch (\Throwable) {
                $component = null;
            }
        }

        $component ??= Yii::createObject($definition);
        CoroutineContext::set($key, $component);

        return $component;
    }

    /**
     * Loads the logged-in identity before controller filters run.
     *
     * `yii\filters\RateLimiter` calls `getIdentity(false)`, which does not
     * open the session. Touching the identity here, only when the request
     * already carries a session id, makes that filter see the same user the
     * action will see.
     */
    public function restoreIdentity(): void
    {
        $definition = $this->getComponents(true)['user'] ?? null;
        if (!is_array($definition) || empty($definition['identityClass'])) {
            return;
        }

        try {
            $session = $this->getSession();
            if (!$session->getHasSessionId() && !$session->getIsActive()) {
                return;
            }
            $this->getUser()->getIdentity();
        } catch (\Throwable $e) {
            Yii::warning('Failed to restore the user identity: ' . $e->getMessage(), __METHOD__);
        }
    }
}
