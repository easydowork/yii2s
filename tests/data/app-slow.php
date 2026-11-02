<?php

/**
 * Test application with a slow endpoint used by the graceful-stop test.
 */

return [
    'id' => 'yii2stest-slow',
    'basePath' => dirname(__DIR__, 1),
    'controllerMap' => [
        'slow' => ['class' => \yii2stest\server\SlowController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-slow-key',
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
            'port' => 0,
            'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher(__FILE__),
        ],
    ],
];
