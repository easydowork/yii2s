<?php

use yii\db\Migration;

/**
 * Features added to exercise the package beyond plain CRUD:
 * cover uploads (CoroutineUploadedFile + static file serving), a Redis view
 * counter, moderated comments (CSRF + flash + validation) and an audit table
 * written by queue jobs.
 */
class m240102_000001_blog_features extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%post}}', 'cover', $this->string(255)->null()->after('content'));
        $this->addColumn('{{%post}}', 'views', $this->integer()->notNull()->defaultValue(0)->after('status'));

        $this->createTable('{{%comment}}', [
            'id' => $this->primaryKey(),
            'post_id' => $this->integer()->notNull(),
            'author_name' => $this->string(64)->notNull(),
            'author_email' => $this->string(128)->notNull(),
            'content' => $this->text()->notNull(),
            'status' => $this->smallInteger()->notNull()->defaultValue(0),
            'ip' => $this->string(45)->notNull()->defaultValue(''),
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('idx-comment-post', '{{%comment}}', ['post_id', 'status']);
        $this->addForeignKey(
            'fk-comment-post',
            '{{%comment}}',
            'post_id',
            '{{%post}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->createTable('{{%audit_log}}', [
            'id' => $this->primaryKey(),
            'action' => $this->string(64)->notNull(),
            'post_id' => $this->integer(),
            'message' => $this->text()->notNull(),
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('idx-audit-created', '{{%audit_log}}', 'created_at');

        $now = time();
        $this->batchInsert('{{%comment}}', ['post_id', 'author_name', 'author_email', 'content', 'status', 'ip', 'created_at'], [
            [1, 'reader', 'reader@example.com', 'Coroutines + classic MVC - nice.', 1, '127.0.0.1', $now - 600],
            [1, 'guest', 'guest@example.com', 'Waiting for moderation...', 0, '127.0.0.1', $now - 300],
        ]);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%audit_log}}');
        $this->dropTable('{{%comment}}');
        $this->dropColumn('{{%post}}', 'views');
        $this->dropColumn('{{%post}}', 'cover');
    }
}
