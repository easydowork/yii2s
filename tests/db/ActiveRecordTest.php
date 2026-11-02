<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\db;

use Yii;
use yii\db\ActiveRecord;
use yii\db\Transaction;
use yii2stest\DatabaseTestCase;
use yii2stest\db\models\Author;
use yii2stest\db\models\Book;

/**
 * Full ActiveRecord lifecycle coverage on the coroutine-pooled connection:
 * CRUD, relations (lazy/eager/joinWith), model events, behaviors, batch
 * operations, transactions and repeated pool borrows.
 *
 * This is the integration surface production Yii2 applications rely on; a
 * failure here means real applications break, not just raw SQL paths.
 *
 * @group db
 * @group mysql
 * @group ar
 */
class ActiveRecordTest extends DatabaseTestCase
{
    protected $driverName = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getConnection();
        Author::$eventLog = [];

        $this->runInCoroutine(function () use ($db): void {
            $db->createCommand('
                CREATE TABLE IF NOT EXISTS {{%yii2s_ar_book}} (
                    [[id]] INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    [[author_id]] INT UNSIGNED NOT NULL,
                    [[title]] VARCHAR(128) NOT NULL,
                    [[created_at]] INT UNSIGNED NOT NULL DEFAULT 0,
                    CONSTRAINT fk_book_author FOREIGN KEY ([[author_id]])
                        REFERENCES {{%customer}} ([[id]])
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ')->execute();

            if ((int) $db->createCommand('SELECT COUNT(*) FROM {{%yii2s_ar_book}}')->queryScalar() === 0) {
                $db->createCommand()->batchInsert(
                    '{{%yii2s_ar_book}}',
                    ['author_id', 'title', 'created_at'],
                    [
                        [1, 'alice-book-1', time() - 300],
                        [1, 'alice-book-2', time() - 200],
                        [2, 'bob-book-1', time() - 100],
                    ]
                )->execute();
            }
        });

        // AR resolves Yii::$app->getDb(); expose the pooled connection as the
        // application db component so the models run exactly like production.
        $this->mockWebApplication([
            'components' => ['db' => $db],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $db = $this->getConnection(false, false);
            $this->runInCoroutine(function () use ($db): void {
                $db->createCommand('DROP TABLE IF EXISTS {{%yii2s_ar_book}}')->execute();
            });
        } catch (\Throwable) {
            // connection already gone - nothing to clean up
        }
        parent::tearDown();
    }

    public function testArInsertFindUpdateDelete(): void
    {
        $this->runInCoroutine(function (): void {
            // Insert.
            $book = new Book();
            $book->author_id = 3;
            $book->title = 'carol-new-book';
            $this->assertTrue($book->save(), json_encode($book->errors, JSON_THROW_ON_ERROR));
            $this->assertGreaterThan(0, $book->id);
            $this->assertGreaterThan(0, $book->created_at, 'TimestampBehavior must fill created_at');

            // Find one.
            $found = Book::findOne(['title' => 'carol-new-book']);
            $this->assertInstanceOf(Book::class, $found);
            $this->assertSame($book->id, $found->id);

            // Update.
            $found->title = 'carol-renamed';
            $this->assertTrue($found->save());
            $this->assertSame(1, Book::find()->where(['title' => 'carol-renamed'])->count());

            // Delete.
            $this->assertSame(1, $found->delete());
            $this->assertNull(Book::findOne($book->id));
        });
    }

    public function testArRelationsLazyAndEager(): void
    {
        $this->runInCoroutine(function (): void {
            $alice = Author::findOne(['name' => 'alice']);
            $this->assertInstanceOf(Author::class, $alice);

            // Lazy hasMany.
            $titles = array_map(static fn (Book $b) => $b->title, $alice->books);
            sort($titles);
            $this->assertSame(['alice-book-1', 'alice-book-2'], $titles);

            // belongsTo on the child.
            $book = Book::findOne(['title' => 'bob-book-1']);
            $this->assertSame('bob', $book->author->name);

            // Eager loading with with().
            $authors = Author::find()->with('books')->orderBy('id')->all();
            $this->assertCount(3, $authors);
            $this->assertCount(2, $authors[0]->books);
            $this->assertCount(1, $authors[1]->books);
            $this->assertCount(0, $authors[2]->books);

            // joinWith with a condition on the (aliased) related table.
            $joined = Author::find()
                ->joinWith('books b')
                ->andWhere(['b.title' => 'bob-book-1'])
                ->one();
            $this->assertInstanceOf(Author::class, $joined);
            $this->assertSame('bob', $joined->name);
        });
    }

    public function testArModelEventsAndBehaviorsFire(): void
    {
        $this->runInCoroutine(function (): void {
            $book = new Book();
            $book->author_id = 1;
            $book->title = 'events-book';
            $this->assertTrue($book->save());

            // TimestampBehavior (EVENT_BEFORE_INSERT) filled created_at.
            $this->assertGreaterThan(0, $book->created_at);

            $author = Author::findOne(1);
            $this->assertInstanceOf(Author::class, $author);
            $this->assertSame(1, Author::$eventLog['afterFind'] ?? 0, 'afterFind fired for the find');

            $author->name = 'alice-updated';
            $this->assertTrue($author->save());
            $this->assertSame(1, Author::$eventLog['beforeSave:update'] ?? 0);

            // Insert fires the insert variant of the event.
            $newAuthor = new Author();
            $newAuthor->name = 'dave';
            $newAuthor->email = 'dave@example.com';
            $this->assertTrue($newAuthor->save());
            $this->assertSame(1, Author::$eventLog['beforeSave:insert'] ?? 0);

            // afterFind fired for every find.
            Author::findAll([1, 2]);
            $this->assertSame(3, Author::$eventLog['afterFind']);
        });
    }

    public function testArUpdateAllDeleteAll(): void
    {
        $this->runInCoroutine(function (): void {
            $updated = Book::updateAll(['title' => 'bulk-renamed'], ['author_id' => 1]);
            $this->assertSame(2, $updated);
            $this->assertSame(2, Book::find()->where(['title' => 'bulk-renamed'])->count());

            $deleted = Book::deleteAll(['author_id' => 1]);
            $this->assertSame(2, $deleted);
            $this->assertSame(1, Book::find()->count());
        });
    }

    public function testArSaveRollsBackInsideTransaction(): void
    {
        $this->runInCoroutine(function (): void {
            /** @var Transaction $tx */
            $tx = Yii::$app->db->beginTransaction();
            try {
                $book = new Book();
                $book->author_id = 2;
                $book->title = 'doomed-book';
                $this->assertTrue($book->save());
                $this->assertNotNull(Book::findOne(['title' => 'doomed-book']), 'visible inside the transaction');
            } finally {
                $tx->rollBack();
            }

            $this->assertNull(Book::findOne(['title' => 'doomed-book']), 'rollback must remove the row');
            $this->assertSame(3, Book::find()->count(), 'fixture rows untouched');
        });
    }

    public function testArWorksAcrossRepeatedPoolBorrows(): void
    {
        // Simulates the resident server: each iteration borrows and returns a
        // connection; schema metadata, events and behaviors must stay correct.
        $this->runInCoroutine(function (): void {
            $inserted = 0;
            for ($i = 1; $i <= 10; $i++) {
                $book = new Book();
                $book->author_id = 1 + ($i % 3);
                $book->title = "loop-book-{$i}";
                $this->assertTrue($book->save(), "iteration {$i}: " . json_encode($book->errors));
                $inserted++;

                $found = Book::findOne(['title' => "loop-book-{$i}"]);
                $this->assertInstanceOf(Book::class, $found);
                $this->assertGreaterThan(0, $found->created_at);
            }
            $this->assertSame(3 + $inserted, Book::find()->count());

            // Schema-driven column reading still matches after the churn.
            $columns = Yii::$app->db->getTableSchema('{{%yii2s_ar_book}}')->columnNames;
            $this->assertSame(['id', 'author_id', 'title', 'created_at'], $columns);
        });
    }

    public function testConcurrentArQueriesAndTransactions(): void
    {
        // The production shape: many coroutines doing AR work at the same
        // time, each on its own pooled connection, with concurrent isolated
        // transactions that must not bleed into each other.
        $this->runInCoroutine(function (): void {
            $results = [];
            $errors = [];
            $wg = new \Swoole\Coroutine\WaitGroup();

            for ($i = 1; $i <= 8; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($i, $wg, &$results, &$errors): void {
                    try {
                        $book = new Book();
                        $book->author_id = 1 + ($i % 3);
                        $book->title = "concurrent-{$i}";
                        if (!$book->save()) {
                            $errors[$i] = 'save failed';
                        }

                        $tx = Yii::$app->db->beginTransaction();
                        try {
                            $found = Book::findOne(['title' => "concurrent-{$i}"]);
                            if ($found === null) {
                                $errors[$i] = 'row invisible inside own transaction';
                            }
                            $tx->commit();
                        } catch (\Throwable $e) {
                            $tx->rollBack();
                            throw $e;
                        }

                        $results[$i] = $found->id;
                    } catch (\Throwable $e) {
                        $errors[$i] = $e->getMessage();
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();

            $this->assertSame([], $errors, 'concurrent AR work must not fail: ' . json_encode($errors));
            $this->assertCount(8, $results);
            $this->assertSame(8, count(array_unique($results)), 'each coroutine must have inserted its own row');

            // Rows persisted by the distinct connections.
            $this->assertSame(8, Book::find()->where(['like', 'title', 'concurrent-'])->count());
        });
    }
}
