<?php

/**
 * Test application configuration for the pool metrics endpoint test.
 */

return [
    'id' => 'yii2stest-pool-metrics',
    'basePath' => dirname(__DIR__, 1),
    'controllerMap' => [
        'site' => ['class' => \yii2stest\server\TestSiteController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-pool-key',
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
        'redis' => [
            'class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null,
            'poolMaxActive' => 5,
        ],
        'swooleHttpServer' => [
            'class' => \Yii2S\Swoole\Server\HttpServer::class,
            'host' => '127.0.0.1',
            'port' => 0,
            'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher(__FILE__),
        ],
    ],
];
