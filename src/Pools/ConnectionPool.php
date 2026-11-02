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
                return $connection;
            }
            $this->discard($connection);
        }

        if ($this->size < $this->maxActive) {
            return $this->create();
        }

        // Pool exhausted: wait for a returned connection.
        $deadline = microtime(true) + $this->waitTimeout;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
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
                return $connection;
            }

            $this->discard($connection);
            if ($this->size < $this->maxActive) {
                return $this->create();
            }
        }
    }

    /**
     * Returns a borrowed connection to the pool.
     */
    public function return(object $connection): void
    {
        if ($this->closed || !$this->channel->push($connection)) {
            $this->destroy($connection);
        }
    }

    /**
     * Destroys a connection and shrinks the pool so a replacement can be created.
     */
    public function discard(object $connection): void
    {
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

    private function create(): object
    {
        $this->size++;
        try {
            return ($this->factory)();
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
    }
}
