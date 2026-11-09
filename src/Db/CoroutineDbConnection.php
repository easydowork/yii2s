<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Db;

use Yii2S\Swoole\Coroutine\CoroutineContext;
use Yii2S\Swoole\Pools\ConnectionPool;
use Yii2S\Swoole\Pools\HasConnectionPool;
use Yii2S\Swoole\Pools\PooledConnectionTrait;
use yii\db\Connection;

/**
 * Coroutine-safe MySQL connection for long-running Swoole workers.
 *
 * The component keeps the exact Yii2 [[Connection]] configuration surface
 * (dsn, username, password, charset, ...) but internally maintains a pool of
 * independent child connections. Each coroutine borrows one connection and
 * keeps it until the coroutine ends or [[release]] is called by the request
 * lifecycle, which guarantees that transactions and multi-statement sequences
 * always run on the same underlying PDO connection.
 *
 * Set [[enableCoroutinePooling]] to `false` to fall back to the standard
 * single-connection behavior for debugging or compatibility.
 */
class CoroutineDbConnection extends Connection implements HasConnectionPool
{
    use PooledConnectionTrait;

    /**
     * @var float log DB statements slower than this many seconds via
     * `Yii::warning` (category `yii2s.db.slow`). 0 disables slow-query logging.
     */
    public float $slowQueryThreshold = 0.0;

    /**
     * Connection used outside of a coroutine context (plain CLI usage), where
     * the Swoole channel-based pool is not available.
     */
    private ?Connection $detachedConnection = null;

    private const CONTEXT_KEY_PREFIX = 'yii2s.db.connection.';

    public function init(): void
    {
        parent::init();
    }

    /**
     * Returns the pool, creating it on first use.
     */
    public function getPool(): ConnectionPool
    {
        if ($this->pool === null) {
            $this->pool = new ConnectionPool(
                fn (): Connection => $this->createPooledConnection(),
                $this->poolSlotLimit(),
                $this->poolWaitTimeout,
                function (Connection $connection): void {
                    $connection->close();
                },
                $this->enableHealthCheck
                    ? function (Connection $connection): bool {
                        try {
                            return $connection->pdo === null
                                || $connection->pdo->query('SELECT 1') !== false;
                        } catch (\Throwable) {
                            return false;
                        }
                    }
                : null,
                $this->poolMinIdle,
                $this->poolMaxLifetime,
                $this->poolIdleTimeout
            );
        }

        return $this->pool;
    }

    /**
     * Borrows the connection bound to the current coroutine (creating it on
     * first use) and keeps it sticky until release.
     */
    public function borrow(): Connection
    {
        if (\Swoole\Coroutine::getCid() === -1) {
            return $this->detachedConnection ??= $this->createPooledConnection();
        }

        $key = self::CONTEXT_KEY_PREFIX . spl_object_id($this);
        $connection = CoroutineContext::get($key);
        if ($connection instanceof Connection) {
            return $connection;
        }

        $connection = $this->getPool()->borrow();
        CoroutineContext::set($key, $connection);

        return $connection;
    }

    /**
     * Returns the coroutine-bound connection to the pool.
     *
     * Called automatically at the end of each HTTP request. Safe to call
     * multiple times.
     */
    public function release(): void
    {
        $key = self::CONTEXT_KEY_PREFIX . spl_object_id($this);
        $connection = CoroutineContext::get($key);
        if ($connection instanceof Connection) {
            $transaction = $connection->getTransaction();
            if ($transaction !== null) {
                // A transaction leaked across the request boundary: roll it
                // back before the connection is reused by another coroutine.
                try {
                    $transaction->rollBack();
                } catch (\Throwable) {
                }
            }
            CoroutineContext::remove($key);
            $this->getPool()->return($connection);
        }
    }

    /**
     * Discards the coroutine-bound connection without returning it to the
     * pool, because it is known to be broken (e.g. the server went away).
     *
     * Called by [[CoroutineCommand]] when a statement fails with a
     * connection-loss error. Safe to call multiple times.
     */
    public function discardCurrent(): void
    {
        $key = self::CONTEXT_KEY_PREFIX . spl_object_id($this);
        $connection = CoroutineContext::get($key);
        if ($connection instanceof Connection) {
            CoroutineContext::remove($key);
            $this->getPool()->discard($connection);
        }
    }

    /**
     * Closes the pool and all its connections.
     */
    public function closePool(): void
    {
        $this->release();
        $this->pool?->close();
        $this->pool = null;

        if ($this->detachedConnection !== null) {
            $this->detachedConnection->close();
            $this->detachedConnection = null;
        }
    }

    public function getIsActive(): bool
    {
        if (!$this->enableCoroutinePooling) {
            return parent::getIsActive();
        }
        if ($this->detachedConnection !== null && $this->detachedConnection->getIsActive()) {
            return true;
        }

        return CoroutineContext::get(self::CONTEXT_KEY_PREFIX . spl_object_id($this)) !== null
            || $this->getPool()->getIdleCount() > 0;
    }

