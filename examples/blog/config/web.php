<?php

$common = require __DIR__ . '/common.php';

$config = [
    'id' => 'blog',
    'basePath' => dirname(__DIR__),
    'bootstrap' => [
        ['class' => \Yii2S\Swoole\Bootstrap::class],
    ],
    'controllerNamespace' => 'app\controllers',
    'aliases' => [
        '@bower' => '@vendor/yidas/yii2-bower-asset/bower',
        '@npm' => '@vendor/npm-asset',
    ],
    'components' => [
        'db' => array_merge($common['components']['db'], ['enableCoroutinePooling' => true]),
        'redis' => [
            'class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'poolMaxActive' => 10,
        ],
        'request' => [
            'cookieValidationKey' => 'blog-demo-validation-key',
        ],
        'session' => [
            'class' => \Yii2S\Swoole\Session\CoroutineSession::class,
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
                'GET,DELETE api/posts/<id:\d+>' => 'api/post/view',
            ],
        ],
        'log' => [
            'targets' => [[
                'class' => \Yii2S\Swoole\Log\CoroutineFileTarget::class,
                'logFile' => '@app/runtime/logs/app.log',
                'maxBufferMessages' => 50,
            ]],
        ],
        'swooleHttpServer' => [
            'class' => \Yii2S\Swoole\Server\HttpServer::class,
            'documentRoot' => '@app/web',
            'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
        ],
    ],
];

return array_replace_recursive($common, $config);
