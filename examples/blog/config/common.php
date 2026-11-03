<?php

return [
    'params' => require __DIR__ . '/params.php',
    'components' => [
        'db' => [
            'class' => \Yii2S\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => 'mysql:host=127.0.0.1;dbname=blog',
            'username' => 'root',
            'password' => 'root',
            'charset' => 'utf8mb4',
        ],
        // Defined here (not only in web.php): the console app needs it too,
        // because `queue/serve` talks to Redis outside the web application.
        'redis' => [
            'class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'poolMaxActive' => 10,
        ],
        // Shared by the web app (pushing jobs) and the console app
        // (`queue/serve` consumes them). Handler lives in app\components.
        'queue' => [
            'class' => \Yii2S\Swoole\Queue\CoroutineRedisQueue::class,
            'redis' => 'redis',
            'channel' => 'blog',
            // Short retry delay so the demo/retry path is quick to observe.
            'ttr' => 2,
            'maxAttempts' => 3,
            'handler' => [\app\components\BlogJobs::class, 'handle'],
        ],
    ],
];
