<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Resilience;

/**
 * Retries a fallible operation with exponential backoff, optionally only for
 * a whitelist of retryable exception types.
 */
class Retry
{
    /**
     * @param callable $operation the fallible work: `function (): mixed`
     * @param int $maxAttempts total attempts (default 3)
     * @param array<class-string<\Throwable>> $retryable only these exception
     * classes trigger a retry (empty = retry everything)
     * @param float $baseDelaySeconds base delay for the exponential backoff
     * @param float $maxDelaySeconds upper bound on the backoff delay
     * @return mixed the operation result
     * @throws \Throwable the last failure once attempts are exhausted (or the
     * error is not retryable)
     */
    public function call(
        callable $operation,
        int $maxAttempts = 3,
        array $retryable = [],
        float $baseDelaySeconds = 0.1,
        float $maxDelaySeconds = 5.0,
    ): mixed {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }
        $baseDelaySeconds = max(0.0, $baseDelaySeconds);
        $maxDelaySeconds = max(0.0, $maxDelaySeconds);

        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $operation();
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts || ($retryable !== [] && !$this->isRetryable($e, $retryable))) {
                    throw $e;
                }
                $this->sleep($this->backoff($attempt, $baseDelaySeconds, $maxDelaySeconds));
            }
        }
    }

    /**
     * @param array<class-string<\Throwable>> $retryable
     */
    private function isRetryable(\Throwable $e, array $retryable): bool
    {
        foreach ($retryable as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function backoff(int $attempt, float $base, float $max): float
    {
        return min($max, $base * (2 ** ($attempt - 1)));
    }

    private function sleep(float $seconds): void
    {
        \Yii2S\Swoole\Coroutine\Sleep::sleep($seconds);
    }
}
