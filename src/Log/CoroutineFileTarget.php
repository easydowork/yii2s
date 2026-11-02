<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Log;

use yii\log\FileTarget;

/**
 * FileTarget that buffers messages in memory and writes them in batches.
 *
 * In a resident coroutine server the framework flushes the logger after
 * every request ([[\Yii2S\Swoole\Runtime\RequestStateManager]] calls
 * `flush(true)`); the stock FileTarget would then open/write/close the log
 * file once per request. This target only hits the disk once
 * [[maxBufferMessages]] messages are pending; the remainder is written on
 * process shutdown or by an explicit [[flushNow()]].
 */
class CoroutineFileTarget extends FileTarget
{
    /** @var int how many messages to accumulate before writing to the file */
    public int $maxBufferMessages = 100;

    public function init(): void
    {
        parent::init();
        register_shutdown_function(function (): void {
            $this->flushNow();
        });
    }

    /**
     * Buffers incoming messages; exporting is deferred until the buffer is
     * full. The $final flag (logger flush after each request) is ignored on
     * purpose - that is what makes this target coroutine-friendly.
     *
     * @param array $messages
     * @param bool $final
     */
    public function collect($messages, $final): void
    {
        $this->messages = array_merge($this->messages, $messages);
        if (count($this->messages) >= $this->maxBufferMessages) {
            $this->export();
            $this->messages = [];
        }
    }

    /**
     * Writes all buffered messages to the file immediately.
     */
    public function flushNow(): void
    {
        if ($this->messages === []) {
            return;
        }
        $this->export();
        $this->messages = [];
    }
}
