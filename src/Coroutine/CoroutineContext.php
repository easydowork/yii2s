<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Coroutine;

/**
 * Small helper for storing request-scoped values in the Swoole coroutine context.
 *
 * Values stored here are isolated per coroutine and garbage collected with the
 * coroutine, which makes them safe for long-lived server processes. Outside a
 * coroutine (e.g. plain CLI usage) values fall back to a process-local store.
 */
final class CoroutineContext
{
    private static array $fallback = [];

    public static function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        if ($context !== null) {
            $context[$key] = $value;
            return;
        }
        self::$fallback[$key] = $value;
    }

    public static function get(string $key): mixed
    {
        $context = \Swoole\Coroutine::getContext();
        if ($context !== null) {
            return $context[$key] ?? null;
        }
        return self::$fallback[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function remove(string $key): void
    {
        $context = \Swoole\Coroutine::getContext();
        if ($context !== null) {
            unset($context[$key]);
            return;
        }
        unset(self::$fallback[$key]);
    }
}
