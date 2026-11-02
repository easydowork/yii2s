<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\log;

use Yii2S\Swoole\Log\CoroutineFileTarget;
use yii\log\Logger;
use yii2stest\TestCase;

/**
 * @group log
 */
class CoroutineFileTargetTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = sys_get_temp_dir() . '/yii2stest-log-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    private function createTarget(int $maxBufferMessages): CoroutineFileTarget
    {
        return new CoroutineFileTarget([
            'logFile' => $this->logFile,
            'maxBufferMessages' => $maxBufferMessages,
            'exportInterval' => PHP_INT_MAX,
        ]);
    }

    private function message(string $text): array
    {
        // Official logger message layout: [text, level, category, timestamp, traces].
        return [$text, Logger::LEVEL_INFO, 'test.category', microtime(true), []];
    }

    public function testBuffersUntilThresholdReached(): void
    {
        $target = $this->createTarget(3);

        $target->collect([$this->message('one')], true);
        $this->assertFileDoesNotExist($this->logFile, 'below threshold: nothing written yet');

        $target->collect([$this->message('two'), $this->message('three')], true);
        $this->assertFileExists($this->logFile);
        $content = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('one', $content);
        $this->assertStringContainsString('three', $content);
    }

    public function testFinalFlushDoesNotWriteBelowThreshold(): void
    {
        $target = $this->createTarget(100);

        // $final=true is what the per-request logger flush produces; it must
        // NOT trigger a disk write on every request.
        $target->collect([$this->message('buffered')], true);
        $this->assertFileDoesNotExist($this->logFile);

        $target->flushNow();
        $this->assertFileExists($this->logFile);
        $this->assertStringContainsString('buffered', (string) file_get_contents($this->logFile));
    }

    public function testFlushNowWithoutMessagesWritesNothing(): void
    {
        $target = $this->createTarget(2);
        $target->flushNow();
        $this->assertFileDoesNotExist($this->logFile);
    }

    public function testExportClearsBuffer(): void
    {
        $target = $this->createTarget(2);
        $target->collect([$this->message('a'), $this->message('b')], false);
        $target->flushNow();

        $content = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('a', $content);
        $this->assertStringContainsString('b', $content);
        $target->flushNow(); // must not duplicate: buffer already empty
        $this->assertSame($content, (string) file_get_contents($this->logFile));
    }
}
