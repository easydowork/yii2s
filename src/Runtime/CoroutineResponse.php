<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use yii\web\Response;

/**
 * Yii2 web response finalized in memory instead of writing to php://output.
 *
 * [[finalize]] runs the normal formatter pipeline (data -> content, headers,
 * cookies) without touching PHP output functions. The result is then handed
 * over to [[SwooleResponseSender]] which writes it to the Swoole response.
 */
class CoroutineResponse extends Response
{
    /**
     * Prepares the response for sending without emitting output.
     */
    public function finalize(): void
    {
        if ($this->isSent) {
            return;
        }

        $this->trigger(self::EVENT_BEFORE_SEND);
        $this->prepare();
        $this->trigger(self::EVENT_AFTER_SEND);
        $this->isSent = true;
    }
}
