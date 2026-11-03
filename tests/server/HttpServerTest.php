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
class HttpServerTest extends ServerTestCase
{
    public function testServerServesRequestsAndStopsGracefully(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app.php');
            try {
                $index = $this->get('/');
                $this->assertSame(200, $index['status']);
                $this->assertSame('yii2stest', $index['json']['app'] ?? null);
                $this->assertGreaterThan(0, $index['json']['cid'] ?? 0);

                $echo = $this->get('/site/echo?x=1&y=hello');
                $this->assertSame(200, $echo['status']);
                $this->assertSame(['x' => '1', 'y' => 'hello'], $echo['json']['get'] ?? null);
                $this->assertSame('GET', $echo['json']['method'] ?? null);

                $missing = $this->get('/site/missing');
                $this->assertSame(404, $missing['status']);
            } finally {
                $this->stopServer();
            }
        });
    }
}
