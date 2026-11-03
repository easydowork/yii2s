<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\framework;

use yii\db\Transaction;
use yii2stest\DatabaseTestCase;

/**
 * Full transaction surface of Yii2 under the coroutine pool: isolation
 * levels, savepoint nesting (three levels), closure transactions,
 * exception propagation, and AR interop.
 *
 * @group db
 * @group mysql
 */
class TransactionsTest extends DatabaseTestCase
{
    use FwSchema;

    protected $driverName = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFwSchema();
    }

    protected function tearDown(): void
    {
        $this->dropFwSchema();
        parent::tearDown();
    }

    private function titleExists(string $title): bool
    {
        return (bool) $this->getConnection(false, false)
            ->createCommand('SELECT COUNT(*) FROM {{%fw_post}} WHERE [[title]] = :t')
            ->bindValue(':t', $title)->queryScalar();
    }

    private function insertPost(string $title, string $slug): void
    {
        $this->getConnection(false, false)->createCommand()->insert('{{%fw_post}}', [
            'title' => $title, 'slug' => $slug, 'content' => 'x',
            'status' => 1, 'author_id' => 1, 'created_at' => time(), 'updated_at' => time(),
        ])->execute();
    }

    public function testAllFourIsolationLevels(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            foreach ([
                Transaction::READ_UNCOMMITTED,
                Transaction::READ_COMMITTED,
                Transaction::REPEATABLE_READ,
                Transaction::SERIALIZABLE,
            ] as $level) {
                $tx = $db->beginTransaction($level);
                $this->assertNotNull($db->getTransaction(), "transaction started at level $level");
                $tx->commit();
            }
        });
    }

    public function testThreeLevelSavepointNesting(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $outer = $db->beginTransaction();
            $this->insertPost('T-outer', 't-outer');

            $mid = $db->beginTransaction();
            $this->insertPost('T-mid', 't-mid');

            $inner = $db->beginTransaction();
            $this->insertPost('T-inner', 't-inner');

            $inner->commit();
            $mid->rollBack();   // rolls back everything after the mid savepoint (incl. inner)
            $outer->commit();

            $this->assertTrue($this->titleExists('T-outer'), 'outer row survives');
            $this->assertFalse($this->titleExists('T-mid'), 'mid row rolled back');
            $this->assertFalse($this->titleExists('T-inner'), 'inner row rolled back with mid');
        });
    }

    public function testInnerCommitIsUndoneByOuterRollback(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $outer = $db->beginTransaction();
            $this->insertPost('T-out', 't-out');

            $inner = $db->beginTransaction();
            $this->insertPost('T-in', 't-in');
            $inner->commit();   // savepoint release: still inside the outer transaction

            $outer->rollBack();

            $this->assertFalse($this->titleExists('T-out'));
            $this->assertFalse($this->titleExists('T-in'), 'inner commit is not durable inside an outer rollback');
        });
    }

    public function testClosureTransactionCommitsOnSuccess(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $result = $db->transaction(function (): string {
                $this->insertPost('Closure ok', 'closure-ok');

                return 'done';
            });

            $this->assertSame('done', $result);
            $this->assertTrue($this->titleExists('Closure ok'));
        });
    }

    public function testClosureTransactionRollsBackOnException(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            try {
                $db->transaction(function (): void {
                    $this->insertPost('Closure fail', 'closure-fail');
                    throw new \RuntimeException('rollback me');
                });
                $this->fail('exception must propagate');
            } catch (\RuntimeException $e) {
                $this->assertSame('rollback me', $e->getMessage());
            }

            $this->assertFalse($this->titleExists('Closure fail'), 'exception must roll the closure back');
        });
    }

    public function testNestedClosureWithInnerFailureKeepsOuterWorking(): void
    {
        $db = $this->getConnection();
        $this->runInCoroutine(function () use ($db): void {
            $db->transaction(function () use ($db): void {
                $this->insertPost('Nested outer row', 'nested-outer');

                try {
                    $db->transaction(function (): void {
                        $this->insertPost('Nested inner row', 'nested-inner');
                        throw new \RuntimeException('inner failure');
                    });
                } catch (\RuntimeException) {
                    // outer keeps working after the inner failure
                }

                $this->insertPost('Nested after', 'nested-after');
            });

            $this->assertTrue($this->titleExists('Nested outer row'));
            $this->assertFalse($this->titleExists('Nested inner row'), 'inner must roll back');
            $this->assertTrue($this->titleExists('Nested after'), 'outer continues after inner failure');
        });
    }

    public function testArSaveInsideTransactionRollsBack(): void
    {
        $db = $this->getConnection(false);
        \yii2stest\framework\FwDb::$connection = $db;
        $this->runInCoroutine(function () use ($db): void {
            $tx = $db->beginTransaction();

            $post = new \yii2stest\framework\models\FwPost();
            $post->title = 'AR in transaction';
            $post->content = 'x';
            $post->author_id = 1;
            $this->assertTrue($post->save());
            $savedId = $post->id;

            $tx->rollBack();

            $this->assertNull(\yii2stest\framework\models\FwPost::findOne($savedId), 'AR insert must roll back');
        });
    }
}
