<?php

declare(strict_types=1);

try {
    $pdo = new PDO('mysql:host=127.0.0.1', 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS yii2s_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE yii2s_demo');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(64) NOT NULL,
        created_at INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM user')->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO user (name, created_at) VALUES
            ('alice', UNIX_TIMESTAMP()), ('bob', UNIX_TIMESTAMP()), ('carol', UNIX_TIMESTAMP())");
    }

    echo 'db ready, users=' . $pdo->query('SELECT COUNT(*) FROM user')->fetchColumn() . PHP_EOL;
} catch (Throwable $e) {
    echo 'DB ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
