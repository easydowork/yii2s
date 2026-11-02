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

    public function testRotationKeepsFileSizeBounded(): void
    {
        // Inherits yii\log\FileTarget's rotation; pin the semantics: when the
        // file exceeds maxFileSize the log rotates to .1/.2/... keeping at
        // most maxLogFiles history files. Long-running production servers
        // depend on this - an unbounded app.log is an outage.
        $target = new CoroutineFileTarget([
            'logFile' => $this->logFile,
            'maxBufferMessages' => 1,
            'exportInterval' => PHP_INT_MAX,
            'maxFileSize' => 1, // 1 KB - rotate almost immediately
            'maxLogFiles' => 2,
        ]);

        $rounds = 40;
        for ($i = 0; $i < $rounds; $i++) {
            $target->collect([$this->message('round-' . $i . ' ' . str_repeat('x', 120))], true);
        }

        $this->assertFileExists($this->logFile);
        $this->assertLessThan(
            200 * 1024,
            (int) filesize($this->logFile),
            'active log file must stay bounded by rotation'
        );
        $this->assertFileExists($this->logFile . '.1', 'at least one rotated file must exist');
        $this->assertFileDoesNotExist($this->logFile . '.3', 'history beyond maxLogFiles must be dropped');

        // Official rotation semantics: messages older than the history window
        // are dropped; everything inside the window survives. The last two
        // history files + active file must contain the newest rounds.
        $haystack = (string) @file_get_contents($this->logFile)
            . (string) @file_get_contents($this->logFile . '.1')
            . (string) @file_get_contents($this->logFile . '.2');
        $this->assertStringContainsString('round-' . ($rounds - 1) . ' ', $haystack, 'newest message must survive');
        $this->assertStringContainsString('round-' . ($rounds - 10) . ' ', $haystack, 'recent messages must survive rotation');
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
