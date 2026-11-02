<?php

/**
 * Test application configuration with CSRF validation enabled.
 */

return [
    'id' => 'yii2stest-csrf',
    'basePath' => dirname(__DIR__, 1),
    'controllerMap' => [
        'site' => ['class' => \yii2stest\server\TestSiteController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-csrf-key',
            'enableCsrfValidation' => true,
            'enableCsrfCookie' => true,
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
