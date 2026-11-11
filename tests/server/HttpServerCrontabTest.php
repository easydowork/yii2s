<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use yii2stest\ServerTestCase;

/**
 * @group server
 * @group integration
 */
final class HttpServerCrontabTest extends ServerTestCase
{
    public function testCrontabTimerDoesNotBlockWorkerReload(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app.php', [
                'server' => [
                    'crontab' => ['class' => \Yii2S\Swoole\Scheduling\CrontabDispatcher::class],
                ],
            ]);
            try {
                $this->assertSame(200, $this->get('/')['status']);

                // A `while (true) { sleep() }` ticker would leave the old
                // worker stuck in a sleeping coroutine here (Swoole's
                // "all coroutines asleep" fatal). The reactor timer is
                // cleared on worker exit, so the reload must stay clean.
                $this->reloadServer();
                \Swoole\Coroutine::sleep(1.5);
                $this->assertSame(200, $this->get('/')['status']);
            } finally {
                $this->stopServer();
            }
        });
    }
}
