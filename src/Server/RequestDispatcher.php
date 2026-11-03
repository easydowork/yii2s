<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Yii2S\Swoole\Pools\PoolMetricsCollector;
use Yii2S\Swoole\Runtime\CoroutineApplication;
use Yii2S\Swoole\Runtime\CoroutineRequest;
use Yii2S\Swoole\Runtime\CoroutineResponse;
use Yii2S\Swoole\Runtime\RequestStateManager;
use Yii2S\Swoole\Runtime\SwooleResponseSender;
use Swoole\Http\Server as SwooleHttpServer;
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

    /**
     * @var bool whether to expose the Prometheus metrics endpoint
     * ([[metricsPath]]). Keep it disabled on public deployments or block the
     * path at the reverse proxy.
     */
    public bool $enableMetricsEndpoint = false;

    /**
     * @var string request path of the Prometheus metrics endpoint
     */
    public string $metricsPath = '/metrics';

    private ?WebApplication $application = null;

    private ?StaticFileServer $staticFileServer = null;

    private SwooleResponseSender $responseSender;

    private RequestStateManager $stateManager;

    private RequestMetrics $requestMetrics;

    /**
     * @var array<string, callable> raw routes keyed by "METHOD /path"
     */
    private array $rawRoutes = [];

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
        $this->enableMetricsEndpoint = (bool) ($config['enableMetricsEndpoint'] ?? $this->enableMetricsEndpoint);
        if (isset($config['metricsPath']) && is_string($config['metricsPath'])) {
            $this->metricsPath = $config['metricsPath'];
        }
        $this->responseSender = $config['responseSender'] ?? new SwooleResponseSender();
        $this->stateManager = $config['stateManager'] ?? new RequestStateManager();
        $this->requestMetrics = $config['requestMetrics'] ?? new RequestMetrics();
    }

    /**
     * Serves the connection pool metrics debug endpoint when enabled. Runs
     * before the request enters the Yii lifecycle: it only reads pool state
     * and never borrows a connection.
     */
    private function tryServePoolMetrics(Request $swooleRequest, Response $swooleResponse, ?string $path = null): bool
    {
        if (!$this->enablePoolMetricsEndpoint) {
            return false;
        }

        if ($path === null) {
            $parsed = parse_url((string) ($swooleRequest->server['request_uri'] ?? ''), PHP_URL_PATH);
            if (!is_string($parsed)) {
                return false;
            }
            $path = $parsed;
        }
        if (rtrim($path, '/') !== $this->poolMetricsPath) {
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
     * Serves the Prometheus metrics endpoint when enabled. Runs before the
     * request enters the Yii lifecycle; it only reads counters and pool state
     * and never borrows a connection.
     */
    private function tryServeMetrics(Request $swooleRequest, Response $swooleResponse, ?string $path = null): bool
    {
        if (!$this->enableMetricsEndpoint) {
            return false;
        }
        if ($path === null) {
            $parsed = parse_url((string) ($swooleRequest->server['request_uri'] ?? ''), PHP_URL_PATH);
            if (!is_string($parsed)) {
                return false;
            }
            $path = $parsed;
        }
        if (rtrim($path, '/') !== $this->metricsPath) {
            return false;
        }

        $pools = [];
        foreach (PoolMetricsCollector::collect($this->getApplication()) as $id => $pool) {
            try {
                $pools[$id] = $pool->getMetrics();
            } catch (\Throwable) {
                // A broken pool must not break the metrics endpoint.
            }
        }

        if ($swooleResponse->isWritable()) {
            $swooleResponse->status(200);
            $swooleResponse->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
            $swooleResponse->end($this->requestMetrics->render($pools));
        }

        return true;
    }

    /**
     * Attempts to serve the request as a static file.
     */
    private function tryServeStaticFile(Request $swooleRequest, Response $swooleResponse, ?string $path = null): bool
    {
        if ($this->staticRoot === null) {
            return false;
        }
        if ($this->staticFileServer === null) {
            $this->staticFileServer = new StaticFileServer($this->staticRoot);
        }

        return $this->staticFileServer->serve($swooleRequest, $swooleResponse, $path);
    }

    /**
     * Registers a raw route: an exact `METHOD /path` match that is answered
     * without creating the Yii request/response objects.
     *
     * The handler receives the Swoole request/response and may return:
     *  - a string  -> text/plain response body
     *  - an array  -> JSON response body
     *  - null      -> the handler already wrote the response itself
     *
     * Raw routes bypass routing, session, CSRF and user authentication. Use
     * them for public/stateless endpoints only (health checks, metrics, tiny
     * read-only APIs); anything that needs the framework belongs in Yii.
     *
     * @param callable(Request, Response): (string|array|null) $handler
     */
    public function addRawRoute(string $method, string $path, callable $handler): self
    {
        $this->rawRoutes[strtoupper($method) . ' ' . $path] = $handler;

        return $this;
    }

    /**
     * @return array<string, callable>
     */
    public function getRawRoutes(): array
    {
        return $this->rawRoutes;
    }

    /**
     * @return RequestMetrics the request metrics collector (for tests/inspection)
     */
    public function getRequestMetrics(): RequestMetrics
    {
        return $this->requestMetrics;
    }

    /**
     * Answers a registered raw route. Returns false when the request is not
     * a raw route and the normal Yii pipeline should handle it.
     */
    private function tryRawRoute(Request $request, Response $response, ?string $path = null): bool
    {
        if ($path === null) {
            $uri = (string) ($request->server['request_uri'] ?? '');
            $parsed = parse_url($uri, PHP_URL_PATH);
            if (!is_string($parsed)) {
                return false;
            }
            $path = $parsed;
        }

        $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
        $handler = $this->rawRoutes[$method . ' ' . $path] ?? null;
        if ($handler === null) {
            return false;
        }

        try {
            $result = $handler($request, $response);
            if (!$response->isWritable() || $result === null) {
                return true; // the handler owns the response
            }

            if (is_array($result)) {
                $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new \RuntimeException('Raw route returned data that cannot be JSON-encoded.');
                }
                $response->header('Content-Type', 'application/json; charset=UTF-8');
                $response->end($json);

                return true;
            }

            $response->header('Content-Type', 'text/plain; charset=UTF-8');
            $response->end(is_scalar($result) ? (string) $result : '');

            return true;
        } catch (Throwable $exception) {
            Yii::error('Raw route ' . $method . ' ' . $path . ' failed: ' . $exception->getMessage(), __METHOD__);
            if ($response->isWritable()) {
                $response->status(500);
                $response->header('Content-Type', 'text/plain; charset=UTF-8');
                $response->end('Internal Server Error');
            }

            return true;
        }
    }

    public function dispatch(Request $swooleRequest, Response $swooleResponse, SwooleHttpServer $server): void
    {
        $start = hrtime(true);
        $this->requestMetrics->beginRequest();
        $statusCode = 200;

        try {
            // Parse the path once for the pre-Yii handlers; when none of them
            // is enabled the parse is skipped entirely.
            $path = null;
            if ($this->rawRoutes !== [] || $this->staticRoot !== null || $this->enablePoolMetricsEndpoint || $this->enableMetricsEndpoint) {
                $parsed = parse_url((string) ($swooleRequest->server['request_uri'] ?? ''), PHP_URL_PATH);
                $path = is_string($parsed) ? $parsed : null;
            }

            // Static files and the metrics endpoints run outside the Yii
            // request lifecycle. A malformed path must never escape as an
            // uncaught exception: inside a coroutine that terminates the worker.
            try {
                if ($path !== null && $this->rawRoutes !== [] && $this->tryRawRoute($swooleRequest, $swooleResponse, $path)) {
                    return;
                }

                if ($path !== null && $this->tryServeStaticFile($swooleRequest, $swooleResponse, $path)) {
                    return;
                }

                if ($path !== null && $this->tryServePoolMetrics($swooleRequest, $swooleResponse, $path)) {
                    return;
                }

                if ($path !== null && $this->tryServeMetrics($swooleRequest, $swooleResponse, $path)) {
                    return;
                }
            } catch (Throwable $exception) {
                Yii::error('Pre-dispatch request handling failed: ' . $exception->getMessage(), __METHOD__);
                if (!$swooleResponse->isWritable()) {
                    return;
                }
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
                $statusCode = $response->statusCode ?? 200;
            } catch (Throwable $exception) {
                $statusCode = $this->handleException($swooleResponse, $exception);
            } finally {
                $this->stateManager->cleanup($app);
            }
        } finally {
            $this->requestMetrics->record(
                (hrtime(true) - $start) / 1_000_000_000.0,
                $statusCode,
                strtoupper((string) ($swooleRequest->server['request_method'] ?? 'GET'))
            );
        }
    }

    /**
     * Eagerly resolves pooled components so connection pools are created
     * before the first real request.
     */
    public function prewarm(int $connections = 0): void
    {
        try {
            $pools = PoolMetricsCollector::collect($this->getApplication());
        } catch (Throwable $e) {
            Yii::error('Connection pool pre-warming failed: ' . $e->getMessage(), __METHOD__);

            return;
        }

        if ($connections <= 0 || $pools === []) {
            return; // pools are instantiated, no connections opened
        }

        foreach ($pools as $id => $pool) {
            try {
                $maxActive = (int) ($pool->getMetrics()['max_active'] ?? $connections);
            } catch (Throwable) {
                continue;
            }

            // Borrow the target count while holding every connection, so each
            // borrow() has to *create* a new one (none is back in the idle
            // channel yet). Returning them one-by-one up front would let the
            // next borrow() reuse the same single connection, opening only 1.
            $target = min($connections, $maxActive);
            $borrowed = [];
            try {
                for ($i = 0; $i < $target; $i++) {
                    $borrowed[] = $pool->borrow();
                }
            } catch (Throwable $e) {
                Yii::error("Failed to pre-warm pool '{$id}': " . $e->getMessage(), __METHOD__);
            } finally {
                foreach ($borrowed as $connection) {
                    try {
                        $pool->return($connection);
                    } catch (Throwable) {
                        // Pre-warming is best-effort; a failed return is no
                        // worse than the connection simply not existing.
                    }
                }
            }
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

        if ($class === WebApplication::class) {
            // Default application class: swap in the coroutine-aware subclass
            // so request/response/session/user are created per coroutine
            // instead of being shared by concurrent requests.
            $class = CoroutineApplication::class;
        } elseif (!is_a($class, CoroutineApplication::class, true)) {
            Yii::warning(sprintf(
                'Application class "%s" does not extend %s: request/response/session/user are shared between concurrent requests.',
                $class,
                CoroutineApplication::class
            ), __METHOD__);
        }

        $this->application = new $class($config);

        return $this->application;
    }

    private function handleException(Response $swooleResponse, Throwable $exception): int
    {
        // Exceptions never reach Yii's own error handler here (the request is
        // handled manually), so log them explicitly - otherwise failed
        // requests leave no trace in the application log.
        try {
            Yii::error(
                sprintf(
                    'Unhandled request exception: %s: %s in %s:%d',
                    $exception::class,
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                ),
                __METHOD__
            );
        } catch (Throwable) {
            // Logging must never mask the response.
        }

        if (!$swooleResponse->isWritable()) {
            return $this->resolveStatusCode($exception);
        }

        // Render through the application's error handler (errorAction / custom
        // error view / JSON for AJAX requests) like a normal Yii2 request.
        try {
            $errorResponse = $this->renderExceptionResponse($exception);
        } catch (Throwable $renderError) {
            $errorResponse = null;
            try {
                Yii::error('Failed to render the error response: ' . $renderError->getMessage(), __METHOD__);
            } catch (Throwable) {
            }
        }
        if ($errorResponse !== null) {
            try {
                $this->responseSender->send($swooleResponse, $errorResponse);

                return $errorResponse->statusCode;
            } catch (Throwable) {
                // Fall through to the plain-text response below.
            }
        }

        $status = $this->resolveStatusCode($exception);
        $swooleResponse->status($status);
        $swooleResponse->header('Content-Type', 'text/plain; charset=UTF-8');

        if (YII_DEBUG) {
            $body = 'Error: ' . $exception->getMessage() . "\n\n"
                . 'Coroutine ID: ' . \Swoole\Coroutine::getCid() . "\n\n"
                . $exception->getTraceAsString();
        } else {
            $body = 'Internal Server Error';
        }

        $swooleResponse->end($body);

        return $status;
    }

    /**
     * Builds the response Yii2 would produce for this exception: the
     * configured `errorHandler.errorAction` (e.g. `site/error`) with the
     * proper status code, or null when the application has no error action.
     */
    private function renderExceptionResponse(Throwable $exception): ?\yii\web\Response
    {
        $app = $this->application;
        if ($app === null) {
            return null;
        }

        $handler = $app->getErrorHandler();
        if ($handler->errorAction === null) {
            return null;
        }

        $response = $app->getResponse();
        $response->clear();
        $response->statusCode = $this->resolveStatusCode($exception);
        $response->format = $app->getRequest()->getIsAjax()
            ? \yii\web\Response::FORMAT_JSON
            : \yii\web\Response::FORMAT_HTML;

        $handler->exception = $exception;
        try {
            $result = $app->runAction($handler->errorAction);
        } finally {
            $handler->exception = null;
        }

        if ($result instanceof \yii\web\Response) {
            return $result;
        }

        $response->data = $result;
        // The normal dispatch path finalizes the response before sending;
        // this branch returns early, so run the formatter here.
        if ($response instanceof CoroutineResponse) {
            $response->finalize();
        } elseif (is_string($result)) {
            $response->content = $result;
        }

        return $response;
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
