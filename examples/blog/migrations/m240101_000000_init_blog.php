<?php

use yii\db\Migration;

class m240101_000000_init_blog extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%user}}', [
            'id' => $this->primaryKey(),
            'username' => $this->string(64)->notNull()->unique(),
            'password_hash' => $this->string(255)->notNull(),
            'auth_key' => $this->string(64)->notNull(),
            'email' => $this->string(128),
            'created_at' => $this->integer()->notNull(),
        ]);

        $this->createTable('{{%post}}', [
            'id' => $this->primaryKey(),
            'title' => $this->string(200)->notNull(),
            'content' => $this->text()->notNull(),
            'status' => $this->smallInteger()->notNull()->defaultValue(1),
            'author_id' => $this->integer()->notNull(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('idx-post-author', '{{%post}}', 'author_id');

        $hash = Yii::$app->security->generatePasswordHash('admin123');
        $this->insert('{{%user}}', [
            'username' => 'admin',
            'password_hash' => $hash,
            'auth_key' => Yii::$app->security->generateRandomString(),
            'email' => 'admin@example.com',
            'created_at' => time(),
        ]);

        $now = time();
        $this->batchInsert('{{%post}}', ['title', 'content', 'status', 'author_id', 'created_at', 'updated_at'], [
            ['Hello Swoole', 'First post served by the resident-memory coroutine server. PHP code untouched.', 1, 1, $now, $now],
            ['Coroutines in a classic MVC app', 'Views, sessions, forms and CSRF all keep working under the coroutine runtime.', 1, 1, $now - 3600, $now - 3600],
            ['Why connection pools matter', 'Every coroutine borrows its own connection; the pool bounds concurrency.', 1, 1, $now - 7200, $now - 7200],
        ]);
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%post}}');
        $this->dropTable('{{%user}}');
    }
}
