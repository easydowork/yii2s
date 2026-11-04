<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

use Yii2S\Swoole\Runtime\CoroutineRequest;
use Yii2S\Swoole\Runtime\CoroutineUploadedFile;
use yii\web\UploadedFile;
use yii2stest\TestCase;

/**
 * The coroutine upload class must stay usable outside the Swoole runtime (a
 * stock web application / unit tests), where it falls back to the official
 * `$_FILES`-based implementation.
 *
 * @group compat
 */
class CoroutineUploadedFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockWebApplication();
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        UploadedFile::reset();
        parent::tearDown();
    }

    public function testFallsBackToStockUploadedFileWithoutCoroutineRequest(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'compat-fallback');
        file_put_contents($tempFile, 'abc');
        $targetFile = sys_get_temp_dir() . '/compat-fallback-target-' . bin2hex(random_bytes(4)) . '.txt';

        $_FILES = [
            'upload' => [
                'name' => 'a.txt',
                'tmp_name' => $tempFile,
                'type' => 'text/plain',
                'size' => 3,
                'error' => UPLOAD_ERR_OK,
            ],
        ];
        UploadedFile::reset();

        try {
            $file = CoroutineUploadedFile::getInstanceByName('upload');
            $this->assertNotNull($file, 'the stock $_FILES table must be used when no coroutine request exists');
            $this->assertSame('a.txt', $file->name);
            $this->assertSame('txt', $file->getExtension());
            $this->assertSame(3, $file->size);

            $this->assertTrue($file->saveAs($targetFile), 'saveAs() must work outside Swoole as well');
            $this->assertSame('abc', file_get_contents($targetFile));
            $this->assertFalse(is_file($tempFile), 'the moved temp file must be gone');
        } finally {
            @unlink($tempFile);
            @unlink($targetFile);
        }
    }

    public function testGetInstancesByNameWithMultipleFiles(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $swooleRequest = new \Swoole\Http\Request();
        // Swoole's entry-group shape: many file entries under one field key.
        $swooleRequest->files = [
            'files' => [
                0 => ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/a', 'size' => 100, 'error' => 0],
                1 => ['name' => 'b.png', 'type' => 'image/png', 'tmp_name' => '/tmp/b', 'size' => 200, 'error' => 0],
            ],
        ];
        $request->setSwooleRequest($swooleRequest);

        $this->mockWebApplication();
        \Yii::$app->set('request', $request);

        $files = CoroutineUploadedFile::getInstancesByName('files');

        $this->assertCount(2, $files);
        $this->assertSame('a.jpg', $files[0]->name);
        $this->assertSame('b.png', $files[1]->name);
        $this->assertSame(100, $files[0]->size);
        $this->assertSame(200, $files[1]->size);
    }
}
