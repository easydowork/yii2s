<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Yii2S\Swoole\Pools\PoolMetricsCollector;
use Yii2S\Swoole\Runtime\CoroutineRequest;
use Yii2S\Swoole\Runtime\CoroutineResponse;
use Yii2S\Swoole\Runtime\RequestStateManager;
use Yii2S\Swoole\Runtime\SwooleResponseSender;
use Swoole\Coroutine\Http\Server as SwooleCoroutineHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\Application as WebApplication;

/**
 * Default dispatcher: converts Swoole requests to Yii2 requests, runs the
 * shared resident application instance and sends the Yii2 response back
 * through Swoole.
 *
 * The application is created once on the first request and reused for the
 * whole server lifetime. Request-bound state is rebuilt and pooled resources
 * are released after every request by [[RequestStateManager]].
 */
class RequestDispatcher implements RequestDispatcherInterface
{
    /**
     * @var string|null path to the Yii2 web application config file
     */
    public ?string $appConfig = null;

    /**
     * @var string|null document root for PHP-level static file serving
     * (supports Yii aliases); see [[StaticFileServer]]
     */
    public ?string $staticRoot = null;

    /**
     * @var bool whether to expose the connection pool metrics debug endpoint
     * ([[poolMetricsPath]]). Keep it disabled on public deployments or block
     * the path at the reverse proxy.
     */
    public bool $enablePoolMetricsEndpoint = false;

    /**
     * @var string request path of the pool metrics debug endpoint
     */
    public string $poolMetricsPath = '/__pool';

    private ?WebApplication $application = null;

    private ?StaticFileServer $staticFileServer = null;

    private SwooleResponseSender $responseSender;

    private RequestStateManager $stateManager;

    public function __construct(?string $appConfig = null, array $config = [])
    {
        $this->appConfig = $appConfig;
        $this->staticRoot = isset($config['staticRoot']) && is_string($config['staticRoot'])
            ? $config['staticRoot']
            : null;
        if ($this->staticRoot !== null && str_starts_with($this->staticRoot, '@')) {
            $this->staticRoot = Yii::getAlias($this->staticRoot);
        }
        $this->enablePoolMetricsEndpoint = (bool) ($config['enablePoolMetricsEndpoint'] ?? $this->enablePoolMetricsEndpoint);
        if (isset($config['poolMetricsPath']) && is_string($config['poolMetricsPath'])) {
            $this->poolMetricsPath = $config['poolMetricsPath'];
        }
        $this->responseSender = $config['responseSender'] ?? new SwooleResponseSender();
        $this->stateManager = $config['stateManager'] ?? new RequestStateManager();
    }

    /**
     * Enables PHP-level static file serving from the given document root.
     */
    public function setStaticRoot(?string $root): void
    {
        $this->staticRoot = $root;
        $this->staticFileServer = null;
    }

    /**
     * Serves the connection pool metrics debug endpoint when enabled. Runs
     * before the request enters the Yii lifecycle: it only reads pool state
     * and never borrows a connection.
     */
    private function tryServePoolMetrics(Request $swooleRequest, Response $swooleResponse): bool
    {
        if (!$this->enablePoolMetricsEndpoint) {
            return false;
        }

        $path = parse_url((string) ($swooleRequest->server['request_uri'] ?? ''), PHP_URL_PATH);
        if (!is_string($path) || rtrim($path, '/') !== $this->poolMetricsPath) {
            return false;
        }

        $pools = [];
        foreach (PoolMetricsCollector::collect($this->getApplication()) as $id => $pool) {
            try {
                $pools[$id] = $pool->getMetrics();
            } catch (\Throwable $e) {
                $pools[$id] = ['error' => $e->getMessage()];
            }
        }

        if ($swooleResponse->isWritable()) {
            $swooleResponse->status(200);
            $swooleResponse->header('Content-Type', 'application/json');
            $swooleResponse->end((string) json_encode(
                ['pools' => $pools],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));
        }

        return true;
    }

    /**
     * Attempts to serve the request as a static file.
     */
    private function tryServeStaticFile(Request $swooleRequest, Response $swooleResponse): bool
    {
        if ($this->staticRoot === null) {
            return false;
        }
        if ($this->staticFileServer === null) {
            $this->staticFileServer = new StaticFileServer($this->staticRoot);
        }

        return $this->staticFileServer->serve($swooleRequest, $swooleResponse);
    }

    public function dispatch(Request $swooleRequest, Response $swooleResponse, SwooleCoroutineHttpServer $server): void
    {
        if ($this->tryServeStaticFile($swooleRequest, $swooleResponse)) {
            return;
        }

        if ($this->tryServePoolMetrics($swooleRequest, $swooleResponse)) {
            return;
        }

        $app = $this->getApplication();
        $this->stateManager->beginRequest();

        try {
            $request = $app->getRequest();
            if ($request instanceof CoroutineRequest) {
                $request->setSwooleRequest($swooleRequest);
            }

            $response = $app->handleRequest($request);
            if ($response instanceof CoroutineResponse && !$response->isSent) {
                $response->finalize();
            }

            $this->responseSender->send($swooleResponse, $response);
        } catch (Throwable $exception) {
            $this->handleException($swooleResponse, $exception);
        } finally {
            $this->stateManager->cleanup($app);
        }
    }

    /**
     * Eagerly resolves pooled components so connection pools are created
     * before the first real request.
     */
    public function prewarm(): void
    {
        try {
            foreach (PoolMetricsCollector::collect($this->getApplication()) as $id => $pool) {
                try {
                    $pool->getMetrics(); // touch: validates the pool is usable
                } catch (Throwable $e) {
                    Yii::error("Failed to pre-warm component '{$id}': " . $e->getMessage(), __METHOD__);
                }
            }
        } catch (Throwable $e) {
            Yii::error('Connection pool pre-warming failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Creates the resident Yii2 application instance (once).
     */
    private function getApplication(): WebApplication
    {
        if ($this->application !== null) {
            return $this->application;
        }

        if ($this->appConfig === null) {
            throw new InvalidConfigException('Property "appConfig" must be configured with a Yii web application config file.');
        }

        $config = require $this->appConfig;
        if (!is_array($config)) {
            throw new InvalidConfigException('The application config must return an array.');
        }

        $class = $config['class'] ?? WebApplication::class;
        if (!is_string($class)) {
            throw new InvalidConfigException('Application class must be configured as a class name string.');
        }
        if (!is_a($class, WebApplication::class, true)) {
            throw new InvalidConfigException(sprintf('Application class "%s" must be a subclass of %s.', $class, WebApplication::class));
        }
        unset($config['class']);

        $this->application = new $class($config);

        return $this->application;
    }

    private function handleException(Response $swooleResponse, Throwable $exception): void
    {
        if (!$swooleResponse->isWritable()) {
            return;
        }

        $swooleResponse->status($this->resolveStatusCode($exception));
        $swooleResponse->header('Content-Type', 'text/plain; charset=UTF-8');

        if (YII_DEBUG) {
            $body = 'Error: ' . $exception->getMessage() . "\n\n"
                . 'Coroutine ID: ' . \Swoole\Coroutine::getCid() . "\n\n"
                . $exception->getTraceAsString();
        } else {
            $body = 'Internal Server Error';
        }

        $swooleResponse->end($body);
    }

    /**
     * Maps Yii2 HTTP exceptions to their status codes; everything else is a
     * 500.
     */
    private function resolveStatusCode(Throwable $exception): int
    {
        if ($exception instanceof \yii\web\HttpException) {
            return $exception->statusCode;
        }

        return 500;
    }
}
