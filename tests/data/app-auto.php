<?php

/**
 * The "one-line integration" path: nothing is configured explicitly except
 * `bootstrap`, so request/response are still the stock classes when
 * `yii\web\Application::bootstrap()` runs. The package must replace them (and
 * drop the already-created stock instances) before the first real request.
 */

return [
    'id' => 'yii2stest-auto',
    'basePath' => dirname(__DIR__),
    'bootstrap' => [
        ['class' => \Yii2S\Swoole\Bootstrap::class],
    ],
    'controllerMap' => [
        'compat' => ['class' => \yii2stest\compat\CompatController::class],
    ],
    'components' => [
        'request' => [
            'cookieValidationKey' => 'yii2stest-auto-key',
        ],
        'response' => [
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [],
        ],
    ],
];
