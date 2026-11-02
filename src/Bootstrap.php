<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole;

use Yii;
use yii\base\BootstrapInterface;
use yii\console\Application as ConsoleApplication;

/**
 * Application bootstrap: registers the `swoole` and `queue` console commands
 * and applies process-level settings.
 */
class Bootstrap implements BootstrapInterface
{
    public const DEFAULT_COMPONENT_ID = 'swooleHttpServer';

    public string $componentId = self::DEFAULT_COMPONENT_ID;

    /** @var string application component id of the queue, if any */
    public string $queueComponentId = 'queue';

    /** @var string memory_limit applied for the resident server process */
    public string $memoryLimit = '512M';

    /** @var int coroutine hook flags (informational; HttpServer applies its own) */
    public int $hookFlags = SWOOLE_HOOK_ALL;

    public function bootstrap($app): void
    {
        $memoryLimit = getenv('SWOOLE_MEMORY_LIMIT') ?: $this->memoryLimit;
        if ($memoryLimit) {
            ini_set('memory_limit', (string) $memoryLimit);
        }

        Yii::setAlias('@yii2s/swoole', dirname(__DIR__));

        if (!$app instanceof ConsoleApplication) {
            return;
        }

        if (!isset($app->controllerMap['swoole']) && $app->has($this->componentId)) {
            $app->controllerMap['swoole'] = [
                'class' => Console\SwooleController::class,
                'serverComponent' => $this->componentId,
            ];
        }

        if (!isset($app->controllerMap['queue']) && $app->has($this->queueComponentId)) {
            $app->controllerMap['queue'] = [
                'class' => Console\QueueController::class,
                'queueComponent' => $this->queueComponentId,
            ];
        }
    }
}
