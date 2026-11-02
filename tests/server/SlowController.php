<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use yii\web\Controller;

/**
 * Controller with a deliberately slow endpoint for graceful-stop testing.
 */
class SlowController extends Controller
{
    public function actionIndex(): array
    {
        \Swoole\Coroutine::sleep(1.0);

        return ['done' => true];
    }
}
