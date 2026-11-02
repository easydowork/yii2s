<?php

/**
 * This is the configuration file for the yii2s/swoole unit tests.
 *
 * You can override configuration values by creating a `config.local.php` file
 * and manipulate the `$config` variable. For example to change the MySQL
 * password your `config.local.php` should contain the following:
 * ```
 * <?php
 * $config['databases']['mysql']['password'] = 'changeme';
 * ```
 */
$config = [
    'databases' => [
        'mysql' => [
            'dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_test',
            'username' => 'root',
            'password' => 'root',
            'fixture' => __DIR__ . '/mysql.sql',
        ],
    ],
    'redis' => [
        'hostname' => '127.0.0.1',
        'port' => 6379,
    ],
];

if (is_file(__DIR__ . '/config.local.php')) {
    include __DIR__ . '/config.local.php';
}

return $config;