    public function open(): void
    {
        if (!$this->enableCoroutinePooling) {
            parent::open();
            return;
        }
        $this->borrow()->open();
    }

    public function close(): void
    {
        if (!$this->enableCoroutinePooling) {
            parent::close();
            return;
        }
        $this->closePool();
    }

    public function createCommand($sql = null, $params = [])
    {
        if (!$this->enableCoroutinePooling) {
            return parent::createCommand($sql, $params);
        }

        $command = $this->borrow()->createCommand($sql, $params);
        if ($command instanceof CoroutineCommand) {
            $command->setOwner($this);
        }

        return $command;
    }

    public function beginTransaction($isolationLevel = null)
    {
        if (!$this->enableCoroutinePooling) {
            return parent::beginTransaction($isolationLevel);
        }

        return $this->borrow()->beginTransaction($isolationLevel);
    }

    // Note: yii\db\Connection intentionally has no commit()/rollBack();
    // transactions are driven through the Transaction object returned by
    // [[getTransaction()]], which is coroutine-bound via [[borrow()]].

    public function getTransaction(): ?\yii\db\Transaction
    {
        if (!$this->enableCoroutinePooling) {
            return parent::getTransaction();
        }

        return $this->borrow()->getTransaction();
    }

    public function getSchema(): \yii\db\Schema
    {
        if (!$this->enableCoroutinePooling) {
            return parent::getSchema();
        }

        return $this->borrow()->getSchema();
    }

    public function getDriverName(): string
    {
        if (!$this->enableCoroutinePooling) {
            return parent::getDriverName();
        }

        return $this->borrow()->getDriverName();
    }

    public function getLastInsertID($sequenceName = ''): string
    {
        if (!$this->enableCoroutinePooling) {
            return parent::getLastInsertID($sequenceName);
        }

        return $this->borrow()->getLastInsertID($sequenceName);
    }

    public function quoteValue($value): string
    {
        if (!$this->enableCoroutinePooling) {
            return parent::quoteValue($value);
        }

        return $this->borrow()->quoteValue($value);
    }

    public function quoteTableName($name): string
    {
        if (!$this->enableCoroutinePooling) {
            return parent::quoteTableName($name);
        }

        return $this->borrow()->quoteTableName($name);
    }

    public function quoteColumnName($name): string
    {
        if (!$this->enableCoroutinePooling) {
            return parent::quoteColumnName($name);
        }

        return $this->borrow()->quoteColumnName($name);
    }

    public function quoteSql($sql): string
    {
        if (!$this->enableCoroutinePooling) {
            return parent::quoteSql($sql);
        }

        return $this->borrow()->quoteSql($sql);
    }

    /**
     * How many pooled connection objects fit in [[poolMaxActive]].
     *
     * `poolMaxActive` is the MySQL connection budget. A pooled object with
     * slaves enabled opens a master PDO and a slave PDO, so each object
     * spends two sockets. The pool therefore holds half as many objects.
     * Routing between those sockets is still Yii's own master/slave logic;
     * this only keeps the socket count inside the budget.
     */
    private function poolSlotLimit(): int
    {
        $socketsPerObject = ($this->enableSlaves && $this->slaves !== []) ? 2 : 1;

        return max(1, intdiv($this->poolMaxActive, $socketsPerObject));
    }

    /**
     * Creates a fully independent child connection sharing this component's
     * configuration.
     */
    private function createPooledConnection(): Connection
    {
        return new Connection([
            'dsn' => $this->dsn,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => $this->charset,
            'tablePrefix' => $this->tablePrefix,
            'attributes' => $this->attributes,
            'emulatePrepare' => $this->emulatePrepare,
            'pdoClass' => $this->pdoClass,
            // Read/write split (masters/slaves) must be carried over, otherwise
            // a configured replication setup silently degrades to a single DSN.
            'masters' => $this->masters,
            'slaves' => $this->slaves,
            'masterConfig' => $this->masterConfig,
            'slaveConfig' => $this->slaveConfig,
            'enableSlaves' => $this->enableSlaves,
            'enableSchemaCache' => $this->enableSchemaCache,
            'schemaCache' => $this->schemaCache,
            'schemaCacheDuration' => $this->schemaCacheDuration,
            'schemaCacheExclude' => $this->schemaCacheExclude,
            'enableQueryCache' => $this->enableQueryCache,
            'queryCache' => $this->queryCache,
            'queryCacheDuration' => $this->queryCacheDuration,
            'enableLogging' => $this->enableLogging,
            'enableProfiling' => $this->enableProfiling,
            // Upgrade the default command to the self-healing variant so a
            // dead connection is discarded instead of reused. A custom
            // commandClass is honoured as-is (its author owns the behavior).
            'commandClass' => ($this->commandClass === 'yii\db\Command' || $this->commandClass === \yii\db\Command::class)
                ? CoroutineCommand::class
                : $this->commandClass,
            'commandMap' => $this->commandMap,
            'shuffleMasters' => false,
        ]);
    }
}
