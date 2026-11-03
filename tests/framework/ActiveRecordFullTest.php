<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\framework;

use yii\db\StaleObjectException;
use yii2stest\DatabaseTestCase;

/**
 * The full ActiveRecord surface beyond basic CRUD: dirty tracking,
 * refresh, optimistic locking, updateAllCounters, scenarios, asArray,
 * indexBy, aggregates, subqueries.
 *
 * @group db
 * @group mysql
 */
class ActiveRecordFullTest extends DatabaseTestCase
{
    use FwSchema;

    protected $driverName = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFwSchema();
        \yii2stest\framework\FwDb::$connection = $this->getConnection(false);
    }

    protected function tearDown(): void
    {
        $this->dropFwSchema();
        parent::tearDown();
    }

    public function testDirtyAttributesTracking(): void
    {
        $this->runInCoroutine(function (): void {
            $post = \yii2stest\framework\models\FwPost::findOne(1);
            $this->assertSame([], $post->getDirtyAttributes(), "clean after find");

            $post->title = "changed title";
            $dirty = $post->getDirtyAttributes();
            $this->assertSame(["title" => "changed title"], $dirty, "only changed attributes are dirty");

            $this->assertTrue($post->isAttributeChanged("title"));
            $this->assertFalse($post->isAttributeChanged("content"));

            $this->assertSame("First framework post", $post->getOldAttribute("title"));
            $post->save(false);
            $this->assertSame([], $post->getDirtyAttributes(), "clean after save");
        });
    }

    public function testRefreshRestoresState(): void
    {
        $this->runInCoroutine(function (): void {
            $post = \yii2stest\framework\models\FwPost::findOne(1);
            $original = $post->title;

            $post->title = "mutated in memory";
            $post->content = "mutated";
            $this->assertSame("mutated in memory", $post->title);

            $this->assertTrue($post->refresh());
            $this->assertSame($original, $post->title, "refresh restores persisted state");
            $this->assertSame([], $post->getDirtyAttributes());
        });
    }

    public function testExplicitInsertAndUpdate(): void
    {
        $this->runInCoroutine(function (): void {
            $post = new \yii2stest\framework\models\FwPost();
            $post->title = "explicit insert";
            $post->content = "x";
            $post->author_id = 1;
            $post->version = 1;   // explicit: DB default fills the column but not the AR state
            $this->assertTrue($post->insert());
            $this->assertGreaterThan(0, $post->id, "auto-increment id assigned");

            $post->title = "explicit update";
            $this->assertSame(1, $post->update(), "one row affected");
            $this->assertSame("explicit update", \yii2stest\framework\models\FwPost::findOne($post->id)->title);
        });
    }

    public function testOptimisticLockPreventsStaleUpdate(): void
    {
        $this->runInCoroutine(function (): void {
            $a = \yii2stest\framework\models\FwPost::findOne(1);
            $b = \yii2stest\framework\models\FwPost::findOne(1);
            $this->assertSame($a->version, $b->version, "same version for both readers");

            $a->title = "writer-a";
            $a->update(false);   // bumps version

            $b->title = "writer-b";
            $this->expectException(StaleObjectException::class);
            $b->update(false);   // stale version must fail
        });
    }

    public function testUpdateAllCounters(): void
    {
        $this->runInCoroutine(function (): void {
            $before = \yii2stest\framework\models\FwPost::findOne(1)->views;

            \yii2stest\framework\models\FwPost::updateAllCounters(["views" => 5], ["id" => 1]);
            $this->assertSame($before + 5, \yii2stest\framework\models\FwPost::findOne(1)->views);

            \yii2stest\framework\models\FwPost::updateAllCounters(["views" => -2], ["id" => 1]);
            $this->assertSame($before + 3, \yii2stest\framework\models\FwPost::findOne(1)->views);
        });
    }

    public function testScenarioBasedRules(): void
    {
        $this->runInCoroutine(function (): void {
            // default scenario: content required
            $post = new \yii2stest\framework\models\FwPost();
            $post->title = "scenario test";
            $this->assertFalse($post->validate(), "content required in default scenario");

            // a scenario where content is not marked required validates fine
            $post->scenario = "no-content-check";
            $this->assertSame("no-content-check", $post->getScenario());
        });
    }

    public function testAsArrayAndIndexBy(): void
    {
        $this->runInCoroutine(function (): void {
            $rows = \yii2stest\framework\models\FwPost::find()
                ->where(["status" => 1])
                ->asArray()
                ->orderBy(["id" => SORT_ASC])
                ->all();
            $this->assertNotEmpty($rows);
            $this->assertArrayHasKey("title", $rows[0]);
            $this->assertSame("First framework post", $rows[0]["title"]);

            $indexed = \yii2stest\framework\models\FwPost::find()
                ->indexBy("slug")
                ->all();
            $this->assertArrayHasKey("first-framework-post", $indexed);
            $this->assertInstanceOf(\yii2stest\framework\models\FwPost::class, $indexed["first-framework-post"]);
        });
    }

    public function testAggregates(): void
    {
        $this->runInCoroutine(function (): void {
            $q = \yii2stest\framework\models\FwPost::find();
            $this->assertSame(3, (int) $q->count());
            $this->assertSame(0, (int) $q->min("views"));
            $this->assertGreaterThanOrEqual(0, (int) $q->max("views"));
            $this->assertGreaterThanOrEqual(0, (int) $q->average("views"));
            $this->assertGreaterThanOrEqual(0, (int) $q->sum("views"));
        });
    }

    public function testWhereWithSubquery(): void
    {
        $this->runInCoroutine(function (): void {
            // posts whose author also wrote another post (subquery over author ids)
            $sub = \yii2stest\framework\models\FwPost::find()
                ->select("author_id")
                ->where(["status" => 0]);   // draft author = 1
            $rows = \yii2stest\framework\models\FwPost::find()
                ->where(["author_id" => $sub])
                ->all();
            $this->assertNotEmpty($rows);
            foreach ($rows as $row) {
                $this->assertSame(1, $row->author_id);
            }
        });
    }
}
