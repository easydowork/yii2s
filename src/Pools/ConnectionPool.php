<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Pools;

/**
 * Generic coroutine-safe connection pool built on a Swoole channel.
 *
 * The pool lazily creates connections up to [[maxActive]]. When the pool is
 * exhausted, borrowers block until a connection is returned or the wait
 * timeout elapses.
 *
 * Borrow and return must always be paired, including on exception paths.
 */
class ConnectionPool
{
    public const DEFAULT_MAX_ACTIVE = 20;

    public const DEFAULT_WAIT_TIMEOUT = 3.0;

    public const DEFAULT_MIN_IDLE = 0;

    /** 0 disables max-lifetime recycling. */
    public const DEFAULT_MAX_LIFETIME = 0.0;

    /** 0 disables idle keepalive. */
    public const DEFAULT_IDLE_TIMEOUT = 0.0;

    private \Swoole\Coroutine\Channel $channel;

    private int $maxActive = self::DEFAULT_MAX_ACTIVE;

    private float $waitTimeout = self::DEFAULT_WAIT_TIMEOUT;

    private int $minIdle = self::DEFAULT_MIN_IDLE;

    private float $maxLifetime = self::DEFAULT_MAX_LIFETIME;

    private float $idleTimeout = self::DEFAULT_IDLE_TIMEOUT;

    private \Closure $factory;

    private ?\Closure $destroyer = null;

    private ?\Closure $validator = null;

    /**
     * Number of connections created by this pool (in use + idle).
     */
    private int $size = 0;

    private bool $closed = false;

    private int $totalCreated = 0;

    private int $totalBorrowed = 0;

    private int $totalTimeouts = 0;

    private int $totalDiscarded = 0;

    private int $waiting = 0;

    /**
     * Borrowed connections indexed by spl_object_id, each holding the
     * connection and the coroutine id that borrowed it. [[sweep()]] uses the
     * mapping to reclaim connections whose borrowing coroutine died without
     * returning them (e.g. sub-coroutines whose connections nobody releases
     * explicitly).
     *
     * Note: a plain array is used deliberately - WeakMap writes performed in
     * child coroutines may not be visible to other coroutines, which would
     * break the tracking.
     *
     * @var array<int, array{0: object, 1: int}>
     */
    private array $borrowed = [];

    /**
     * Per-connection timestamps keyed by spl_object_id, used for max-lifetime
     * recycling and idle keepalive.
     *
     * @var array<int, array{created: float, lastUsed: float}>
     */
    private array $meta = [];

    /**
     * @param int $maxActive maximum number of connections managed by the pool
     * @param float $waitTimeout seconds to wait for a free connection before failing
     * @param \Closure $factory creates a new connection: `function (): object`
     * @param \Closure|null $destroyer destroys a connection: `function (object $connection): void`
     * @param \Closure|null $validator validates an idle connection before reuse:
     * `function (object $connection): bool`
     * @param int $minIdle minimum idle connections to maintain (see [[maintain()]])
     * @param float $maxLifetime recycle connections older than this many seconds
     * (0 disables)
     * @param float $idleTimeout re-validate idle connections older than this many
     * seconds (0 disables); requires a validator to be useful
     */
    public function __construct(
        \Closure $factory,
        int $maxActive = self::DEFAULT_MAX_ACTIVE,
        float $waitTimeout = self::DEFAULT_WAIT_TIMEOUT,
        ?\Closure $destroyer = null,
        ?\Closure $validator = null,
        int $minIdle = self::DEFAULT_MIN_IDLE,
        float $maxLifetime = self::DEFAULT_MAX_LIFETIME,
        float $idleTimeout = self::DEFAULT_IDLE_TIMEOUT,
    ) {
        $this->factory = $factory;
        $this->maxActive = max(1, $maxActive);
        $this->waitTimeout = max(0.0, $waitTimeout);
        $this->minIdle = max(0, min($minIdle, $this->maxActive));
        $this->maxLifetime = max(0.0, $maxLifetime);
        $this->idleTimeout = max(0.0, $idleTimeout);
        $this->destroyer = $destroyer;
        $this->validator = $validator;
        $this->channel = new \Swoole\Coroutine\Channel($this->maxActive);
    }

