<?php

use yii\db\Migration;

/**
 * Multi-user support: role + account status, plus seed editor/author accounts.
 *
 * Roles: admin (everything), editor (posts + comments), author (own posts).
 */
class m240103_000002_users_rbac extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%user}}', 'role', $this->string(16)->notNull()->defaultValue('author')->after('email'));
        $this->addColumn('{{%user}}', 'status', $this->smallInteger()->notNull()->defaultValue(1)->after('role'));

        $this->update('{{%user}}', ['role' => 'admin'], ['username' => 'admin']);

        $this->batchInsert(
            '{{%user}}',
            ['username', 'password_hash', 'auth_key', 'email', 'role', 'status', 'created_at'],
            [
                [
                    'editor',
                    Yii::$app->security->generatePasswordHash('editor123'),
                    Yii::$app->security->generateRandomString(),
                    'editor@example.com',
                    'editor',
                    1,
                    time(),
                ],
                [
                    'author',
                    Yii::$app->security->generatePasswordHash('author123'),
                    Yii::$app->security->generateRandomString(),
                    'author@example.com',
                    'author',
                    1,
                    time(),
                ],
            ]
        );
    }

    public function safeDown(): void
    {
        $this->dropColumn('{{%user}}', 'status');
        $this->dropColumn('{{%user}}', 'role');
    }
}
