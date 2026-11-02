<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

// ensure we get report on all possible php errors
error_reporting(-1);

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);
define('YII_ENV', 'test');

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

Yii::setAlias('@yii2stest', __DIR__);
Yii::setAlias('@yii2s/swoole', dirname(__DIR__));

require_once __DIR__ . '/TestCase.php';
