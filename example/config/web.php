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
    'controllerNamespace' => 'app\controllers',
    'bootstrap' => [
        ['class' => \Yii2S\Swoole\Bootstrap::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2s-example-cookie-key',
            'enableCsrfValidation' => false,
        ],
        'response' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineResponse::class,
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [],
        ],
        'swooleHttpServer' => [
            'class' => \Yii2S\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 9501,
            'pidFile' => '@app/runtime/swoole-server.pid',
            'documentRoot' => '@app/public',
            'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
        ],
        'db' => [
            'class' => \Yii2S\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_demo',
            'username' => 'root',
            'password' => 'root',
            'charset' => 'utf8mb4',
            'enableSchemaCache' => false,
            'poolMaxActive' => 20,
            'poolWaitTimeout' => 3.0,
        ],
        'redis' => [
            'class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'poolMaxActive' => 20,
        ],
        'cache' => [
            'class' => \Yii2S\Swoole\Cache\CoroutineRedisCache::class,
            'redis' => 'redis',
        ],
        'session' => [
            'class' => \Yii2S\Swoole\Session\CoroutineSession::class,
            'redis' => 'redis',
        ],
        'queue' => [
            'class' => \Yii2S\Swoole\Queue\CoroutineRedisQueue::class,
            'redis' => 'redis',
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
            'traceLevel' => 0,
            'targets' => [
                [
                    'class' => \Yii2S\Swoole\Log\CoroutineFileTarget::class,
                    'levels' => ['error', 'warning', 'info'],
                    'logFile' => '@app/runtime/logs/app.log',
                    'maxBufferMessages' => 20,
                ],
            ],
        ],
    ],
];
