<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

/**
 * Same actions as [[CompatController]] with CSRF validation disabled, so the
 * request-parsing cases can focus on body/query handling.
 */
class OpenCompatController extends CompatController
{
    public $enableCsrfValidation = false;
}
