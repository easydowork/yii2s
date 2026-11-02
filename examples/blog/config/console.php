<?php

$common = require __DIR__ . '/common.php';

$config = [
    'id' => 'blog-console',
    'basePath' => dirname(__DIR__),
    'bootstrap' => [
        ['class' => \Yii2S\Swoole\Bootstrap::class],
    ],
    'controllerNamespace' => 'app\commands',
    'components' => [
        // migrations run in the console (non-coroutine): detached direct connection
        'db' => ['enableCoroutinePooling' => false],
    ],
];

return array_replace_recursive($common, $config);
