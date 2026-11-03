<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\framework;

use yii2stest\DatabaseTestCase;

/**
 * The full relations surface: via-table many-to-many, inverseOf,
 * link/unlink, multi-level with, joinWith + alias, relation counting.
 *
 * @group db
 * @group mysql
 */
class RelationsTest extends DatabaseTestCase
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

    public function testViaTableManyToMany(): void
    {
        $this->runInCoroutine(function (): void {
            $post = \yii2stest\framework\models\FwPost::findOne(1);
            $tags = $post->tags;
            $this->assertCount(2, $tags);
            $this->assertSame(["php", "swoole"], array_map(fn ($t) => $t->name, $tags));

            $tag = \yii2stest\framework\models\FwTag::findOne(2);
            $this->assertCount(2, $tag->posts);   // post 1 + post 2
        });
    }

    public function testInverseOf(): void
    {
        $this->runInCoroutine(function (): void {
            $user = \yii2stest\framework\models\FwUser::findOne(1);
            $posts = $user->posts;
            $this->assertNotEmpty($posts);
            foreach ($posts as $post) {
                $this->assertSame($user, $post->author, "inverseOf keeps identity");
            }
        });
    }

    public function testLinkAndUnlink(): void
    {
        $this->runInCoroutine(function (): void {
            $post = \yii2stest\framework\models\FwPost::findOne(2);
            $tag = \yii2stest\framework\models\FwTag::findOne(1);   // php ? not linked to post 2 yet

            $post->link("tags", $tag);      // INSERT INTO fw_post_tag
            $this->assertCount(2, $post->tags);   // php + swoole now
            $this->assertTrue(FwSchemaCheck::pivotExists(2, 1));

            $post->unlink("tags", $tag, true);    // DELETE FROM fw_post_tag
            $this->assertCount(1, $post->tags);
        });
    }

    public function testMultiLevelWith(): void
    {
        $this->runInCoroutine(function (): void {
            $comments = \yii2stest\framework\models\FwComment::find()
                ->with(["post.author"])
                ->orderBy(["fw_comment.id" => SORT_ASC])
                ->all();
            $this->assertCount(3, $comments);
            foreach ($comments as $c) {
                $this->assertNotSame("", $c->post->content, "eager-loaded post is populated");
                $this->assertNotEmpty($c->post->author->username, "nested relation is populated");
            }
        });
    }

    public function testJoinWithAlias(): void
    {
        $this->runInCoroutine(function (): void {
            $posts = \yii2stest\framework\models\FwPost::find()
                ->joinWith(["author author"])
                ->where(["author.username" => "alice"])
                ->orderBy(["fw_post.id" => SORT_ASC])
                ->all();
            $this->assertNotEmpty($posts);
            foreach ($posts as $p) {
                $this->assertSame(1, $p->author_id);
            }
        });
    }

    public function testRelationCount(): void
    {
        $this->runInCoroutine(function (): void {
            $post = \yii2stest\framework\models\FwPost::findOne(1);
            $this->assertSame(2, (int) $post->getComments()->count());
        });
    }
}

/**
 * Small helper verifying the pivot table directly (kept outside the model
 * namespace to avoid autoload coupling).
 */
class FwSchemaCheck
{
    public static function pivotExists(int $postId, int $tagId): bool
    {
        $db = \yii2stest\framework\FwDb::$connection;

        return (bool) $db->createCommand(
            "SELECT COUNT(*) FROM {{%fw_post_tag}} WHERE [[post_id]] = :p AND [[tag_id]] = :t"
        )->bindValue(":p", $postId)->bindValue(":t", $tagId)->queryScalar();
    }
}
