<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

namespace yii2stest\framework;

use Yii2S\Swoole\Db\CoroutineDbConnection;

final class FwDb
{
    public static ?CoroutineDbConnection $connection = null;
}
