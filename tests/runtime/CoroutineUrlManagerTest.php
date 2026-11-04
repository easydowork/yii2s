<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\runtime;

use Yii2S\Swoole\Runtime\CoroutineRequest;
use Yii2S\Swoole\Runtime\CoroutineUrlManager;
use yii2stest\TestCase;

/**
 * @group runtime
 */
class CoroutineUrlManagerTest extends TestCase
{
    public function testParseRequestCachesRoutes(): void
    {
        $manager = $this->createUrlManager();
        $request = $this->createRequest('GET', '/posts/42');

        $first = $manager->parseRequest($request);
        $this->assertSame(['post/view', ['id' => '42']], $first);

        // The repeated request resolves identically and stays a single cache entry.
        $this->assertSame($first, $manager->parseRequest($request));
        $this->assertSame(1, $manager->getParseCacheSize(), 'the repeated route is served from the cache');
    }

    public function testDifferentRoutesAreCachedIndependently(): void
    {
        $manager = $this->createUrlManager();

        $this->assertSame(['post/index', []], $manager->parseRequest($this->createRequest('GET', '/posts')));
        $this->assertSame(['post/view', ['id' => '7']], $manager->parseRequest($this->createRequest('GET', '/posts/7')));
        $this->assertSame(['post/create', []], $manager->parseRequest($this->createRequest('POST', '/posts')));

        $this->assertSame(3, $manager->getParseCacheSize());
    }

    public function testCacheIsBounded(): void
    {
        $manager = new CoroutineUrlManager([
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'maxParseCache' => 2,
            'rules' => ['GET items/<id:\d+>' => 'item/view'],
        ]);

        $manager->parseRequest($this->createRequest('GET', '/items/1'));
        $manager->parseRequest($this->createRequest('GET', '/items/2'));
        $manager->parseRequest($this->createRequest('GET', '/items/3'));

        $this->assertSame(2, $manager->getParseCacheSize(), 'the cache caps out at maxParseCache');
    }

    private function createUrlManager(): CoroutineUrlManager
    {
        return new CoroutineUrlManager([
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'GET posts' => 'post/index',
                'GET posts/<id:\d+>' => 'post/view',
                'POST posts' => 'post/create',
            ],
        ]);
    }

    private function createRequest(string $method, string $uri): CoroutineRequest
    {
        $swooleRequest = new \Swoole\Http\Request();
        $swooleRequest->get = [];
        $swooleRequest->post = [];
        $swooleRequest->cookie = [];
        $swooleRequest->header = [];
        $swooleRequest->server = [
            'request_method' => $method,
            'request_uri' => $uri,
            'remote_addr' => '127.0.0.1',
        ];

        $request = new CoroutineRequest(['cookieValidationKey' => 'test']);
        $request->setSwooleRequest($swooleRequest);

        return $request;
    }
}
