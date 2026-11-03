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
 * This subclass resolves the four request-scoped ids from the coroutine
 * context instead of the shared component cache, creating one instance per
 * coroutine from the configured definition. Everything else (db, redis,
 * cache, pools, log, ...) stays shared, which is what makes the server fast.
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
    public const REQUEST_SCOPED_COMPONENTS = ['request', 'response', 'session', 'user'];

    private const CONTEXT_KEY_PREFIX = 'yii2s.app.component.';

    /**
     * Drops the instance cached for the current coroutine, so the next
     * [[get()]] recreates it from the (possibly just updated) definition.
     *
     * Used by [[\Yii2S\Swoole\Bootstrap]]: `yii\web\Application::bootstrap()`
     * resolves `request` before the component classes are swapped, and that
     * stock instance must not survive into the request.
     */
    public static function forgetComponent(string $id): void
    {
        CoroutineContext::remove(self::CONTEXT_KEY_PREFIX . $id);
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

        $component = Yii::createObject($definition);
        CoroutineContext::set($key, $component);

        return $component;
    }
}
