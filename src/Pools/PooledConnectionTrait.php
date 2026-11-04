<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Pools;

use Yii2S\Swoole\Resilience\CircuitBreaker;

/**
 * Shared, type-agnostic pool configuration for the DB and Redis components.
 *
 * Only the truly symmetric surface lives here: the pool/circuit configuration
 * knobs and the circuit-breaker accessor. The type-specific plumbing
 * (`getPool()`, `borrow()`, `release()`, `closePool()`) stays in each component
 * because it wraps a different connection type and reads more clearly inline.
 */
trait PooledConnectionTrait
{
    /** @var int maximum number of pooled connections */
    public int $poolMaxActive = ConnectionPool::DEFAULT_MAX_ACTIVE;

    /** @var float seconds to wait for a pooled connection before failing */
    public float $poolWaitTimeout = ConnectionPool::DEFAULT_WAIT_TIMEOUT;

    /** @var bool whether to borrow a dedicated connection per coroutine */
    public bool $enableCoroutinePooling = true;

    /** @var bool whether idle connections are validated before reuse */
    public bool $enableHealthCheck = false;

    /** @var int minimum idle connections to maintain (0 = no minimum) */
    public int $poolMinIdle = ConnectionPool::DEFAULT_MIN_IDLE;

    /** @var float recycle connections older than this many seconds (0 = disabled) */
    public float $poolMaxLifetime = ConnectionPool::DEFAULT_MAX_LIFETIME;

    /** @var float re-validate idle connections older than this many seconds (0 = disabled) */
    public float $poolIdleTimeout = ConnectionPool::DEFAULT_IDLE_TIMEOUT;

    /** @var bool whether to guard acquisition/execution with a circuit breaker */
    public bool $enableCircuitBreaker = false;

    /** @var int consecutive failures before the circuit opens */
    public int $circuitFailureThreshold = 5;

    /** @var float seconds the circuit stays open before allowing a probe */
    public float $circuitCooldown = 30.0;

    private ?ConnectionPool $pool = null;

    private ?CircuitBreaker $circuitBreaker = null;

    /**
     * @return CircuitBreaker|null the configured circuit breaker, or null when
     * [[enableCircuitBreaker]] is false
     */
    public function getCircuitBreaker(): ?CircuitBreaker
    {
        if (!$this->enableCircuitBreaker) {
            return null;
        }

        return $this->circuitBreaker ??= new CircuitBreaker($this->circuitFailureThreshold, $this->circuitCooldown);
    }
}
