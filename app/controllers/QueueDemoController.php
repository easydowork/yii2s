<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace app\controllers;

use Yii;
use yii\web\Controller;

/**
 * Demo endpoints for the coroutine queue component.
 */
class QueueDemoController extends Controller
{
    /**
     * Pushes a demo job onto the example queue.
     */
    public function actionPush(): array
    {
        $payload = ['action' => 'demo', 'at' => time(), 'nonce' => bin2hex(random_bytes(4))];
        $id = Yii::$app->queue->push($payload);

        return ['pushed' => true, 'id' => $id, 'pending' => Yii::$app->queue->countPending()];
    }

    /**
     * Processes up to 10 pending jobs inline (demo worker without a separate
     * console process; production should use `php yii queue/serve`).
     */
    public function actionRun(): array
    {
        $processed = Yii::$app->queue->run(10);

        return [
            'processed' => $processed,
            'pending' => Yii::$app->queue->countPending(),
            'dead' => Yii::$app->queue->countDead(),
        ];
    }
}
