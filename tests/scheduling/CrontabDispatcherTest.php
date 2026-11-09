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

    public function testZeroIntervalDoesNotDivideByZero(): void
    {
        $dispatcher = new CrontabDispatcher();
        $runs = 0;
        $dispatcher->add(new Crontab('job', '@every 0s', function () use (&$runs): void {
            $runs++;
        }));

        // Must not throw a DivisionByZeroError; an invalid rule never fires.
        $dispatcher->tick(100);
        $this->assertSame(0, $runs);
    }

    public function testSundayCronAlias(): void
    {
        $dispatcher = new CrontabDispatcher();
        $runs = 0;
        $dispatcher->add(new Crontab('job', '* * * * 7', function () use (&$runs): void {
            $runs++;
        }));

        // 2026-01-04 is a Sunday; cron weekday 7 is the Sunday alias.
        $dispatcher->tick(strtotime('2026-01-04 12:00:00'));
        $this->assertSame(1, $runs);
    }

    public function testNonZeroWorkerDoesNotRunTheTask(): void
    {
        CrontabDispatcher::setWorkerId(1);
        try {
            $dispatcher = new CrontabDispatcher();
            $runs = 0;
            $dispatcher->add(new Crontab('job', '@every 1s', function () use (&$runs): void {
                $runs++;
            }));

            $dispatcher->tick(100);
            $this->assertSame(0, $runs);
        } finally {
            CrontabDispatcher::setWorkerId(null);
        }
    }

    public function testRunningTaskIsNotStartedAgain(): void
    {
        $dispatcher = new CrontabDispatcher();
        $runs = 0;
        $dispatcher->add(new Crontab('job', '@every 1s', function () use ($dispatcher, &$runs): void {
            $runs++;
            if ($runs === 1) {
                $dispatcher->tick(100);
            }
        }));

        $dispatcher->tick(100);
        $this->assertSame(1, $runs);
    }

    public function testRedisLockLetsOnlyOneDispatcherWinTheSlot(): void
    {
        $this->runInCoroutine(function (): void {
            $redis = new \Yii2S\Swoole\Redis\CoroutineRedisConnection(self::getParam('redis'));
            $name = 'lock-' . bin2hex(random_bytes(4));
            $runs = 0;
            $started = new \Swoole\Coroutine\Channel(1);
            $gate = new \Swoole\Coroutine\Channel(1);
            $callback = function () use (&$runs, $started, $gate): void {
                $runs++;
                if ($runs === 1) {
                    $started->push(true);
                    $gate->pop(2);
                }
            };
            $first = new CrontabDispatcher($redis);
            $second = new CrontabDispatcher($redis);
            $first->add(new Crontab($name, '@every 1s', $callback));
            $second->add(new Crontab($name, '@every 1s', $callback));

            try {
                \Swoole\Coroutine::create(static function () use ($first): void {
                    $first->tick(1_700_000_000);
                });
                $started->pop(2);
                $second->tick(1_700_000_001);
                $this->assertSame(1, $runs, 'a running task keeps the lock past that one second');
                $gate->push(true);
                \Swoole\Coroutine::sleep(0.05);
                $second->tick(1_700_000_002);
                $this->assertSame(2, $runs, 'the lock is released when the callback returns');
            } finally {
                $redis->del('yii2s:crontab:' . $name);
            }
        });
    }
}
