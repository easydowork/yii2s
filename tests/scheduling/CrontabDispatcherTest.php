<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\scheduling;

use Yii2S\Swoole\Scheduling\Crontab;
use Yii2S\Swoole\Scheduling\CrontabDispatcher;
use yii2stest\TestCase;

class CrontabDispatcherTest extends TestCase
{
    public function testEverySecondsInterval(): void
    {
        $dispatcher = new CrontabDispatcher();
        $runs = 0;
        $dispatcher->add(new Crontab('job', '@every 1s', function () use (&$runs): void {
            $runs++;
        }));

        $dispatcher->tick(100);
        $dispatcher->tick(101);
        $dispatcher->tick(102);

        $this->assertSame(3, $runs);
    }

    public function testEveryMinutesInterval(): void
    {
        $dispatcher = new CrontabDispatcher();
        $runs = 0;
        $dispatcher->add(new Crontab('job', '@every 5m', function () use (&$runs): void {
            $runs++;
        }));

        $dispatcher->tick(strtotime('2026-01-01 00:05:00')); // divisible by 300s
        $dispatcher->tick(strtotime('2026-01-01 00:07:00')); // not divisible

        $this->assertSame(1, $runs);
    }

    public function testCronExpression(): void
    {
        $dispatcher = new CrontabDispatcher();
        $runs = 0;
        $dispatcher->add(new Crontab('job', '0 0 * * *', function () use (&$runs): void {
            $runs++;
        }));

        $dispatcher->tick(strtotime('2026-01-01 00:00:00')); // midnight -> due
        $dispatcher->tick(strtotime('2026-01-01 12:00:00')); // noon -> not due

        $this->assertSame(1, $runs);
    }

    public function testCronStep(): void
    {
        $dispatcher = new CrontabDispatcher();
        $runs = 0;
        $dispatcher->add(new Crontab('job', '*/5 * * * *', function () use (&$runs): void {
            $runs++;
        }));

        $dispatcher->tick(strtotime('2026-01-01 00:05:00')); // minute 5 -> due
        $dispatcher->tick(strtotime('2026-01-01 00:07:00')); // minute 7 -> not due

        $this->assertSame(1, $runs);
    }
}
