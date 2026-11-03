<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

use yii\web\Controller;
use yii\web\ErrorAction;

/**
 * Hosts a stock [[ErrorAction]] so `errorHandler.errorAction` can be
 * exercised end to end. The view lives under tests/data (excluded from the
 * static analysis paths).
 */
class CompatErrorController extends Controller
{
    public function init(): void
    {
        parent::init();
        $this->setViewPath(__DIR__ . '/../data/views');
    }

    public function actions(): array
    {
        return [
            'error' => [
                'class' => ErrorAction::class,
                'layout' => false,
                'view' => 'error',
            ],
        ];
    }
}
