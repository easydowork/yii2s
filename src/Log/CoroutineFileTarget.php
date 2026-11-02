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

    /**
     * Cross-coroutine mutex serializing buffer mutations. The logger is flushed
     * from every request coroutine concurrently; without a lock the read-modify-
     * write on [[$messages]] races and messages are lost or written twice.
     *
     * @var \Swoole\Coroutine\Channel|null
     */
    private static ?\Swoole\Coroutine\Channel $lock = null;

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
        $this->lock();
        try {
            $this->messages = array_merge($this->messages, $messages);
            if (count($this->messages) >= $this->maxBufferMessages) {
                $this->export();
                $this->messages = [];
            }
        } finally {
            $this->unlock();
        }
    }

    /**
     * Writes all buffered messages to the file immediately.
     */
    public function flushNow(): void
    {
        $this->lock();
        try {
            if ($this->messages === []) {
                return;
            }
            $this->export();
            $this->messages = [];
        } finally {
            $this->unlock();
        }
    }

    private function lock(): void
    {
        if (\Swoole\Coroutine::getCid() === -1) {
            return; // single-threaded CLI: no concurrency to guard against
        }

        // Lazy init. Initialization races are impossible here: `new Channel`
        // and the seeding `push` contain no yield points, so a coroutine
        // always finishes both before any other coroutine can observe
        // self::$lock. This must stay inside a coroutine - Channel::push from
        // non-coroutine context fails silently and would permanently lose
        // the token, deadlocking the first lock() call.
        if (self::$lock === null) {
            self::$lock = new \Swoole\Coroutine\Channel(1);
            self::$lock->push(true); // seeded as "unlocked"
        }

        self::$lock->pop(); // block until the mutex is free
    }

    private function unlock(): void
    {
        if (self::$lock !== null && \Swoole\Coroutine::getCid() !== -1) {
            self::$lock->push(true);
        }
    }
}
