<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Db;

use yii\db\Command;
use yii\db\Exception as DbException;

/**
 * A [[Command]] that self-heals the pool when a pooled connection dies.
 *
 * A MySQL restart, or a server-side idle timeout, leaves a "gone away" PDO
 * behind: the local connection object still looks open, but every query
 * throws. Without this wrapper the dead connection is returned to the pool at
 * the end of the request and reused by every following request, so one restart
 * poisons the pool until the process itself is restarted.
 *
 * [[execute()]] and [[queryInternal()]] detect a connection-loss error
 * (SQLSTATE class `08`, or MySQL/MariaDB codes 2006/2013), discard the broken
 * connection so the next borrow creates a fresh one, then rethrow the original
 * error. The failing statement is **not** retried: a retried INSERT/UPDATE
 * could be applied twice. The request fails once, the pool recovers
 * immediately.
 */
class CoroutineCommand extends Command
{
    private ?CoroutineDbConnection $owner = null;

    /**
     * Attaches the owning pooled connection, used to discard the broken
     * connection on a connection-loss error.
     */
    public function setOwner(CoroutineDbConnection $owner): void
    {
        $this->owner = $owner;
    }

    public function execute()
    {
        try {
            return parent::execute();
        } catch (\Throwable $e) {
            $this->discardIfConnectionLost($e);
            throw $e;
        }
    }

    protected function queryInternal($method, $fetchMode = null)
    {
        try {
            return parent::queryInternal($method, $fetchMode);
        } catch (\Throwable $e) {
            $this->discardIfConnectionLost($e);
            throw $e;
        }
    }

    private function discardIfConnectionLost(\Throwable $e): void
    {
        if ($this->owner !== null && self::isConnectionLost($e)) {
            $this->owner->discardCurrent();
        }
    }

    /**
     * Whether an exception means the underlying connection is broken, as
     * opposed to a SQL error (constraint violation, deadlock, syntax error)
     * that leaves the connection fully usable and must not be discarded.
     */
    public static function isConnectionLost(\Throwable $e): bool
    {
        if ($e instanceof DbException || $e instanceof \PDOException) {
            $errorInfo = $e->errorInfo;
            $sqlState = $errorInfo[0] ?? null;
            $driverCode = $errorInfo[1] ?? null;

            // SQL standard: SQLSTATE class '08' is the connection-exception
            // class (e.g. 08S01 "communication link failure").
            if (is_string($sqlState) && str_starts_with($sqlState, '08')) {
                return true;
            }

            // MySQL/MariaDB surface their connection losses under HY000, so
            // fall back to the driver-specific codes.
            if (in_array((int) $driverCode, [2006, 2013], true)) {
                return true;
            }
        }

        return false;
    }
}
