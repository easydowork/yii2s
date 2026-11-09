<?php

/**
 * Subprocess server entry for the integration tests:
 *
 *   swoole-cli tests/data/server.php <port> <config-file> [<options-json-file>]
 *
 * The options file is a JSON map with three optional keys:
 *   - "dispatcher": array  RequestDispatcher config (enablePoolMetricsEndpoint,
 *                          enableMetricsEndpoint, staticRoot, ...)
 *   - "rawRoutes":  array  raw-route definitions, each
 *                          {"method","path","type","value","message"}
 *                          where type is "string"|"array"|"throw"
 *   - "server":     array  HttpServer component overrides (prewarmConnections,
 *                          workerNum, documentRoot, settings, ...)
 */

declare(strict_types=1);

define('YII_DEBUG', true);
define('YII_ENV', 'test');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

$port = (int) ($argv[1] ?? 9876);
$configFile = (string) ($argv[2] ?? __DIR__ . '/app.php');
$optionsFile = $argv[3] ?? null;

$config = require $configFile;
if (!is_array($config)) {
    fwrite(STDERR, "Test server config did not return an array.\n");
    exit(1);
}

$options = [];
if (is_string($optionsFile) && is_file($optionsFile)) {
    $decoded = json_decode((string) file_get_contents($optionsFile), true);
    if (is_array($decoded)) {
        $options = $decoded;
    }
}

// Build the dispatcher from the same config file the server uses, applying the
// requested flags and raw routes (serialisable stand-ins for the callables the
// in-process tests used to register directly).
$dispatcher = new \Yii2S\Swoole\Server\RequestDispatcher(
    $configFile,
    is_array($options['dispatcher'] ?? null) ? $options['dispatcher'] : []
);

foreach (is_array($options['rawRoutes'] ?? null) ? $options['rawRoutes'] : [] as $route) {
    $method = strtoupper((string) ($route['method'] ?? 'GET'));
    $path = (string) ($route['path'] ?? '/');
    $type = (string) ($route['type'] ?? 'string');
    $value = $route['value'] ?? '';
    $message = (string) ($route['message'] ?? 'raw-route-error');

    $dispatcher->addRawRoute($method, $path, match ($type) {
        'array' => static fn (): array => (array) $value,
        'throw' => static function () use ($message): string {
            throw new \RuntimeException($message);
        },
        'pid' => static fn (): string => (string) getmypid(),
        default => static fn (): string => (string) $value,
    });
}

$serverOptions = is_array($options['server'] ?? null) ? $options['server'] : [];

// The config file's own swooleHttpServer block is the base, so per-config
// options (enableWebSocket, websocketHandler, ...) reach the component.
$config['components']['swooleHttpServer'] = array_merge(
    is_array($config['components']['swooleHttpServer'] ?? null)
        ? $config['components']['swooleHttpServer']
        : [],
    [
        'class' => \Yii2S\Swoole\Server\HttpServer::class,
        'host' => '127.0.0.1',
        'workerNum' => 1,
    ],
    $serverOptions,
    [
        'port' => $port,
        'dispatcher' => $dispatcher,
    ]
);

$server = Yii::createObject($config['components']['swooleHttpServer']);
$server->start();
