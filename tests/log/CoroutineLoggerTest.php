<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\log;

use Yii2S\Swoole\Log\CoroutineLogger;
use yii\log\Dispatcher;
use yii\log\Logger;
use yii\log\Target;
use yii2stest\TestCase;

/**
 * @group log
 */
class CoroutineLoggerTest extends TestCase
{
    public function testConcurrentFlushDoesNotDropMessages(): void
    {
        $previous = \Yii::getLogger();
        $previousDispatcher = $previous->dispatcher;
        try {
            \Yii::setLogger(new CoroutineLogger(['flushInterval' => 0]));
            $this->runInCoroutine(function (): void {
            $target = new class extends Target {
                /** @var array<int, string> */
                public array $seen = [];

                public function export(): void
                {
                }

                public function collect($messages, $final): void
                {
                    \Swoole\Coroutine::sleep(0.01);
                    foreach ($messages as $message) {
                        $this->seen[] = (string) $message[0];
                    }
                }
            };
            $target->exportInterval = 1;

            $logger = \Yii::getLogger();
            $logger->flushInterval = 0;
            new Dispatcher([
                'targets' => [$target],
                'logger' => $logger,
            ]);

            $channel = new \Swoole\Coroutine\Channel(2);
            foreach (['a', 'b'] as $prefix) {
                \Swoole\Coroutine::create(function () use ($logger, $prefix, $channel): void {
                    for ($i = 0; $i < 20; $i++) {
                        $logger->log($prefix . $i, Logger::LEVEL_INFO, 'test');
                        $logger->flush(true);
                    }
                    $channel->push(true);
                });
            }
            $channel->pop(2);
            $channel->pop(2);
            $logger->flush(true);

            $this->assertCount(40, $target->seen);
            });
        } finally {
            $installed = \Yii::getLogger();
            if ($installed instanceof CoroutineLogger) {
                $installed->dispatcher = null;
            }
            \Yii::setLogger($previous);
            $previous->dispatcher = $previousDispatcher;
            if ($previousDispatcher !== null) {
                $previousDispatcher->logger = $previous;
            }
        }
    }
}
