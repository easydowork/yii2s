<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

use Yii2S\Swoole\Server\RequestDispatcher;

/**
 * Raw routes answer requests without creating the Yii request/response, and
 * must coexist with the normal Yii pipeline (method mismatch falls through,
 * exceptions return 500, unknown paths still route through Yii).
 *
 * @group compat
 * @group integration
 */
class RawRouteTest extends CompatTestCase
{
    /**
     * @testdox Raw routes bypass Yii, respect the method and fail safely
     */
    public function testRawRoutes(): void
    {
        $this->runInCoroutine(function (): void {
            $dispatcher = new RequestDispatcher(__DIR__ . '/../data/app-raw.php');
            $dispatcher
                ->addRawRoute('GET', '/ping', static fn (): string => 'pong')
                ->addRawRoute('GET', '/health', static fn (): array => ['ok' => true])
                ->addRawRoute('GET', '/boom', static function (): string {
                    throw new \RuntimeException('raw-route-boom');
                });

            $this->startServerWithDispatcher($dispatcher);
            try {
                $res = $this->http('GET', '/ping');
                $this->assertSame(200, $res['status']);
                $this->assertSame('pong', $res['body']);
                $this->assertStringContainsString('text/plain', $this->header($res['headers'], 'content-type'));

                $res = $this->http('GET', '/ping?query=1');
                $this->assertSame('pong', $res['body'], 'the query string does not participate in matching');

                $res = $this->http('GET', '/health');
                $this->assertSame(200, $res['status']);
                $this->assertSame(['ok' => true], $res['json']);
                $this->assertStringContainsString('application/json', $this->header($res['headers'], 'content-type'));

                $res = $this->http('POST', '/ping');
                $this->assertSame(404, $res['status'], 'a method mismatch falls through to Yii');

                $res = $this->http('GET', '/boom');
                $this->assertSame(500, $res['status'], 'a throwing handler returns 500');

                $res = $this->http('GET', '/compat/index');
                $this->assertSame(200, $res['status'], 'normal Yii routing still works next to raw routes');
                $this->assertSame('yii2stest-compat', $res['json']['app'] ?? null);
            } finally {
                $this->stopServer();
            }
        });
    }
}
