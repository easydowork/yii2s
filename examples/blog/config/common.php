<?php

return [
    'components' => [
        'db' => [
            'class' => \Yii2S\Swoole\Db\CoroutineDbConnection::class,
            'dsn' => 'mysql:host=127.0.0.1;dbname=blog',
            'username' => 'root',
            'password' => 'root',
            'charset' => 'utf8mb4',
        ],
    ],
];
