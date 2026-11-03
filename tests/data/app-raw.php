<?php

/**
 * Application behind the raw-route tests: only routing/response are needed,
 * because raw routes never build the Yii request or response objects.
 */

return [
    'id' => 'yii2stest-raw',
    'basePath' => dirname(__DIR__),
    'controllerMap' => [
        'compat' => ['class' => \yii2stest\compat\CompatController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-raw-key',
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
    ],
];
