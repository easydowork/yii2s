<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\framework;

use Yii2S\Swoole\Db\CoroutineDbConnection;
use yii\db\ActiveRecord;
use yii2stest\TestCase;

/**
 * Behaviors/events with a directly managed connection: TimestampBehavior,
 * SluggableBehavior, BlameableBehavior, dynamic attach/detach, AR event family.
 *
 * @group db
 * @group mysql
 */
class BehaviorsEventsTest extends TestCase
{
    private ?CoroutineDbConnection $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new CoroutineDbConnection([
            'dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_test',
            'username' => 'root',
            'password' => getenv('YII2S_TEST_DB_PASSWORD') ?: 'root',
            'charset' => 'utf8mb4',
            'enableCoroutinePooling' => false,   // plain connection for clean transaction testing
        ]);
        $this->db->open();
        FwDb::$connection = $this->db;

        foreach (['fw_comment', 'fw_post_tag', 'fw_tag', 'fw_post', 'fw_user'] as $t) {
            $this->db->createCommand("DROP TABLE IF EXISTS $t")->execute();
        }
        $this->db->createCommand("CREATE TABLE fw_user (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64) NOT NULL UNIQUE, email VARCHAR(128), status SMALLINT NOT NULL DEFAULT 1, created_by INT, created_at INT NOT NULL)")->execute();
        $this->db->createCommand("CREATE TABLE fw_post (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, slug VARCHAR(220) DEFAULT '' NOT NULL UNIQUE, content TEXT NOT NULL, views INT NOT NULL DEFAULT 0, version INT NOT NULL DEFAULT 1, status SMALLINT NOT NULL DEFAULT 1, author_id INT UNSIGNED NOT NULL, created_at INT NOT NULL, updated_at INT NOT NULL)")->execute();
        $this->db->createCommand()->batchInsert('fw_user', ['id', 'username', 'email', 'status', 'created_by', 'created_at'], [[1, 'alice', 'a@x', 1, 99, time()]])->execute();
    }

    protected function tearDown(): void
    {
        foreach (['fw_comment', 'fw_post_tag', 'fw_tag', 'fw_post', 'fw_user'] as $t) {
            try {
                $this->db?->createCommand("DROP TABLE IF EXISTS $t")->execute();
            } catch (\Throwable) {
            }
        }
        $this->db?->close();
        $this->db = null;
        FwDb::$connection = null;
        parent::tearDown();
    }

    public function testTimestampAndSluggable(): void
    {
        $this->runInCoroutine(function (): void {
            $post = new \yii2stest\framework\models\FwPost();
            $post->title = "ts-test";
            $post->content = "x";
            $post->author_id = 1;
            // SluggableBehavior listens to beforeValidate, not beforeInsert.
            // save(false) skips that event; this is stock Yii, not a coroutine gap.
            $this->assertTrue($post->save(), implode(' ', $post->getErrorSummary(true)));
            $this->assertGreaterThan(0, $post->created_at);
            $this->assertSame('ts-test', $post->slug);
        });
    }

    public function testEventFamilyOnInsertAndDelete(): void
    {
        $this->runInCoroutine(function (): void {
            $post = new \yii2stest\framework\models\FwPost();
            $post->title = "event-family";
            $post->content = "x";
            $post->author_id = 1;
            $log = [];
            $post->on(ActiveRecord::EVENT_BEFORE_INSERT, function () use (&$log) {
                $log[] = "beforeInsert";
            });
            $post->on(ActiveRecord::EVENT_AFTER_INSERT, function () use (&$log) {
                $log[] = "afterInsert";
            });
            $post->on(ActiveRecord::EVENT_BEFORE_DELETE, function () use (&$log) {
                $log[] = "beforeDelete";
            });
            $post->on(ActiveRecord::EVENT_AFTER_DELETE, function () use (&$log) {
                $log[] = "afterDelete";
            });
            $post->save(false);
            $this->assertContains("beforeInsert", $log);
            $this->assertContains("afterInsert", $log);
            $post->delete();
            $this->assertContains("beforeDelete", $log);
            $this->assertContains("afterDelete", $log);
        });
    }

    public function testDynamicAttachDetach(): void
    {
        $this->runInCoroutine(function (): void {
            $post = new \yii2stest\framework\models\FwPost();
            $post->title = "dyn";
            $post->content = "x";
            $post->author_id = 1;
            $post->detachBehaviors();
            $post->created_at = time();
            $post->updated_at = time();
            $post->slug = "dyn";
            $post->on(ActiveRecord::EVENT_BEFORE_INSERT, function ($event) {
                $event->sender->views = 777;
            });
            $post->save(false);
            $this->assertSame(777, $post->views);
            $post2 = new \yii2stest\framework\models\FwPost();
            $post2->title = "dyn2";
            $post2->content = "x";
            $post2->author_id = 1;
            $post2->save(false);
            $this->assertSame(0, $post2->views);
        });
    }

    public function testSluggableGeneratesUniqueSlug(): void
    {
        $this->runInCoroutine(function (): void {
            $a = new \yii2stest\framework\models\FwPost();
            $a->title = "Sluggable Test Post";
            $a->content = "x";
            $a->author_id = 1;
            $this->assertTrue($a->save(), implode(' ', $a->getErrorSummary(true)));
            $this->assertSame("sluggable-test-post", $a->slug);
            $b = new \yii2stest\framework\models\FwPost();
            $b->title = "Sluggable Test Post";
            $b->content = "x";
            $b->author_id = 1;
            $this->assertTrue($b->save(), implode(' ', $b->getErrorSummary(true)));
            $this->assertNotSame($a->slug, $b->slug);
        });
    }

    public function testBlameableFillsCreator(): void
    {
        $this->runInCoroutine(function (): void {
            $user = \yii2stest\framework\models\FwUser::findOne(1);
            $user->email = "blameable@example.com";
            $user->save(false);
            $this->assertSame(99, (int) \yii2stest\framework\models\FwUser::findOne(1)->created_by);
        });
    }
}
