<?php

/**
 * Application wired like a stock Yii2 app: `errorHandler.errorAction` points
 * at a controller action, so exceptions must be rendered through it instead
 * of the dispatcher's plain-text fallback.
 */

return [
    'id' => 'yii2stest-error',
    'basePath' => dirname(__DIR__),
    'controllerMap' => [
        'fallback' => ['class' => \yii2stest\compat\CompatErrorController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-error-key',
        ],
        'response' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineResponse::class,
        ],
        'errorHandler' => [
            'errorAction' => 'fallback/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [],
        ],
    ],
];
