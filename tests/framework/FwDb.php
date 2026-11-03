<?php

namespace yii2stest\framework;

use Yii2S\Swoole\Db\CoroutineDbConnection;

final class FwDb
{
    public static ?CoroutineDbConnection $connection = null;
}
