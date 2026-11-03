<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

namespace yii2stest\framework;

/**
 * Self-contained schema for the framework-feature suites: five tables built
 * per-test and dropped after, independent from the shared fixtures.
 */
trait FwSchema
{
    protected function createFwSchema(): void
    {
        $db = $this->getConnection(false, false);
        FwDb::$connection = $db;

        foreach (['{{%fw_comment}}', '{{%fw_post_tag}}', '{{%fw_tag}}', '{{%fw_post}}', '{{%fw_user}}'] as $t) {
            $db->createCommand("DROP TABLE IF EXISTS $t")->execute();
        }

        $db->createCommand(<<<'SQL'
CREATE TABLE {{%fw_user}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(64) NOT NULL UNIQUE,
    `email` VARCHAR(128),
    `status` SMALLINT NOT NULL DEFAULT 1,
    `created_by` INT,
    `created_at` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL)->execute();

        $db->createCommand(<<<'SQL'
CREATE TABLE {{%fw_post}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `slug` VARCHAR(220) DEFAULT '' NOT NULL UNIQUE,
    `content` TEXT NOT NULL,
    `views` INT NOT NULL DEFAULT 0,
    `version` INT NOT NULL DEFAULT 1,
    `status` SMALLINT NOT NULL DEFAULT 1,
    `author_id` INT UNSIGNED NOT NULL,
    `created_at` INT NOT NULL,
    `updated_at` INT NOT NULL,
    CONSTRAINT `fk-fw-post-author` FOREIGN KEY (`author_id`) REFERENCES {{%fw_user}} (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL)->execute();

        $db->createCommand(<<<'SQL'
CREATE TABLE {{%fw_tag}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(64) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL)->execute();

        $db->createCommand(<<<'SQL'
CREATE TABLE {{%fw_post_tag}} (
    `post_id` INT UNSIGNED NOT NULL,
    `tag_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL)->execute();

        $db->createCommand(<<<'SQL'
CREATE TABLE {{%fw_comment}} (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `post_id` INT UNSIGNED NOT NULL,
    `content` VARCHAR(1000) NOT NULL,
    `status` SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL)->execute();

        $now = time();
        $db->createCommand()->batchInsert('{{%fw_user}}', ['id', 'username', 'email', 'status', 'created_by', 'created_at'], [
            [1, 'alice', 'alice@example.com', 1, 99, $now],
            [2, 'bob', 'bob@example.com', 1, 99, $now],
        ])->execute();

        $db->createCommand()->batchInsert('{{%fw_post}}', ['id', 'title', 'slug', 'content', 'status', 'author_id', 'created_at', 'updated_at'], [
            [1, 'First framework post', 'first-framework-post', 'body one', 1, 1, $now, $now],
            [2, 'Second framework post', 'second-framework-post', 'body two', 1, 2, $now - 100, $now - 100],
            [3, 'Draft framework post', 'draft-framework-post', 'body draft', 0, 1, $now - 200, $now - 200],
        ])->execute();

        $db->createCommand()->batchInsert('{{%fw_tag}}', ['id', 'name'], [
            [1, 'php'], [2, 'swoole'], [3, 'testing'],
        ])->execute();

        $db->createCommand()->batchInsert('{{%fw_post_tag}}', ['post_id', 'tag_id'], [
            [1, 1], [1, 2], [2, 2],
        ])->execute();

        $db->createCommand()->batchInsert('{{%fw_comment}}', ['id', 'post_id', 'content', 'status'], [
            [1, 1, 'nice', 1],
            [2, 1, 'me too', 0],
            [3, 2, 'thanks', 1],
        ])->execute();
    }
    protected function dropFwSchema(): void
    {
        $db = $this->getConnection(false, false);
        foreach (['{{%fw_comment}}', '{{%fw_post_tag}}', '{{%fw_tag}}', '{{%fw_post}}', '{{%fw_user}}'] as $table) {
            try {
                $db->createCommand("DROP TABLE IF EXISTS $table")->execute();
            } catch (\Throwable) {
            }
        }
    }
}
