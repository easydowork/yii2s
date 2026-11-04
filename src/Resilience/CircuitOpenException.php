<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Resilience;

/**
 * Thrown by [[CircuitBreaker]] when a call is rejected because the circuit is
 * open. A clear, typed signal that a dependency is being skipped, so callers
 * can degrade gracefully instead of surfacing a generic 500.
 */
class CircuitOpenException extends \RuntimeException
{
    private float $cooldownSeconds;

    public function __construct(float $cooldownSeconds, ?\Throwable $previous = null)
    {
        $this->cooldownSeconds = $cooldownSeconds;

        parent::__construct(
            sprintf('Circuit breaker is open (retrying in %.1fs).', $cooldownSeconds),
            0,
            $previous
        );
    }

    public function getCooldownSeconds(): float
    {
        return $this->cooldownSeconds;
    }
}
