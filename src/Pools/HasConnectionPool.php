<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Pools;

/**
 * Implemented by application components that manage a [[ConnectionPool]].
 *
 * It lets infrastructure code (pool pre-warming, the /__pool metrics debug
 * endpoint) discover pooled components without hard-coding their class names
 * and without instantiating unrelated components.
 */
interface HasConnectionPool
{
    public function getPool(): ConnectionPool;
}
