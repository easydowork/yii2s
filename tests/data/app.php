<?php

/**
 * Test application configuration used by the integration server test.
 */

return [
    'id' => 'yii2stest-app',
    'basePath' => dirname(__DIR__),
    'controllerMap' => [
        'site' => ['class' => \yii2stest\server\TestSiteController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-cookie-key',
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
