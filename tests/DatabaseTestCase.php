<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest;

use Yii;
use Yii2S\Swoole\Db\CoroutineDbConnection;
use yii\db\Connection;

/**
 * Base class for the database tests. Prepares the test database from the
 * fixture defined in /data/config.php, following the official Yii2 testing
 * layout.
 */
abstract class DatabaseTestCase extends TestCase
{
    /**
     * @var string the driver name of this test class. Must be set by a subclass.
     */
    protected $driverName;

    /**
     * @var array database configuration from /data/config.php
     */
    protected $database;

    /**
     * @var CoroutineDbConnection|null
     */
    private ?CoroutineDbConnection $_db = null;

    protected function setUp(): void
    {
        if ($this->driverName === null) {
            throw new \Exception('driverName is not set for a DatabaseTestCase.');
        }

        parent::setUp();
        $databases = self::getParam('databases');
        $this->database = $databases[$this->driverName];

        $pdoDriver = 'pdo_' . $this->driverName;
        if (!extension_loaded('pdo') || !extension_loaded($pdoDriver)) {
            $this->markTestSkipped('pdo and ' . $pdoDriver . ' extensions are required.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->_db !== null) {
            $this->_db->close();
            $this->_db = null;
        }
        $this->destroyApplication();
        parent::tearDown();
    }

    /**
     * Returns a database connection component prepared from the test config.
     * @param bool $reset whether to re-apply the fixture
     * @param bool $open whether to open the connection
     * @return CoroutineDbConnection
     */
    public function getConnection($reset = true, $open = true): CoroutineDbConnection
    {
        if (!$reset && $this->_db !== null) {
            return $this->_db;
        }

        $config = $this->database;
        $fixture = $config['fixture'] ?? null;
        unset($config['fixture']);

        try {
            $this->prepareDatabase($config, $fixture, $reset);
        } catch (\Exception $e) {
            $this->markTestSkipped('Something wrong when preparing database: ' . $e->getMessage());
        }

        $connection = Yii::createObject(array_merge([
            'class' => $this->getConnectionClass(),
        ], $config));
        if (!$connection instanceof CoroutineDbConnection) {
            throw new \Exception('getConnectionClass() must return a CoroutineDbConnection subclass.');
        }
        $this->_db = $connection;

        if ($open) {
            $this->_db->open();
        }

        return $this->_db;
    }

    /**
     * Creates the test database if missing and loads the fixture.
     * @param array $config
     * @param string|null $fixture
     * @param bool $reset
     */
    protected function prepareDatabase(array $config, ?string $fixture, bool $reset): void
    {
        [, $dsnParams] = $this->parseDsn($config['dsn']);
        $host = $dsnParams['host'] ?? '127.0.0.1';
        $dbname = $dsnParams['dbname'] ?? 'yii2s_test';

        $serverPdo = new \PDO(
            "mysql:host={$host}",
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $serverPdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $serverPdo = null;

        if ($reset && $fixture !== null) {
            // PHP 8.5 moved the driver constants to Pdo\Mysql and deprecated
            // PDO::MYSQL_ATTR_*. Resolve at runtime so the suite stays quiet
            // on both 8.4 and 8.5 without raising the minimum PHP version.
            $multiStatements = class_exists('Pdo\\Mysql')
                ? (int) constant('Pdo\\Mysql::ATTR_MULTI_STATEMENTS')
                : (int) constant('PDO::MYSQL_ATTR_MULTI_STATEMENTS');
            $pdo = new \PDO(
                $config['dsn'],
                $config['username'],
                $config['password'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    $multiStatements => true,
                ]
            );
            $pdo->exec((string) file_get_contents($fixture));
            $pdo = null;
        }
    }

    /**
     * @param string $dsn
     * @return array [driverName, params]
     */
    protected function parseDsn(string $dsn): array
    {
        $driver = strtolower(substr($dsn, 0, strpos($dsn, ':')));
        $params = [];
        foreach (explode(';', substr($dsn, strpos($dsn, ':') + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $params[strtolower(trim($key))] = trim($value);
        }

        return [$driver, $params];
    }

    /**
     * @return string the connection component class under test
     */
    protected function getConnectionClass(): string
    {
        return CoroutineDbConnection::class;
    }
}
