<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\runtime;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use yii\base\InvalidCallException;
use yii\base\Widget;
use yii2stest\TestCase;

class WidgetStackTest extends TestCase
{
    public function testNestedBeginEndStaysOrdered(): void
    {
        $outer = StackOuter::begin();
        $inner = StackInner::begin();
        $this->assertSame($inner, StackInner::end());
        $this->assertSame($outer, StackOuter::end());
    }

    public function testEndWithoutBeginThrows(): void
    {
        $this->expectException(InvalidCallException::class);
        StackOuter::end();
    }

    public function testConcurrentBeginEndDoesNotCross(): void
    {
        $this->runInCoroutine(function (): void {
            $done = new Channel(2);

            Coroutine::create(function () use ($done): void {
                $widget = StackOuter::begin();
                Coroutine::sleep(0.02);
                try {
                    $ended = StackOuter::end();
                    $done->push($ended === $widget);
                } catch (\Throwable $e) {
                    $done->push($e->getMessage());
                }
            });
            Coroutine::create(function () use ($done): void {
                Coroutine::sleep(0.01);
                $widget = StackInner::begin();
                Coroutine::sleep(0.03);
                try {
                    $ended = StackInner::end();
                    $done->push($ended === $widget);
                } catch (\Throwable $e) {
                    $done->push($e->getMessage());
                }
            });

            $this->assertTrue($done->pop(1));
            $this->assertTrue($done->pop(1));
        });
    }
}

class StackOuter extends Widget
{
    public function run(): string
    {
        return '';
    }
}

class StackInner extends Widget
{
    public function run(): string
    {
        return '';
    }
}
