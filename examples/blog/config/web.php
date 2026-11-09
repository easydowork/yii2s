<?php

$common = require __DIR__ . '/common.php';

$config = [
    'id' => 'blog',
    'basePath' => dirname(__DIR__),
    // swoole-cli has no php.ini (PHP defaults to UTC) and Yii2 pins the app
    // timezone to UTC - declare it so log timestamps match system time.
    'timeZone' => 'Asia/Shanghai',
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
        // Schema cache: Yii2 otherwise re-queries information_schema for table
        // metadata (columns + foreign keys) on every cold request. Caching it in
        // Redis makes those lookups hit once across all workers and restarts.
        'db' => array_merge($common['components']['db'], [
            'enableCoroutinePooling' => true,
            'enableSchemaCache' => true,
            'schemaCache' => 'cache',
            'schemaCacheDuration' => 3600,
        ]),
        'request' => [
            // The explicit class is REQUIRED here (not style): web
            // Application::bootstrap() resolves `request` BEFORE extension
            // bootstraps run, so a definition that relies on the stock
            // default would instantiate yii\web\Request and FATAL on
            // `trustedProxies` (a CoroutineRequest-only property) before the
            // auto-replacement ever gets a chance. State the class whenever
            // the definition carries coroutine-specific keys.
            'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
            'cookieValidationKey' => 'blog-demo-validation-key',
            // Behind nginx: trust the local proxy chain so getUserIP()
            // resolves the REAL client IP from the right-most untrusted
            // X-Forwarded-For entry instead of the proxy address.
            'trustedProxies' => ['127.0.0.1', '::1'],
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
            // Normalize trailing slashes so /admin/ resolves like /admin.
            'normalizer' => [
                'class' => \yii\web\UrlNormalizer::class,
                'action' => \yii\web\UrlNormalizer::ACTION_REDIRECT_PERMANENT,
            ],
            'rules' => [
                // Public front pages
                'GET signup' => 'site/signup',
                'POST signup' => 'site/signup',
                // Vue3 admin SPA entry
                'GET admin' => 'admin/default/vue',
                // Public JSON API (read-only for guests, writes need X-Api-Key)
                'GET api/posts' => 'api/post/index',
                'POST api/posts' => 'api/post/create',
                'GET api/posts/<id:\d+>' => 'api/post/view',
                'PUT,PATCH api/posts/<id:\d+>' => 'api/post/update',
                'DELETE api/posts/<id:\d+>' => 'api/post/delete',
                // Admin JSON API (session + CSRF, powers the Vue SPA)
                'POST api/admin/login' => 'api/admin/login',
                'POST api/admin/logout' => 'api/admin/logout',
                'GET api/admin/me' => 'api/admin/me',
                'GET api/admin/dashboard' => 'api/admin/dashboard',
                'GET api/admin/posts' => 'api/admin/posts',
                'GET api/admin/posts/<id:\d+>' => 'api/admin/post-view',
                'POST api/admin/posts' => 'api/admin/post-create',
                'PUT,PATCH api/admin/posts/<id:\d+>' => 'api/admin/post-update',
                'DELETE api/admin/posts/<id:\d+>' => 'api/admin/post-delete',
                'GET api/admin/comments' => 'api/admin/comments',
                'POST api/admin/comments/<id:\d+>/approve' => 'api/admin/comment-approve',
                'DELETE api/admin/comments/<id:\d+>' => 'api/admin/comment-delete',
                'GET api/admin/users' => 'api/admin/users',
                'POST api/admin/users' => 'api/admin/user-create',
                'PUT,PATCH api/admin/users/<id:\d+>' => 'api/admin/user-update',
                'DELETE api/admin/users/<id:\d+>' => 'api/admin/user-delete',
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
            'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php', [
                'enableMetricsEndpoint' => true,
                'enablePoolMetricsEndpoint' => true,
            ]),
        ],
    ],
];

return array_replace_recursive($common, $config);
