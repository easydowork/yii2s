<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Pools;

use yii\web\Application as WebApplication;

/**
 * Discovers pooled application components.
 *
 * Components are matched by their configured class implementing
 * [[HasConnectionPool]] so that only pooled components are instantiated.
 */
final class PoolMetricsCollector
{
    /**
     * @param WebApplication $app
     * @return array<string, ConnectionPool> component id => pool
     */
    public static function collect(WebApplication $app): array
    {
        $pools = [];
        foreach ($app->getComponents(true) as $id => $definition) {
            $class = is_array($definition) ? ($definition['class'] ?? null) : null;
            if (!is_string($class) || !is_a($class, HasConnectionPool::class, true)) {
                continue;
            }

            try {
                $component = $app->get($id);
            } catch (\Throwable) {
                continue;
            }
            if ($component instanceof HasConnectionPool) {
                $pools[$id] = $component->getPool();
            }
        }

        return $pools;
    }
}
