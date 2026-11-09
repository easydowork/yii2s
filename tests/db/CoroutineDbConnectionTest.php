<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\db;

use yii2stest\DatabaseTestCase;
use Yii2S\Swoole\Db\CoroutineDbConnection;
use yii\db\Connection;

/**
 * @group db
 * @group mysql
 */
class CoroutineDbConnectionTest extends DatabaseTestCase
{
    protected $driverName = 'mysql';

    public function testOpenClose(): void
    {
        $db = $this->getConnection(false, false);
        $this->runInCoroutine(function () use ($db): void {
            $this->assertFalse($db->getIsActive());
            $db->open();
            $this->assertTrue($db->getIsActive());
            $db->closePool();
            $this->assertFalse($db->getIsActive());
        });
    }

    public function testQueryAll(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $rows = $db->createCommand('SELECT `id`, `name` FROM `customer` ORDER BY `id`')->queryAll();
            $this->assertCount(3, $rows);
            $this->assertSame('alice', $rows[0]['name']);
            $this->assertSame('bob', $rows[1]['name']);
            $this->assertSame('carol', $rows[2]['name']);
        });
    }

    public function testInsertAndLastInsertId(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $db->createCommand()->insert('customer', [
                'name' => 'dave',
                'email' => 'dave@example.com',
                'created_at' => time(),
            ])->execute();

            $id = (int) $db->getLastInsertID();
            $this->assertGreaterThan(0, $id);

            $name = $db->createCommand('SELECT `name` FROM `customer` WHERE `id` = ' . $id)->queryScalar();
            $this->assertSame('dave', $name);
        });
    }

    public function testTransactionCommit(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $transaction = $db->beginTransaction();
            try {
                $db->createCommand()->insert('customer', [
                    'name' => 'tx-commit',
                    'email' => 'tx-commit@example.com',
                    'created_at' => time(),
                ])->execute();
                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            $count = $db->createCommand("SELECT COUNT(*) FROM `customer` WHERE `name` = 'tx-commit'")->queryScalar();
            $this->assertSame('1', (string) $count);
        });
    }

    public function testTransactionRollback(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $transaction = $db->beginTransaction();
            $db->createCommand()->insert('customer', [
                'name' => 'tx-rollback',
                'email' => 'tx-rollback@example.com',
                'created_at' => time(),
            ])->execute();
            $transaction->rollBack();

            $count = $db->createCommand("SELECT COUNT(*) FROM `customer` WHERE `name` = 'tx-rollback'")->queryScalar();
            $this->assertSame('0', (string) $count);
        });
    }

    public function testTransactionIsStickyToSameConnection(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $db->createCommand('SELECT 1')->execute();
            $first = $db->borrow();

            $db->beginTransaction();
            $second = $db->borrow();

            $this->assertSame($first, $second, 'borrowed connection must stay sticky inside a coroutine');
            $this->assertNotNull($db->getTransaction());

            $db->getTransaction()->commit();
            $db->release();
        });
    }

    public function testReleaseReturnsConnectionToPool(): void
    {
        $db = $this->getConnection(false, false);
        $this->runInCoroutine(function () use ($db): void {
            $db->createCommand('SELECT 1')->execute();
            $this->assertSame(0, $db->getPool()->getIdleCount());

            $db->release();
            $this->assertSame(1, $db->getPool()->getIdleCount());

            // release() must be idempotent
            $db->release();
            $this->assertSame(1, $db->getPool()->getIdleCount());
        });
    }

    public function testConcurrentQueriesUseSeparateConnections(): void
    {
        $db = $this->getConnection(false, false);
        $pool = $this->runInCoroutine(function () use ($db): \Yii2S\Swoole\Pools\ConnectionPool {
            $connectionIds = [];
            $wait = new \Swoole\Coroutine\WaitGroup();

            for ($i = 0; $i < 5; $i++) {
                $wait->add();
                \Swoole\Coroutine::create(function () use ($db, $wait, &$connectionIds, $i): void {
                    try {
                        $db->createCommand('SELECT 1')->execute();
                        $connectionIds[$i] = spl_object_id($db->borrow());
                    } finally {
                        $db->release();
                        $wait->done();
                    }
                });
            }
            $wait->wait();

            $this->assertCount(5, array_unique($connectionIds), 'each concurrent coroutine must own its connection');

            return $db->getPool();
        });

        $this->assertGreaterThanOrEqual(5, $pool->getSize());
        $this->assertSame(5, $pool->getIdleCount(), 'all connections must be returned after the coroutines finish');
    }

    public function testFallbackToStandardBehaviorWhenPoolingDisabled(): void
    {
        $db = $this->getConnection(false, false);
        $db->enableCoroutinePooling = false;
        $this->runInCoroutine(function () use ($db): void {
            $this->assertInstanceOf(Connection::class, $db);
            $rows = $db->createCommand('SELECT COUNT(*) FROM `customer`')->queryScalar();
            $this->assertGreaterThan(0, (int) $rows);
        });
    }

    public function testNonCoroutineAccessUsesDetachedConnection(): void
    {
        // Console commands (queue/serve handlers, migrations) run outside a
        // coroutine. The component must bypass the pool via a detached
        // connection and must not hang or touch pool counters.
        $db = $this->getConnection(false, false);

        $first = $db->createCommand('SELECT `name` FROM `customer` ORDER BY `id` LIMIT 1')->queryScalar();
        $second = $db->createCommand('SELECT `name` FROM `customer` ORDER BY `id` LIMIT 1')->queryScalar();
        $this->assertSame('alice', $first);
        $this->assertSame('alice', $second, 'detached connection must be reused across non-coroutine calls');

        $metrics = $db->getPool()->getMetrics();
        $this->assertSame(0, $metrics['total_borrowed'], 'pool must be untouched outside coroutines');
    }

    public function testPooledConnectionCarriesReadWriteSplitConfig(): void
    {
        $masters = [['dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_test']];
        $slaves = [['dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_test']];

        $db = new CoroutineDbConnection([
            'dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_test',
            'username' => 'root',
            'password' => getenv('YII2S_TEST_DB_PASSWORD') ?: 'root',
            'masters' => $masters,
            'slaves' => $slaves,
            'masterConfig' => ['username' => 'root'],
            'slaveConfig' => ['username' => 'root'],
            'enableSlaves' => true,
        ]);

        $this->runInCoroutine(function () use ($db, $masters, $slaves): void {
            $connection = $db->borrow();

            $this->assertSame($masters, $connection->masters);
            $this->assertSame($slaves, $connection->slaves);
            $this->assertSame(['username' => 'root'], $connection->masterConfig);
            $this->assertSame(['username' => 'root'], $connection->slaveConfig);
            $this->assertTrue($connection->enableSlaves);
            $this->assertSame(
                10,
                $db->getPool()->getMetrics()['max_active'],
                'slaves open a second PDO, so the pool holds half of poolMaxActive objects'
            );

            $db->release();
        });
    }

    public function testSlowQueryIsLogged(): void
    {
        $db = $this->getConnection(false, false);
        $db->slowQueryThreshold = 0.05;

        $this->runInCoroutine(function () use ($db): void {
            \Yii::getLogger()->messages = [];
            $db->createCommand('SELECT SLEEP(0.2)')->queryScalar();

            $this->assertTrue($this->hasSlowQueryLog(), 'a slow query must be logged');
        });
    }

    public function testFastQueryIsNotLogged(): void
    {
        $db = $this->getConnection(false, false);
        $db->slowQueryThreshold = 1.0;

        $this->runInCoroutine(function () use ($db): void {
            \Yii::getLogger()->messages = [];
            $db->createCommand('SELECT 1')->queryScalar();

            $this->assertFalse($this->hasSlowQueryLog(), 'a fast query must not be logged');
        });
    }

    private function hasSlowQueryLog(): bool
    {
        foreach (\Yii::getLogger()->messages as $message) {
            if (str_contains((string) $message[0], 'Slow query')) {
                return true;
            }
        }

        return false;
    }
}
