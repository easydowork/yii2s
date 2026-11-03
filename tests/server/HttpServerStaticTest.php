<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use yii2stest\ServerTestCase;

/**
 * @group server
 * @group integration
 */
class HttpServerStaticTest extends ServerTestCase
{
    private string $docRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docRoot = sys_get_temp_dir() . '/yii2stest-docroot-' . bin2hex(random_bytes(4));
        mkdir($this->docRoot . '/css', 0777, true);
        file_put_contents($this->docRoot . '/css/app.css', 'body { color: red; }');
    }

    protected function tearDown(): void
    {
        @unlink($this->docRoot . '/css/app.css');
        @rmdir($this->docRoot . '/css');
        @rmdir($this->docRoot);
        parent::tearDown();
    }

    public function testStaticFilesAreServedAndMissingPathsFallThrough(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app.php', [
                'server' => ['documentRoot' => $this->docRoot],
            ]);
            try {
                $css = $this->get('/css/app.css');
                $this->assertSame(200, $css['status']);
                $this->assertSame('body { color: red; }', $css['body']);
                $this->assertSame('text/css; charset=utf-8', (string) ($css['headers']['content-type'] ?? ''));

                // Missing paths fall through to the Yii2 router (404).
                $missing = $this->get('/site/missing');
                $this->assertSame(404, $missing['status']);

                // Yii routes keep working alongside static files.
                $index = $this->get('/');
                $this->assertSame(200, $index['status']);
                $this->assertSame('yii2stest', $index['json']['app'] ?? null);
            } finally {
                $this->stopServer();
            }
        });
    }

    public function testExecutableAndHiddenFilesAreNotServed(): void
    {
        file_put_contents($this->docRoot . '/secret.php', '<?php echo "leak";');
        file_put_contents($this->docRoot . '/.env', "SECRET=1\n");

        try {
            $this->runInCoroutine(function (): void {
                $this->startServer(__DIR__ . '/../data/app.php', [
                    'server' => ['documentRoot' => $this->docRoot],
                ]);
                try {
                    $php = $this->get('/secret.php');
                    $this->assertSame(404, $php['status'], 'PHP source must never be served as a static file');

                    $env = $this->get('/.env');
                    $this->assertSame(404, $env['status'], 'hidden files must never be served as a static file');

                    // An encoded null byte must be rejected *after* decoding,
                    // before it reaches realpath().
                    $nullByte = $this->get('/css/app.css%00.png');
                    $this->assertSame(404, $nullByte['status'], 'encoded null bytes must not reach the filesystem');
                } finally {
                    $this->stopServer();
                }
            });
        } finally {
            @unlink($this->docRoot . '/secret.php');
            @unlink($this->docRoot . '/.env');
        }
    }
}
