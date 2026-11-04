<?php

$common = require __DIR__ . '/common.php';

$config = [
    'id' => 'blog',
    'basePath' => dirname(__DIR__),
    'bootstrap' => [
        // Standard Yii2 practice: instantiate the log dispatcher, otherwise
        // Yii::error() messages never reach the configured targets.
        'log',
        ['class' => \Yii2S\Swoole\Bootstrap::class],
    ],
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@bower' => '@vendor/yidas/yii2-bower-asset/bower',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
        'db' => array_merge($common['components']['db'], ['enableCoroutinePooling' => true]),
        'request' => [
            'cookieValidationKey' => 'blog-demo-validation-key',
        ],
        // Standard Yii2 wiring: route exceptions through site/error so the
        // custom error view (views/site/error.php) is rendered.
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'session' => [
            'class' => \Yii2S\Swoole\Session\CoroutineSession::class,
            'cookieParams' => ['httpOnly' => true, 'sameSite' => 'Lax'],
        ],
        'cache' => [
            'class' => \Yii2S\Swoole\Cache\CoroutineRedisCache::class,
            'redis' => 'redis',
            'keyPrefix' => 'blog:cache:',
        ],
        'user' => [
            'identityClass' => app\models\User::class,
            'enableAutoLogin' => true,
            'loginUrl' => ['admin/default/login'],
        ],
        'assetManager' => [
            'basePath' => '@app/web/assets',
            'baseUrl' => '/assets',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'GET api/posts' => 'api/post/index',
                'POST api/posts' => 'api/post/create',
                'GET api/posts/<id:\d+>' => 'api/post/view',
                'PUT,PATCH api/posts/<id:\d+>' => 'api/post/update',
                'DELETE api/posts/<id:\d+>' => 'api/post/delete',
            ],
        ],
        'log' => [
            'targets' => [[
                'class' => \Yii2S\Swoole\Log\CoroutineFileTarget::class,
                'logFile' => '@app/runtime/logs/app.log',
                // Demo: flush every message so the smoke test can assert log
                // lines immediately; raise this in a real deployment.
                'maxBufferMessages' => 1,
            ]],
        ],
        'swooleHttpServer' => [
            'class' => \Yii2S\Swoole\Server\HttpServer::class,
            'documentRoot' => '@app/web',
            'prewarmConnections' => 2,
            // Covers are limited to 2 MB in the controller; the request body
            // must be a bit larger to let that validation run (default 2 MB).
            'settings' => ['package_max_length' => 8 * 1024 * 1024],
            'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
        ],
    ],
];

return array_replace_recursive($common, $config);
