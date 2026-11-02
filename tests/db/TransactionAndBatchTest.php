<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\db;

use yii2stest\DatabaseTestCase;

/**
 * Ported from the official framework test scope (tests/framework/db/),
 * narrowed to what coroutine pooling can change: nested transactions
 * (savepoints), query batching on a sticky connection, and URL-rule parsing
 * lives in the web suite instead.
 *
 * @group db
 * @group mysql
 */
class TransactionAndBatchTest extends DatabaseTestCase
{
    protected $driverName = 'mysql';

    public function testNestedTransactionsUseSavepoints(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $outer = $db->beginTransaction();
            $db->createCommand()->insert('customer', [
                'name' => 'nested-outer',
                'email' => 'nested-outer@example.com',
                'created_at' => time(),
            ])->execute();

            // Inner transaction must become a SAVEPOINT on the same sticky
            // connection - not a second pooled connection.
            $inner = $db->beginTransaction();
            $db->createCommand()->insert('customer', [
                'name' => 'nested-inner',
                'email' => 'nested-inner@example.com',
                'created_at' => time(),
            ])->execute();
            $inner->rollBack(); // only the inner row must vanish

            $outer->commit();

            $outerCount = (int) $db->createCommand(
                "SELECT COUNT(*) FROM `customer` WHERE `name` = 'nested-outer'"
            )->queryScalar();
            $innerCount = (int) $db->createCommand(
                "SELECT COUNT(*) FROM `customer` WHERE `name` = 'nested-inner'"
            )->queryScalar();
            $this->assertSame(1, $outerCount, 'outer commit must persist its row');
            $this->assertSame(0, $innerCount, 'inner rollback must remove only the inner row');
        });
    }

    public function testNestedInnerRollbackDoesNotKillOuter(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $outer = $db->beginTransaction();
            $inner = $db->beginTransaction();
            $inner->rollBack();
            // After a savepoint rollback the outer transaction must still be
            // usable (this is what breaks on naive level-counter handling).
            $db->createCommand()->insert('customer', [
                'name' => 'after-inner-rollback',
                'email' => 'after-inner-rollback@example.com',
                'created_at' => time(),
            ])->execute();
            $outer->commit();

            $count = (int) $db->createCommand(
                "SELECT COUNT(*) FROM `customer` WHERE `name` = 'after-inner-rollback'"
            )->queryScalar();
            $this->assertSame(1, $count, 'outer transaction stays usable after inner rollback');
        });
    }

    public function testQueryBatchIteratesAllRows(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            // Batch queries keep a statement open across many fetches on the
            // same sticky connection; pooling must not disturb it.
            $names = [];
            $batch = (new \yii\db\Query())
                ->select('name')
                ->from('customer')
                ->orderBy('id')
                ->batch(2, $db);
            foreach ($batch as $rows) {
                foreach ($rows as $row) {
                    $names[] = $row['name'];
                }
            }
            $this->assertCount(3, $names);
            $this->assertSame(['alice', 'bob', 'carol'], $names);
        });
    }
}
