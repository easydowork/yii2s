<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use yii\web\Controller;

class SiteController extends Controller
{
    public function actionIndex(): array
    {
        return [
            'app' => 'yii2s-example',
            'engine' => 'swoole-coroutine',
            'php' => PHP_VERSION,
            'swoole' => phpversion('swoole'),
            'pid' => getmypid(),
            'cid' => \Swoole\Coroutine::getCid(),
        ];
    }

    public function actionError(): array
    {
        $exception = Yii::$app->errorHandler->exception ?? null;

        return [
            'error' => $exception !== null ? $exception->getMessage() : 'Not Found',
        ];
    }
}
