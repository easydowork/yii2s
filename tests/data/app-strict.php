<?php

/**
 * Strict-parsing application: `UrlManager::parseRequest()` returns false for
 * unmatched paths, which the coroutine request must translate into a 404
 * instead of a type error.
 */

return [
    'id' => 'yii2stest-strict',
    'basePath' => dirname(__DIR__),
    'controllerMap' => [
        'compat' => ['class' => \yii2stest\compat\CompatController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-strict-key',
        ],
        'response' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineResponse::class,
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'enableStrictParsing' => true,
            'rules' => [
                'GET compat/index' => 'compat/index',
            ],
        ],
    ],
];
