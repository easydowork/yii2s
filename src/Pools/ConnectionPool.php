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

    private \Swoole\Coroutine\Channel $channel;

    private int $maxActive = self::DEFAULT_MAX_ACTIVE;

    private float $waitTimeout = self::DEFAULT_WAIT_TIMEOUT;

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
     * child coroutines are not visible in other coroutines on the Windows
     * (msys2) swoole-cli build, which both breaks the tracking and crashes
     * the process.
     *
     * @var array<int, array{0: object, 1: int}>
     */
    private array $borrowed = [];

    /**
     * @param int $maxActive maximum number of connections managed by the pool
     * @param float $waitTimeout seconds to wait for a free connection before failing
     * @param \Closure $factory creates a new connection: `function (): object`
     * @param \Closure|null $destroyer destroys a connection: `function (object $connection): void`
     * @param \Closure|null $validator validates an idle connection before reuse:
     * `function (object $connection): bool`
     */
    public function __construct(
        \Closure $factory,
        int $maxActive = self::DEFAULT_MAX_ACTIVE,
        float $waitTimeout = self::DEFAULT_WAIT_TIMEOUT,
        ?\Closure $destroyer = null,
        ?\Closure $validator = null,
    ) {
        $this->factory = $factory;
        $this->maxActive = max(1, $maxActive);
        $this->waitTimeout = max(0.0, $waitTimeout);
        $this->destroyer = $destroyer;
        $this->validator = $validator;
        $this->maxActive = max(1, $maxActive);
        $this->waitTimeout = max(0.0, $waitTimeout);
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
            if ($this->isValid($connection)) {
                $this->totalBorrowed++;
                $this->trackBorrow($connection);
                return $connection;
            }
            $this->discard($connection);
        }

        if ($this->size < $this->maxActive) {
            $connection = $this->create();
            $this->totalBorrowed++;
            $this->trackBorrow($connection);
            return $connection;
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

                if ($this->isValid($connection)) {
                    $this->totalBorrowed++;
                    $this->trackBorrow($connection);
                    return $connection;
                }

                $this->discard($connection);
                if ($this->size < $this->maxActive) {
                    $connection = $this->create();
                    $this->totalBorrowed++;
                    $this->trackBorrow($connection);
                    return $connection;
                }
            }
        } finally {
            $this->waiting--;
        }
    }

    /**
     * Marks the connection as borrowed by the current coroutine.
     */
    private function trackBorrow(object $connection): void
    {
        $this->borrowed[spl_object_id($connection)] = [$connection, \Swoole\Coroutine::getCid()];
    }

    /**
     * Reclaims connections whose borrowing coroutine is gone.
     *
     * Sub-coroutines may borrow connections into their own context (every
     * Yii2 DB query does); when such a coroutine dies, nothing releases its
     * connection and it would leak forever. The request manager calls this
     * after every request; destroyed connections are rebuilt on demand, so
     * correctness is preferred over reuse here.
     *
     * @return int number of reclaimed connections
     */
    public function sweep(): int
    {
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
            if ($this->size > 0) {
                $this->size--;
            }
            $this->totalDiscarded++;
            $reclaimed++;
        }

        return $reclaimed;
    }

    /**
     * Returns a borrowed connection to the pool.
     */
    public function return(object $connection): void
    {
        unset($this->borrowed[spl_object_id($connection)]);
        if ($this->closed || !$this->channel->push($connection)) {
            $this->destroy($connection);
        }
    }

    /**
     * Destroys a connection and shrinks the pool so a replacement can be created.
     */
    public function discard(object $connection): void
    {
        unset($this->borrowed[spl_object_id($connection)]);
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
        if ($this->destroyer === null) {
            return;
        }
        try {
            ($this->destroyer)($connection);
        } catch (\Throwable) {
            // Swallow cleanup errors by design.
        }
        if ($this->size > 0) {
            $this->size--;
        }
        $this->totalDiscarded++;
    }
}
