<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Log;

use Yii;
use yii\log\Logger;

/**
 * Logger whose message buffer is mutated by one coroutine at a time.
 *
 * `log()` and `flush()` both read and replace [[$messages]]. A flush yields
 * while a target writes the batch, and another request can log into the same
 * array in that window. The lock is re-entrant for the coroutine that holds
 * it, because flushing a dangling profiling warning logs from inside `flush()`.
 */
class CoroutineLogger extends Logger
{
    private static ?\Swoole\Coroutine\Channel $lock = null;

    private static ?int $owner = null;

    private static int $depth = 0;

    /**
     * Replaces the process logger with a coroutine-safe one, keeping messages
     * and the dispatcher already attached to the previous instance.
     */
    public static function install(): void
    {
        $current = Yii::getLogger();
        if ($current instanceof self) {
            return;
        }

        $logger = new self([
            'flushInterval' => $current->flushInterval,
            'traceLevel' => $current->traceLevel,
            'profilingAware' => $current->profilingAware,
            'dbEventNames' => $current->dbEventNames,
            'messages' => $current->messages,
            'dispatcher' => $current->dispatcher,
        ]);
        Yii::setLogger($logger);
        if ($logger->dispatcher !== null) {
            $logger->dispatcher->logger = $logger;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function log($message, $level, $category = 'application')
    {
        $this->acquire();
        try {
            parent::log($message, $level, $category);
        } finally {
            $this->release();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function flush($final = false)
    {
        $this->acquire();
        try {
            parent::flush($final);
        } finally {
            $this->release();
        }
    }

    private function acquire(): void
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($cid === -1) {
            return;
        }
        if (self::$owner === $cid) {
            self::$depth++;

            return;
        }

        // `new Channel` and the seeding push do not yield, so initialization
        // cannot race. The push must stay inside a coroutine.
        if (self::$lock === null) {
            self::$lock = new \Swoole\Coroutine\Channel(1);
            self::$lock->push(true);
        }
        self::$lock->pop();
        self::$owner = $cid;
        self::$depth = 1;
    }

    private function release(): void
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($cid === -1 || self::$owner !== $cid) {
            return;
        }
        self::$depth--;
        if (self::$depth > 0) {
            return;
        }
        self::$owner = null;
        self::$depth = 0;
        self::$lock?->push(true);
    }
}
