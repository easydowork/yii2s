<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\runtime;

use Yii;
use Yii2S\Swoole\Runtime\CoroutineRequest;
use yii\web\Request;
use yii2stest\TestCase;

/**
 * @group runtime
 */
class CoroutineRequestTest extends TestCase
{
    private function createSwooleRequest(array $options = []): \Swoole\Http\Request
    {
        $request = new \Swoole\Http\Request();
        $request->get = $options['get'] ?? [];
        $request->post = $options['post'] ?? [];
        $request->cookie = $options['cookie'] ?? [];
        $request->header = $options['header'] ?? [];
        $request->server = array_merge([
            'request_method' => 'GET',
            'request_uri' => '/',
            'remote_addr' => '127.0.0.1',
        ], $options['server'] ?? []);

        return $request;
    }

    public function testMethodAndPathInfo(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'server' => [
                'request_method' => 'POST',
                'request_uri' => '/demo/db',
                'query_string' => 'page=2',
            ],
        ]));

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('demo/db', $request->getPathInfo());
        $this->assertSame('/demo/db?page=2', $request->getUrl());
        $this->assertSame('page=2', $request->getQueryString());
        $this->assertSame('127.0.0.1', $request->getUserIP());
    }

    public function testQueryAndBodyParams(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'get' => ['page' => '2', 'sort' => 'name'],
            'post' => ['title' => 'hello'],
            'header' => ['content-type' => 'application/x-www-form-urlencoded'],
            'server' => ['request_uri' => '/posts', 'query_string' => ''],
        ]));

        $this->assertSame(['page' => '2', 'sort' => 'name'], $request->getQueryParams());
        $this->assertSame(['title' => 'hello'], $request->getBodyParams());
    }

    public function testJsonBodyParams(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'header' => ['content-type' => 'application/json'],
            'server' => ['request_uri' => '/api'],
        ]));

        $this->assertSame('application/json', $request->getContentType());
        // rawContent() is only available on real server requests; with a bare
        // object it returns an empty string, which must parse to [].
        $this->assertSame([], $request->getBodyParams());
    }

    public function testHeaders(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'header' => [
                'host' => 'example.com',
                'x-custom-header' => 'custom-value',
            ],
            'server' => ['request_uri' => '/'],
        ]));

        $this->assertSame('example.com', $request->getHeaders()->get('Host'));
        $this->assertSame('example.com', $request->getHeaders()->get('HOST'));
        $this->assertSame('custom-value', $request->getHeaders()->get('X-Custom-Header'));
        $this->assertSame('http://example.com', $request->getHostInfo());
    }

    public function testServerNameAndPort(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'header' => ['host' => 'example.com'],
            'server' => ['request_uri' => '/', 'server_port' => '9501'],
        ]));

        $this->assertSame('example.com', $request->getServerName());
        $this->assertSame(9501, $request->getServerPort());
    }

    public function testServerPortFromForwardedHeader(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'header' => ['host' => 'example.com', 'x-forwarded-port' => '443'],
            'server' => ['request_uri' => '/', 'server_port' => '9501'],
        ]));

        $this->assertSame(443, $request->getServerPort());
    }

    public function testSecureConnectionDetection(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'server' => ['request_uri' => '/', 'https' => 'on'],
        ]));
        $this->assertTrue($request->getIsSecureConnection());

        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'header' => ['x-forwarded-proto' => 'https', 'host' => 'secure.example.com'],
            'server' => ['request_uri' => '/'],
        ]));
        $this->assertTrue($request->getIsSecureConnection());
        $this->assertSame('https://secure.example.com', $request->getHostInfo());
    }

    public function testSecureConnectionHonorsSecureProtocolHeadersConfig(): void
    {
        $request = new CoroutineRequest([
            'cookieValidationKey' => 'test',
            'secureProtocolHeaders' => [], // disable proxy-header trust
        ]);
        $request->setSwooleRequest($this->createSwooleRequest([
            'header' => ['x-forwarded-proto' => 'https', 'host' => 'plain.example.com'],
            'server' => ['request_uri' => '/'],
        ]));

        $this->assertFalse($request->getIsSecureConnection());
        $this->assertSame('http://plain.example.com', $request->getHostInfo());
    }

    public function testCookies(): void
    {
        // Raw semantics (validation disabled): every cookie passes through.
        $request = new CoroutineRequest([
            'cookieValidationKey' => 'test',
            'enableCookieValidation' => false,
        ]);
        $request->setSwooleRequest($this->createSwooleRequest([
            'cookie' => ['PHPSESSID' => 'abc123', 'theme' => 'dark'],
            'server' => ['request_uri' => '/'],
        ]));

        $cookies = $request->getCookies();
        $this->assertSame('abc123', $cookies->getValue('PHPSESSID'));
        $this->assertSame('dark', $cookies->getValue('theme'));
        $this->assertNull($cookies->getValue('missing'));
    }

    public function testTamperedCookieIsDropped(): void
    {
        // Official loadCookies() semantics with validation enabled: a cookie
        // whose signature does not match the validation key must be dropped
        // (this is what protects the auto-login identity cookie).
        $this->mockWebApplication([
            'components' => [
                'request' => [
                    'class' => CoroutineRequest::class,
                    'cookieValidationKey' => 'tamper-test-key',
                    'enableCsrfValidation' => false,
                ],
            ],
        ]);
        $request = new CoroutineRequest(['cookieValidationKey' => 'tamper-test-key']);
        $request->setSwooleRequest($this->createSwooleRequest([
            'cookie' => [
                'valid' => Yii::$app->security->hashData(serialize(['valid', 'good']), 'tamper-test-key'),
                'tampered' => '9999deadbeef:' . serialize(['tampered', 'evil']),
                'unsigned' => 'plain-value',
            ],
            'server' => ['request_uri' => '/'],
        ]));

        $cookies = $request->getCookies();
        $this->assertSame('good', $cookies->getValue('valid'), 'validly signed cookie must pass');
        $this->assertNull($cookies->getValue('tampered'), 'tampered signature must be dropped');
        $this->assertNull($cookies->getValue('unsigned'), 'unsigned value must not pass validation');
    }

    public function testExtendsYiiWebRequest(): void
    {
        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $this->assertInstanceOf(Request::class, $request);
    }
}
