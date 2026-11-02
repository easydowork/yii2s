<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Yii2S\Swoole\Server\PidFile;
use yii2stest\TestCase;

/**
 * @group server
 */
class PidFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/yii2stest-pidfile-' . bin2hex(random_bytes(4)) . '.pid';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function testWriteReadRemoveRoundtrip(): void
    {
        $pidFile = new PidFile($this->path);

        $this->assertFalse($pidFile->exists());
        $this->assertNull($pidFile->read());

        $pidFile->write(['pid' => 123, 'port' => 9501]);

        $this->assertTrue($pidFile->exists());
        $this->assertSame(['pid' => 123, 'port' => 9501], $pidFile->read());

        $pidFile->remove();
        $this->assertFalse($pidFile->exists());
    }

    public function testReadReturnsNullForInvalidJson(): void
    {
        $pidFile = new PidFile($this->path);
        file_put_contents($this->path, 'not-json{');

        $this->assertTrue($pidFile->exists());
        $this->assertNull($pidFile->read());
        $this->assertFalse($pidFile->isAlive());
    }

    public function testCreatesMissingDirectory(): void
    {
        $nested = $this->path . '-dir/sub/inner.pid';
        $pidFile = new PidFile($nested);

        try {
            $pidFile->write(['pid' => 1]);
            $this->assertFileExists($nested);
        } finally {
            @unlink($nested);
            @rmdir(dirname($nested));
            @rmdir(dirname($nested, 2));
        }
    }

    public function testIsAliveForCurrentProcess(): void
    {
        $this->assertTrue(PidFile::isProcessAlive((int) getmypid()));

        $pidFile = new PidFile($this->path);
        $pidFile->write(['pid' => (int) getmypid()]);
        $this->assertTrue($pidFile->isAlive());
    }

    public function testIsAliveForNonExistentProcess(): void
    {
        // A pid from a killed process is unlikely to be reused immediately;
        // use an absurdly high pid that cannot exist.
        $this->assertFalse(PidFile::isProcessAlive(4194304));

        $pidFile = new PidFile($this->path);
        $pidFile->write(['pid' => 4194304]);
        $this->assertFalse($pidFile->isAlive());
    }

    public function testIsAliveWithoutPidKey(): void
    {
        $pidFile = new PidFile($this->path);
        $pidFile->write(['port' => 9501]);

        $this->assertFalse($pidFile->isAlive());
    }
}
