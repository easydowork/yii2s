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
use yii\web\Application as WebApplication;

/**
 * Application bootstrap: wires the coroutine components, registers the
 * `swoole` and `queue` console commands and applies process-level settings.
 *
 * With [[autoReplaceComponents]] enabled (the default), a web application
 * only needs the bootstrap entry to run on Swoole: stock component classes
 * are replaced by their coroutine counterparts (keeping every configured
 * option) and a default `swooleHttpServer` component is registered when the
 * application does not define one.
 */
class Bootstrap implements BootstrapInterface
{
    public const DEFAULT_COMPONENT_ID = 'swooleHttpServer';

    public string $componentId = self::DEFAULT_COMPONENT_ID;

    /** @var string application component id of the queue, if any */
    public string $queueComponentId = 'queue';

    /** @var string memory_limit applied for the resident server process */
    public string $memoryLimit = '512M';

    /**
     * @var bool whether to replace stock Yii2 component classes with their
     * coroutine counterparts (keeping all configured options) and to register
     * a default HTTP server component. Only classes the application actually
     * uses are touched, explicit application configuration always wins.
     */
    public bool $autoReplaceComponents = true;

    /**
     * Component class replacements: stock Yii2 class => coroutine class.
     * The `redis` replacement only applies when yiisoft/yii2-redis is loaded.
     *
     * @var array<string, array<string, class-string>>
     */
    private const COMPONENT_REPLACEMENTS = [
        'request' => ['yii\web\Request' => Runtime\CoroutineRequest::class],
        'response' => ['yii\web\Response' => Runtime\CoroutineResponse::class],
        'db' => ['yii\db\Connection' => Db\CoroutineDbConnection::class],
        'redis' => ['yii\redis\Connection' => Redis\CoroutineRedisConnection::class],
    ];

    public function bootstrap($app): void
    {
        $memoryLimit = getenv('SWOOLE_MEMORY_LIMIT') ?: $this->memoryLimit;
        if ($memoryLimit) {
            ini_set('memory_limit', (string) $memoryLimit);
        }

        Yii::setAlias('@yii2s/swoole', dirname(__DIR__));

        if ($app instanceof WebApplication) {
            $this->ensureHttpServer($app);
        }

        if ($this->autoReplaceComponents) {
            $this->replaceComponents($app);
        }

        if ($app instanceof ConsoleApplication) {
            $this->registerConsoleControllers($app);
        }
    }

    /**
     * Registers a default HTTP server component for web applications that
     * do not define one, so `php yii swoole/start` works out of the box.
     */
    private function ensureHttpServer(WebApplication $app): void
    {
        if ($app->has($this->componentId) || isset($app->components[$this->componentId])) {
            return;
        }

        $webConfig = Yii::getAlias('@app/config/web.php');
        if (!is_file($webConfig)) {
            return;
        }

        $app->set($this->componentId, [
            'class' => Server\HttpServer::class,
            'dispatcher' => new Server\RequestDispatcher($webConfig),
        ]);
    }

    /**
     * Replaces stock Yii2 component classes with the coroutine counterparts.
     * Only array definitions whose class matches the stock class are touched;
     * anything the application configured explicitly keeps working as-is.
     * Web core components (request/response) always carry their stock class
     * from coreComponents(), so no default-class fallback is needed here.
     */
    private function replaceComponents($app): void
    {
        foreach (self::COMPONENT_REPLACEMENTS as $id => $map) {
            $definition = $app->components[$id] ?? null;
            if (!is_array($definition)) {
                continue; // string ids and closures are left to the application
            }

            $class = $definition['class'] ?? null;
            if (!is_string($class) || !isset($map[$class])) {
                continue;
            }

            $definition['class'] = $map[$class];
            $app->set($id, $definition);
        }
    }

    /**
     * Registers the `swoole` and `queue` console command controllers. The
     * server command is always available (a server component is registered
     * on demand); the queue command only when a queue component exists.
     */
    private function registerConsoleControllers(ConsoleApplication $app): void
    {
        if (!isset($app->controllerMap['swoole'])) {
            if (!$app->has($this->componentId)) {
                // Console-only application (no web config next to it): register
                // a bare server component so the commands at least resolve and
                // produce a meaningful error instead of a missing component.
                $app->set($this->componentId, ['class' => Server\HttpServer::class]);
            }
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
