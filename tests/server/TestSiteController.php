<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Yii;
use yii\web\Controller;

/**
 * Minimal controller used by the integration test application.
 */
class TestSiteController extends Controller
{
    public function actionIndex(): array
    {
        return ['app' => 'yii2stest', 'cid' => \Swoole\Coroutine::getCid()];
    }

    public function actionEcho(): array
    {
        return [
            'get' => Yii::$app->request->getQueryParams(),
            'post' => Yii::$app->request->getBodyParams(),
            'method' => Yii::$app->request->getMethod(),
        ];
    }

    public function actionCsrf(): array
    {
        // Enables CSRF validation and returns the current token.
        return [
            'token' => Yii::$app->request->getCsrfToken(),
            'name' => Yii::$app->request->csrfParam,
        ];
    }
}
