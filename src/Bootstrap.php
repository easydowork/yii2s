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
        Runtime\ClassMap::install();
        Log\CoroutineLogger::install();

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

        if ($app instanceof WebApplication) {
            $this->syncWebAliases($app);
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
            if ($class === null) {
                // Definitions without an explicit class key merge with
                // coreComponents() later and instantiate the stock class.
                // Treat them as stock-class definitions so they get the
                // coroutine replacement too (a definition that REALLY wants
                // another class states it explicitly and stays untouched).
                // Note: definitions carrying coroutine-only config keys (e.g.
                // trustedProxies on request) MUST declare the coroutine class
                // explicitly - web Application::bootstrap() resolves `request`
                // before extension bootstraps run, so the swap happens too
                // late for them.
                $class = array_key_first($map);
                $definition['class'] = $class;
            }
            if (!is_string($class) || !isset($map[$class])) {
                continue;
            }

            $definition['class'] = $map[$class];
            $app->set($id, $definition);

            // yii\web\Application::bootstrap() resolves `request` (and thus
            // caches a stock instance) before this bootstrap runs. Drop it so
            // the current request picks up the coroutine class as well.
            if ($app instanceof Runtime\CoroutineApplication) {
                $app->forgetComponent($id);
            }
        }
    }

    /**
     * Re-derives @webroot/@web after the component swap: the stock request
     * used by `yii\web\Application::bootstrap()` computes them from the
     * console entry script, which is the application root - not the web root.
     */
    private function syncWebAliases(WebApplication $app): void
    {
        $request = $app->getRequest();
        if (!$request instanceof Runtime\CoroutineRequest) {
            return;
        }

        Yii::setAlias('@webroot', dirname($request->getScriptFile()));
        Yii::setAlias('@web', $request->getBaseUrl());
    }

    /**
     * Registers the `swoole` and `queue` console command controllers. The
     * server command is always available (a server component is registered
     * on demand); the queue command only when a queue component exists.
     */
    private function registerConsoleControllers(ConsoleApplication $app): void
    {
        if (!isset($app->controllerMap['swoole'])) {
            if (!$app->has($this->componentId) && !isset($app->components[$this->componentId])) {
                // Prefer the web application's server definition so console
                // commands address the very same server (documentRoot,
                // dispatcher, watch settings...). Fall back to a bare
                // component for console-only applications, where the
                // HttpServer dispatcher default will produce a meaningful
                // error instead of a missing component.
                $app->set($this->componentId, $this->webServerDefinition() ?? [
                    'class' => Server\HttpServer::class,
                    'documentRoot' => '@app/web',
                ]);
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

    /**
     * The `swooleHttpServer` component definition from the web application
     * config (when that file exists next to @app/config), or null. Reading
     * it is safe: the server process already requires this file twice.
     */
    private function webServerDefinition(): ?array
    {
        $webConfig = Yii::getAlias('@app/config/web.php', false);
        if (!is_string($webConfig) || !is_file($webConfig)) {
            return null;
        }

        $config = require $webConfig;
        if (!is_array($config)) {
            return null;
        }

        $definition = $config['components'][$this->componentId] ?? null;

        return is_array($definition) ? $definition : null;
    }
}
