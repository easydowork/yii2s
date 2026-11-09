<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Resilience;

/**
 * A small, coroutine-friendly circuit breaker.
 *
 * It wraps a fallible operation (a DB query, a Redis command, an upstream
 * call) and, after a configurable number of consecutive failures, "opens" and
 * fails fast - with a typed [[CircuitOpenException]] or an optional fallback -
 * instead of hammering a down service on every request. After a cooldown it
 * allows one probe ("half-open"): a success closes the circuit again, a failure
 * re-opens it.
 *
 * The counters are plain integers: Swoole coroutines only switch at I/O yield
 * points and never between the opcodes of a single increment, so the
 * read-modify-write here is safe without locks.
 */
class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';

    public const STATE_OPEN = 'open';

    public const STATE_HALF_OPEN = 'half_open';

    private int $failureThreshold;

    private float $cooldownSeconds;

    private int $consecutiveFailures = 0;

    private float $openedAt = 0.0;

    private string $state = self::STATE_CLOSED;

    /**
     * True while the single half-open probe is inside its operation.
     * Other callers fail fast until that probe returns.
     */
    private bool $probeInFlight = false;

    public function __construct(int $failureThreshold = 5, float $cooldownSeconds = 30.0)
    {
        $this->failureThreshold = max(1, $failureThreshold);
        $this->cooldownSeconds = max(0.0, $cooldownSeconds);
    }

    /**
     * Runs `$operation` through the circuit. When the circuit is open it is
     * skipped entirely: `$fallback` (if given) runs, otherwise a
     * [[CircuitOpenException]] is thrown.
     *
     * @param callable $operation the fallible work: `function (): mixed`
     * @param callable|null $fallback runs when the circuit is open or the
     * operation failed: `function (?\Throwable $e = null): mixed`
     * @param callable|null $countsAsFailure decides whether a thrown error
     * counts toward opening the circuit: `function (\Throwable $e): bool`.
     * Defaults to counting every error (e.g. a connection refused); a DB
     * wrapper can pass a predicate so plain SQL errors (constraint violations,
     * deadlocks) do not trip the circuit.
     * @return mixed the operation or fallback result
     * @throws \Throwable the operation error when there is no fallback
     */
    public function call(callable $operation, ?callable $fallback = null, ?callable $countsAsFailure = null): mixed
    {
        if (!$this->beginCall()) {
            return $this->failFast($fallback, null);
        }

        try {
            $result = $operation();
        } catch (\Throwable $e) {
            $this->endCall();
            if ($countsAsFailure === null || $countsAsFailure($e)) {
                $this->recordFailure();
            }

            return $fallback !== null ? $fallback($e) : throw $e;
        }

        $this->endCall();
        $this->recordSuccess();

        return $result;
    }

    /**
     * Decides whether this caller may run the operation. The transition into
     * the half-open probe happens before the operation yields, so a second
     * coroutine observes [[$probeInFlight]] and does not start another probe.
     */
    private function beginCall(): bool
    {
        if ($this->state === self::STATE_OPEN) {
            if (microtime(true) - $this->openedAt < $this->cooldownSeconds) {
                return false;
            }
            $this->state = self::STATE_HALF_OPEN;
            $this->probeInFlight = true;

            return true;
        }

        if ($this->state === self::STATE_HALF_OPEN) {
            if ($this->probeInFlight) {
                return false;
            }
            $this->probeInFlight = true;
        }

        return true;
    }

    private function endCall(): void
    {
        $this->probeInFlight = false;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    private function recordFailure(): void
    {
        $this->consecutiveFailures++;
        if ($this->state === self::STATE_HALF_OPEN || $this->consecutiveFailures >= $this->failureThreshold) {
            $this->state = self::STATE_OPEN;
            $this->openedAt = microtime(true);
        }
    }

    private function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->state = self::STATE_CLOSED;
    }

    /**
     * @param callable|null $fallback
     * @param \Throwable|null $cause the operation error, when known
     */
    private function failFast(?callable $fallback, ?\Throwable $cause): mixed
    {
        if ($fallback !== null) {
            return $fallback($cause);
        }

        throw new CircuitOpenException($this->cooldownSeconds, $cause);
    }
}