    /**
     * Borrows a connection from the pool.
     *
     * @throws \RuntimeException when the pool is closed or the wait timeout is reached
     */
    public function borrow(): object
    {
        if ($this->closed) {
            throw new \RuntimeException('Connection pool is closed.');
        }

        // Fast path: reuse an idle connection without waiting.
        $connection = $this->channel->pop(0.001);
        if ($connection !== false) {
            if (!$this->isExpired($connection) && $this->isValid($connection)) {
                return $this->take($connection);
            }
            $this->destroy($connection);
        }

        if ($this->size < $this->maxActive) {
            return $this->take($this->create());
        }

        // Pool exhausted: wait for a returned connection.
        $this->waiting++;
        try {
            $deadline = microtime(true) + $this->waitTimeout;
            while (true) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    $this->totalTimeouts++;
                    throw new \RuntimeException(sprintf(
                        'Connection pool exhausted: max %d active connections, waited %.2fs.',
                        $this->maxActive,
                        $this->waitTimeout
                    ));
                }

                $connection = $this->channel->pop($remaining);
                if ($connection === false) {
                    continue;
                }

                if (!$this->isExpired($connection) && $this->isValid($connection)) {
                    return $this->take($connection);
                }

                $this->destroy($connection);
                if ($this->size < $this->maxActive) {
                    return $this->take($this->create());
                }
            }
        } finally {
            $this->waiting--;
        }
    }

    /**
     * Completes a successful borrow: registers the connection in the tracking
     * table and updates the counters.
     */
    private function take(object $connection): object
    {
        $this->totalBorrowed++;
        $id = spl_object_id($connection);
        $this->borrowed[$id] = [$connection, \Swoole\Coroutine::getCid()];
        $this->meta[$id]['lastUsed'] = microtime(true);

        return $connection;
    }

    /**
     * Reclaims connections whose borrowing coroutine is gone.
     *
     * Sub-coroutines may borrow connections into their own context (every
     * Yii2 DB query does); when such a coroutine dies, nothing releases its
     * connection and it would leak forever. The request manager calls this
     * after every request; reclaimed connections are destroyed and rebuilt
     * on demand, so correctness is preferred over reuse here.
     *
     * @return int number of reclaimed connections
     */
    public function sweep(): int
    {
        // Fast path: nothing borrowed, nothing to reclaim. Avoids the
        // listCoroutines() scan, which is per-request overhead on stateless
        // endpoints that never borrow a connection.
        if ($this->borrowed === []) {
            return 0;
        }

        $alive = [];
        foreach (\Swoole\Coroutine::listCoroutines() as $cid) {
            $alive[$cid] = true;
        }

        $reclaimed = 0;
        foreach ($this->borrowed as $id => [$connection, $cid]) {
            if (isset($alive[$cid])) {
                continue; // borrower still alive and owns the connection
            }

            unset($this->borrowed[$id]);
            $this->destroy($connection);
            $reclaimed++;
        }

        return $reclaimed;
    }

    /**
     * Returns a borrowed connection to the pool. Connections that exceeded
     * their max lifetime are recycled instead of being reused.
     */
    public function return(object $connection): void
    {
        unset($this->borrowed[spl_object_id($connection)]);
        if ($this->closed || $this->isExpired($connection) || !$this->channel->push($connection)) {
            $this->destroy($connection);
        }
    }

    /**
     * Destroys a connection and shrinks the pool so a replacement can be created.
     */
    public function discard(object $connection): void
    {
        $this->destroy($connection);
    }

    /**
     * Closes the pool and destroys every idle connection.
     * Borrowed connections are not tracked and must be destroyed by their owners.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        // Swoole channel APIs must be called inside a coroutine. Outside of
        // one (plain CLI usage) idle connections are simply abandoned and
        // garbage collected together with the channel.
        if (\Swoole\Coroutine::getCid() === -1) {
            return;
        }

        while (($connection = $this->channel->pop(0.001)) !== false) {
            $this->destroy($connection);
        }
        $this->channel->close();
    }

    /**
     * @return int number of idle connections currently in the pool
     */
    public function getIdleCount(): int
    {
        return $this->channel->length();
    }

    /**
     * @return int number of connections created by the pool (idle + in use)
     */
    public function getSize(): int
    {
        return $this->size;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Current pool metrics, for the debug endpoint and monitoring.
     *
     * @return array<string, int|bool>
     */
    public function getMetrics(): array
    {
        $idle = $this->getIdleCount();

        return [
            'max_active' => $this->maxActive,
            'min_idle' => $this->minIdle,        // idle watermark target
            'size' => $this->size,               // connections that exist now
            'active' => max(0, $this->size - $idle), // currently borrowed
            'idle' => $idle,
            'waiting' => $this->waiting,         // coroutines blocked in borrow()
            'closed' => $this->closed,
            'total_created' => $this->totalCreated,
            'total_borrowed' => $this->totalBorrowed,
            'total_timeouts' => $this->totalTimeouts,
            'total_discarded' => $this->totalDiscarded,
        ];
    }

    private function create(): object
    {
        $this->size++;
        try {
            $connection = ($this->factory)();
            $this->totalCreated++;
            $now = microtime(true);
            $this->meta[spl_object_id($connection)] = ['created' => $now, 'lastUsed' => $now];

            return $connection;
        } catch (\Throwable $e) {
            $this->size--;
            throw $e;
        }
    }

    private function isValid(object $connection): bool
    {
        if ($this->validator === null) {
            return true;
        }
        try {
            return (bool) ($this->validator)($connection);
        } catch (\Throwable) {
            return false;
        }
    }

    private function destroy(object $connection): void
    {
        unset($this->borrowed[spl_object_id($connection)]);
        unset($this->meta[spl_object_id($connection)]);
        if ($this->destroyer !== null) {
            try {
                ($this->destroyer)($connection);
            } catch (\Throwable) {
                // Never let cleanup errors break the caller.
            }
        }
        if ($this->size > 0) {
            $this->size--;
        }
        $this->totalDiscarded++;
    }

    /**
     * True when the connection has exceeded [[maxLifetime]] and must be
     * recycled. Always false when maxLifetime is 0 (disabled).
     */
    private function isExpired(object $connection): bool
    {
        if ($this->maxLifetime <= 0.0) {
            return false;
        }

        $created = $this->meta[spl_object_id($connection)]['created'] ?? microtime(true);

        return microtime(true) - $created >= $this->maxLifetime;
    }

    /**
     * True when the connection has been idle longer than [[idleTimeout]] and
     * should be re-validated before reuse. Always false when idleTimeout is 0.
     */
    private function isIdleStale(object $connection): bool
    {
        if ($this->idleTimeout <= 0.0) {
            return false;
        }

        $lastUsed = $this->meta[spl_object_id($connection)]['lastUsed'] ?? microtime(true);

        return microtime(true) - $lastUsed >= $this->idleTimeout;
    }

    /**
     * Periodic upkeep, called by the server's pool keeper on a fixed cadence.
     *
     * Drains the idle connections, recycles the expired ones and re-validates
     * the long-idle ones (idle keepalive), then replenishes the idle channel up
     * to [[minIdle]]. Must run inside a coroutine.
     *
     * @return int number of connections recycled (destroyed) during this pass
     */
    public function maintain(): int
    {
        if ($this->closed) {
            return 0;
        }

        $recycled = 0;

        // Drain the idle channel so every idle connection can be inspected
        // without racing a concurrent borrow.
        $idle = [];
        while (($connection = $this->channel->pop(0.001)) !== false) {
            $idle[] = $connection;
        }

        foreach ($idle as $connection) {
            if ($this->isExpired($connection)
                || ($this->isIdleStale($connection) && !$this->isValid($connection))
            ) {
                $this->destroy($connection);
                $recycled++;
                continue;
            }
            $this->channel->push($connection);
        }

        // Replenish the idle channel up to the min-idle watermark.
        while ($this->getIdleCount() < $this->minIdle && $this->size < $this->maxActive) {
            $this->channel->push($this->create());
        }

        return $recycled;
    }
}
