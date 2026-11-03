<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\db;

use yii2stest\DatabaseTestCase;
use Yii2S\Swoole\Db\CoroutineCommand;

/**
 * @group db
 * @group mysql
 */
class CoroutineCommandTest extends DatabaseTestCase
{
    protected $driverName = 'mysql';

    public function testIsConnectionLostDetection(): void
    {
        // Connection-loss errors must be recognised.
        $this->assertTrue(CoroutineCommand::isConnectionLost(new \yii\db\Exception(
            'SQLSTATE[HY000] [2006] MySQL server has gone away',
            ['HY000', 2006, 'MySQL server has gone away'],
            '2006'
        )));
        $this->assertTrue(CoroutineCommand::isConnectionLost(new \yii\db\Exception(
            'SQLSTATE[HY000] [2013] Lost connection to MySQL server during query',
            ['HY000', 2013, 'Lost connection to MySQL server during query'],
            '2013'
        )));
        $this->assertTrue(CoroutineCommand::isConnectionLost(new \yii\db\Exception(
            'SQLSTATE[08S01] Communication link failure',
            ['08S01', 0, 'Communication link failure'],
            '0'
        )));

        $pdoException = new \PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away', 2006);
        $pdoException->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        $this->assertTrue(CoroutineCommand::isConnectionLost($pdoException));

        // SQL errors leave the connection usable and must NOT be discarded.
        $this->assertFalse(CoroutineCommand::isConnectionLost(new \yii\db\Exception(
            'Duplicate entry',
            ['23000', 1062, 'Duplicate entry'],
            '1062'
        )));
        $this->assertFalse(CoroutineCommand::isConnectionLost(new \yii\db\Exception(
            'Deadlock found',
            ['40001', 1213, 'Deadlock found'],
            '1213'
        )));
        $this->assertFalse(CoroutineCommand::isConnectionLost(new \yii\db\Exception(
            'Syntax error',
            ['42000', 1064, 'You have an error in your SQL syntax'],
            '1064'
        )));
        $this->assertFalse(CoroutineCommand::isConnectionLost(new \RuntimeException('not a db error')));
    }

    public function testGoneAwayConnectionIsDiscardedAndRecovers(): void
    {
        $db = $this->getConnection(false, false);

        $this->runInCoroutine(function () use ($db): void {
            // Establish a sticky connection and note its server-side id.
            $db->createCommand('SELECT 1')->queryScalar();
            $connectionId = (int) $db->createCommand('SELECT CONNECTION_ID()')->queryScalar();
            $this->assertGreaterThan(0, $connectionId);

            // Kill the connection from a separate session - this reproduces a
            // MySQL restart / idle-timeout from the client's point of view.
            $killer = new \PDO(
                $db->dsn,
                $db->username,
                $db->password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $killer->exec("KILL {$connectionId}");
            $killer = null;

            // The next statement hits the dead connection and must fail...
            $caught = null;
            try {
                $db->createCommand('SELECT 1')->queryScalar();
            } catch (\Throwable $e) {
                $caught = $e;
            }
            $this->assertNotNull($caught, 'expected the killed connection to fail');
            $this->assertTrue(CoroutineCommand::isConnectionLost($caught));

            // ...and the broken connection must be discarded, not returned.
            $metrics = $db->getPool()->getMetrics();
            $this->assertSame(1, $metrics['total_discarded'], 'the dead connection must be discarded');
            $this->assertSame(0, $metrics['size'], 'pool must shrink back to zero');

            // The next request transparently opens a fresh connection.
            $this->assertSame('1', (string) $db->createCommand('SELECT 1')->queryScalar());
            $this->assertSame(1, $db->getPool()->getSize(), 'a replacement connection is created');
        });
    }
}
