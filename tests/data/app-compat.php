<?php

/**
 * Application used by the Yii2 feature-compatibility suite (tests/compat).
 *
 * Everything a normal Yii2 web application configures is wired here so the
 * suite can verify the feature points one by one against a real Swoole
 * server: request/response, routing, cookies, CSRF, session, user, uploads,
 * downloads, DB, cache, Redis and error handling.
 */

return [
    'id' => 'yii2stest-compat',
    'basePath' => dirname(__DIR__),
    // Like a stock Yii2 app: the log dispatcher must be bootstrapped, otherwise
    // Yii::info() messages are never handed to the targets.
    'bootstrap' => ['log'],
    'controllerMap' => [
        'compat' => ['class' => \yii2stest\compat\CompatController::class],
        'open' => ['class' => \yii2stest\compat\OpenCompatController::class],
    ],
    'components' => [
        'request' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'yii2stest-compat-cookie-key',
            'enableCsrfValidation' => true,
            // The test client connects from 127.0.0.1; private ranges are
            // listed to exercise CIDR matching as well.
            'trustedProxies' => ['127.0.0.1', '::1', '10.0.0.0/8'],
        ],
        'response' => [
            'class' => \Yii2S\Swoole\Runtime\CoroutineResponse::class,
            'format' => \yii\web\Response::FORMAT_JSON,
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'GET compat/hello/<name:[a-z]+>' => 'compat/hello',
                'GET compat/post/<id:\d+>' => 'compat/post',
            ],
        ],
        'session' => [
            'class' => \Yii2S\Swoole\Session\CoroutineSession::class,
            'redis' => 'redis',
            'timeout' => 60,
        ],
        'user' => [
            'identityClass' => \yii2stest\compat\CompatIdentity::class,
            'enableSession' => true,
            'enableAutoLogin' => false,
        ],
        'db' => [
            'class' => \Yii2S\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_test',
            'username' => 'root',
            'password' => getenv('YII2S_TEST_DB_PASSWORD') ?: 'root',
            'charset' => 'utf8mb4',
        ],
        'redis' => [
            'class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'password' => getenv('YII2S_TEST_REDIS_PASSWORD') ?: null,
        ],
        'cache' => [
            'class' => \Yii2S\Swoole\Cache\CoroutineRedisCache::class,
            'redis' => 'redis',
        ],
        'log' => [
            'targets' => [[
                'class' => \Yii2S\Swoole\Log\CoroutineFileTarget::class,
                'logFile' => sys_get_temp_dir() . '/yii2stest-compat.log',
                'maxBufferMessages' => 1,
            ]],
        ],
    ],
];
