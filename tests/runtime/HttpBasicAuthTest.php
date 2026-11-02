<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\runtime;

use Yii2S\Swoole\Runtime\CoroutineRequest;
use yii2stest\TestCase;

/**
 * @group runtime
 */
class HttpBasicAuthTest extends TestCase
{
    private function createRequestWithHeader(?string $authorization): CoroutineRequest
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $swooleRequest = new \Swoole\Http\Request();
        $swooleRequest->get = [];
        $swooleRequest->post = [];
        $swooleRequest->cookie = [];
        $swooleRequest->header = $authorization === null
            ? []
            : ['authorization' => $authorization];
        $swooleRequest->server = [
            'request_method' => 'GET',
            'request_uri' => '/',
        ];
        $request->setSwooleRequest($swooleRequest);

        return $request;
    }

    public function testParsesBasicAuthCredentials(): void
    {
        $request = $this->createRequestWithHeader(
            'Basic ' . base64_encode('alice:s3cret')
        );

        $this->assertSame('alice', $request->getAuthUser());
        $this->assertSame('s3cret', $request->getAuthPassword());
        $this->assertSame(['alice', 's3cret'], $request->getAuthCredentials());
    }

    public function testPasswordMayContainColons(): void
    {
        $request = $this->createRequestWithHeader(
            'Basic ' . base64_encode('alice:pa:ss:word')
        );

        $this->assertSame('alice', $request->getAuthUser());
        $this->assertSame('pa:ss:word', $request->getAuthPassword());
    }

    public function testEmptyPasswordIsNull(): void
    {
        $request = $this->createRequestWithHeader(
            'Basic ' . base64_encode('alice:')
        );

        $this->assertSame('alice', $request->getAuthUser());
        $this->assertNull($request->getAuthPassword());
    }

    public function testMissingHeaderReturnsNulls(): void
    {
        $request = $this->createRequestWithHeader(null);

        $this->assertNull($request->getAuthUser());
        $this->assertNull($request->getAuthPassword());
    }

    public function testNonBasicSchemeIsIgnored(): void
    {
        $request = $this->createRequestWithHeader(
            'Bearer ' . base64_encode('alice:s3cret')
        );

        $this->assertNull($request->getAuthUser());
        $this->assertNull($request->getAuthPassword());
    }

    public function testCaseInsensitiveScheme(): void
    {
        $request = $this->createRequestWithHeader(
            'BASIC ' . base64_encode('bob:pw')
        );

        $this->assertSame('bob', $request->getAuthUser());
        $this->assertSame('pw', $request->getAuthPassword());
    }
}
