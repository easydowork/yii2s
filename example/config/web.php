<?php

/**
 * Yii2S Swoole example - application configuration.
 *
 * Loaded twice: by the console entry (example/yii) and by RequestDispatcher
 * inside the Swoole server to create the web application.
 */

return [
    'id' => 'yii2s-example',
    'basePath' => dirname(__DIR__),
    // swoole-cli ships without a php.ini, so PHP defaults to UTC; Yii2 then
    // pins the application timezone to UTC as well. Declare it explicitly so
    // log timestamps (and everything time-based) match the system timezone.
    'timeZone' => 'Asia/Shanghai',
    'controllerNamespace' => 'app\controllers',
    // The only required integration step: this bootstrap replaces the stock
    // Yii2 component classes (request/response/db/redis) with their coroutine
    // counterparts - keeping every option configured below - and registers a
    // default `swooleHttpServer` component when the application has none.
    'bootstrap' => [
        ['class' => \Yii2S\Swoole\Bootstrap::class],
    ],
    'components' => [
        // No 'class' needed: the stock yii\web\Request is swapped for
        // CoroutineRequest by the bootstrap above.
        'request' => [
            'cookieValidationKey' => 'yii2s-example-cookie-key',
            'enableCsrfValidation' => true,
        ],
        'response' => [
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            // Required: coroutine requests do not populate $_GET, so the
            // default ?r= routing cannot be used (see README known limits).
            'showScriptName' => false,
        ],
        // Deliberately the stock FPM-era class: the bootstrap swaps it for
        // CoroutineDbConnection, so an existing application config works
        // unchanged. Remove the bootstrap to fall back to plain Yii2.
        'db' => [
            'class' => \yii\db\Connection::class,
            'dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_demo',
            'username' => 'root',
            'password' => getenv('YII2S_TEST_DB_PASSWORD') ?: 'root',
            'charset' => 'utf8mb4',
            // poolMaxActive is the hard cap on concurrent queries; raise it
            // when a request fans out into many parallel sub-queries.
            'poolMaxActive' => 20,
            // Minimum idle connections the periodic keeper maintains.
            'poolMinIdle' => 2,
        ],
        'redis' => [
            'class' => \yii\redis\Connection::class, // swapped by the bootstrap
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null,
            'poolMaxActive' => 20,
            'poolMinIdle' => 2,
        ],
        // Cache/session/queue have no stock counterpart worth wrapping, so
        // their coroutine classes are configured explicitly.
        'cache' => [
            'class' => \Yii2S\Swoole\Cache\CoroutineRedisCache::class,
        ],
        'session' => [
            'class' => \Yii2S\Swoole\Session\CoroutineSession::class,
        ],
        'queue' => [
            'class' => \Yii2S\Swoole\Queue\CoroutineRedisQueue::class,
            'channel' => 'example',
            'ttr' => 5,
            'maxAttempts' => 3,
            'handler' => function (array $payload): array {
                // Demo worker: echoes the payload into the log channel.
                \Yii::info('queue job handled: ' . json_encode($payload), 'queue');
                return $payload;
            },
        ],
        'log' => [
            'targets' => [
                [
                    'class' => \Yii2S\Swoole\Log\CoroutineFileTarget::class,
                    'logFile' => '@app/runtime/logs/app.log',
                    'maxBufferMessages' => 20,
                    // A resident server must not write per-request info logs
                    // (every DB query logs at info level) - a sustained load
                    // would fill the disk in hours. Keep the file for errors
                    // and warnings only.
                    'levels' => [\yii\log\Logger::LEVEL_ERROR, \yii\log\Logger::LEVEL_WARNING],
                ],
            ],
        ],
        // Everything below is optional: with no swooleHttpServer component
        // the bootstrap registers one pointing at this config file
        // (defaults: 127.0.0.1:9501, pid file under @app/runtime).
        'swooleHttpServer' => [
            'class' => \Yii2S\Swoole\Server\HttpServer::class,
            'port' => 9501,
            // Periodic connection-pool upkeep (min-idle replenish, max-lifetime
            // recycling, idle keepalive). 0 disables.
            'poolMaintenanceInterval' => 1.0,
            'documentRoot' => '@app/public',
            'dispatcher' => (new \Yii2S\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php', [
                'enablePoolMetricsEndpoint' => true,
                'enableMetricsEndpoint' => true,
                // Aggregate /metrics across all workers via Redis.
                'enableMetricsAggregation' => true,
                'metricsAggregationRedis' => 'redis',
            ]))
                // Raw route: answered without creating the Yii request/response
                // objects - use it for health checks / tiny stateless APIs.
                ->addRawRoute('GET', '/ping', static fn (): string => 'pong'),
        ],
    ],
];
