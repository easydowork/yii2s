<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Coroutine;

/**
 * Sleeps, yielding to the scheduler inside a coroutine and blocking otherwise
 * (`\Swoole\Coroutine::sleep()` throws outside a coroutine).
 */
final class Sleep
{
    public static function sleep(float $seconds): void
    {
        if (\Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
