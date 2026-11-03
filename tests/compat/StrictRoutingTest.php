<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

/**
 * With `enableStrictParsing`, `UrlManager::parseRequest()` returns false for
 * unknown paths; that must become a 404, never a type error.
 *
 * @group compat
 * @group integration
 */
class StrictRoutingTest extends CompatTestCase
{
    /**
     * @testdox Strict parsing turns unmatched paths into a 404
     */
    public function testUnmatchedPathUnderStrictParsing(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer('app-strict.php');
            try {
                $res = $this->http('GET', '/compat/index');
                $this->assertSame(200, $res['status']);

                $res = $this->http('GET', '/definitely-not-a-route');
                $this->assertSame(404, $res['status'], 'an unmatched path must be a 404, not a 500');
            } finally {
                $this->stopServer();
            }
        });
    }
}
