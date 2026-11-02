-- Fixture for the yii2s/swoole unit tests.

DROP TABLE IF EXISTS `customer`;
CREATE TABLE `customer` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(64) NOT NULL,
    `email` VARCHAR(128) NOT NULL,
    `created_at` INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `customer` (`name`, `email`, `created_at`) VALUES
    ('alice', 'alice@example.com', UNIX_TIMESTAMP()),
    ('bob', 'bob@example.com', UNIX_TIMESTAMP()),
    ('carol', 'carol@example.com', UNIX_TIMESTAMP());
