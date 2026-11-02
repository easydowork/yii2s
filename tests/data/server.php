<?php

/**
 * Standalone server entry for the integration test:
 * `swoole-cli tests/data/server.php <port>`
 */

declare(strict_types=1);

define('YII_DEBUG', true);
define('YII_ENV', 'test');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

$port = (int) ($argv[1] ?? 9876);
$config = require __DIR__ . '/app.php';
$config['components']['swooleHttpServer']['port'] = $port;

$server = Yii::createObject($config['components']['swooleHttpServer']);
$server->start();
