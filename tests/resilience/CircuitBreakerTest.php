<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\resilience;

use Yii2S\Swoole\Resilience\CircuitBreaker;
use Yii2S\Swoole\Resilience\CircuitOpenException;
use yii2stest\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function testCallPassesThroughOnSuccess(): void
    {
        $breaker = new CircuitBreaker(3, 1.0);

        $result = $breaker->call(fn (): string => 'ok');

        $this->assertSame('ok', $result);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->getState());
        $this->assertSame(0, $breaker->getConsecutiveFailures());
    }

    public function testCircuitOpensAfterConsecutiveFailures(): void
    {
        $breaker = new CircuitBreaker(3, 1.0);

        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->call(static fn () => throw new \RuntimeException('down'));
            } catch (\RuntimeException) {
            }
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->getState());
        $this->assertSame(3, $breaker->getConsecutiveFailures());

        $this->expectException(CircuitOpenException::class);
        $breaker->call(static fn (): string => 'never runs');
    }

    public function testFallbackRunsWhenCircuitIsOpen(): void
    {
        $breaker = new CircuitBreaker(1, 10.0);

        try {
            $breaker->call(static fn () => throw new \RuntimeException('down'));
        } catch (\RuntimeException) {
        }
        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->getState());

        // The operation is skipped while the circuit is open; the fallback runs.
        $result = $breaker->call(
            static fn (): string => 'never',
            static fn (): string => 'fallback'
        );
        $this->assertSame('fallback', $result);
    }

    public function testHalfOpenProbeClosesAfterSuccess(): void
    {
        $breaker = new CircuitBreaker(1, 0.05);

        try {
            $breaker->call(static fn () => throw new \RuntimeException('down'));
        } catch (\RuntimeException) {
        }
        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->getState());

        usleep(60_000); // exceed the 50ms cooldown

        $result = $breaker->call(fn (): string => 'recovered');
        $this->assertSame('recovered', $result);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->getState());
    }

    public function testNonCountedErrorDoesNotTripCircuit(): void
    {
        $breaker = new CircuitBreaker(3, 1.0);
        $countsOnlyRuntime = static fn (\Throwable $e): bool => $e instanceof \RuntimeException;

        for ($i = 0; $i < 5; $i++) {
            try {
                $breaker->call(
                    static fn () => throw new \LogicException('sql error'),
                    null,
                    $countsOnlyRuntime
                );
            } catch (\LogicException) {
            }
        }

        // LogicException never counts, so the circuit stays closed.
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->getState());
        $this->assertSame(0, $breaker->getConsecutiveFailures());
    }

    public function testHalfOpenAllowsOnlyOneProbe(): void
    {
        $this->runInCoroutine(function (): void {
            $breaker = new CircuitBreaker(1, 0.01);
            try {
                $breaker->call(static fn () => throw new \RuntimeException('down'));
            } catch (\RuntimeException) {
            }
            \Swoole\Coroutine::sleep(0.02);

            $calls = 0;
            $channel = new \Swoole\Coroutine\Channel(2);
            for ($i = 0; $i < 2; $i++) {
                \Swoole\Coroutine::create(function () use ($breaker, &$calls, $channel): void {
                    $result = $breaker->call(function () use (&$calls): string {
                        $calls++;
                        \Swoole\Coroutine::sleep(0.05);

                        return 'probed';
                    }, static fn (): string => 'blocked');
                    $channel->push($result);
                });
            }

            $results = [$channel->pop(1), $channel->pop(1)];
            sort($results);
            $this->assertSame(['blocked', 'probed'], $results);
            $this->assertSame(1, $calls);
            $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->getState());
        });
    }
}
