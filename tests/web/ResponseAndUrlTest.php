<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\web;

use Yii;
use yii2stest\TestCase;

/**
 * Response and URL-manager behavior under the coroutine runtime, ported from
 * the official framework test scope (tests/framework/web/): sendFile,
 * redirect, signed-cookie round-trips and URL rules (parse + create) through
 * CoroutineRequest/Response.
 *
 * @group web
 */
class ResponseAndUrlTest extends TestCase
{
    public function testSendFileServesContentWithHeaders(): void
    {
        $this->runInCoroutine(function (): void {
            $this->mockWebApplication();
            $source = tempnam(sys_get_temp_dir(), 'dl');
            file_put_contents($source, 'file-download-payload-' . str_repeat('y', 999));
            Yii::$app->response->sendFile($source, 'report.bin', ['mimeType' => 'application/octet-stream']);

            $response = Yii::$app->response;
            $this->assertSame('application/octet-stream', $response->getHeaders()->get('Content-Type'));
            $this->assertSame('attachment; filename="report.bin"', $response->getHeaders()->get('Content-Disposition'));
            $this->assertGreaterThan(1000, (int) $response->getHeaders()->get('Content-Length'));
            // sendFile streams the file - the payload lives in the stream
            // handle, not in content.
            $this->assertIsArray($response->stream, 'sendFile must set the stream property');
            rewind($response->stream[0]);
            $streamed = (string) stream_get_contents($response->stream[0]);
            fclose($response->stream[0]);
            $this->assertStringContainsString('file-download-payload', $streamed);
            unlink($source);
        });
    }

    public function testRedirectSetsLocationHeader(): void
    {
        $this->runInCoroutine(function (): void {
            $this->mockWebApplication();
            Yii::$app->response->redirect('/login?next=/dashboard', 302);

            $response = Yii::$app->response;
            $this->assertSame(302, $response->statusCode);
            $this->assertStringContainsString('/login', (string) $response->getHeaders()->get('Location'));
        });
    }

    public function testSignedCookieRoundTrip(): void
    {
        $this->runInCoroutine(function (): void {
            // The signed value travels in the Cookie header and must
            // hash-validate through cookieValidationKey on the way in
            // (official Cookie::validateValue semantics).
            $this->mockWebApplication([
                'components' => [
                    'request' => [
                        'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
                        'cookieValidationKey' => 'resp-url-test-key',
                        'enableCsrfValidation' => false,
                    ],
                ],
            ]);
            $signed = Yii::$app->security->hashData(serialize(['remember', 'token-123']), 'resp-url-test-key');

            $swooleRequest = $this->fakeSwooleRequest('GET', '/');
            $swooleRequest->cookie = ['remember' => $signed];
            $appRequest = Yii::$app->request;
            if ($appRequest instanceof \Yii2S\Swoole\Runtime\CoroutineRequest) {
                $appRequest->setSwooleRequest($swooleRequest);
            }

            $incoming = Yii::$app->request->getCookies()->get('remember');
            $this->assertNotNull($incoming, 'signed cookie must validate on the way in');
            $this->assertSame('token-123', $incoming->value);
        });
    }

    public function testUrlRulesParseAndCreate(): void
    {
        $this->runInCoroutine(function (): void {
            $this->mockWebApplication([
                'components' => [
                    'request' => [
                        'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
                        'cookieValidationKey' => 'url-test-key',
                        'enableCsrfValidation' => false,
                    ],
                    'urlManager' => [
                        'enablePrettyUrl' => true,
                        'showScriptName' => false,
                        'rules' => [
                            'POST posts' => 'post/create',
                            'GET posts/<id:\d+>' => 'post/view',
                            'downloads/<file:[\w\-\.]+>' => 'download/get',
                        ],
                    ],
                ],
            ]);

            // parseRequest: method-aware rules and named parameters must map
            // to route + params exactly like FPM would.
            $appRequest = Yii::$app->request;
            $this->assertInstanceOf(\Yii2S\Swoole\Runtime\CoroutineRequest::class, $appRequest);
            $appRequest->setSwooleRequest($this->fakeSwooleRequest('GET', '/posts/17'));
            [$route, $params] = $appRequest->resolve();
            $this->assertSame('post/view', $route);
            $this->assertSame('17', $params['id'] ?? null);

            $appRequest->setSwooleRequest($this->fakeSwooleRequest('POST', '/posts'));
            [$route] = $appRequest->resolve();
            $this->assertSame('post/create', $route);

            $appRequest->setSwooleRequest($this->fakeSwooleRequest('GET', '/downloads/manual-v2.pdf'));
            [$route, $params] = $appRequest->resolve();
            $this->assertSame('download/get', $route);
            $this->assertSame('manual-v2.pdf', $params['file'] ?? null);

            // createUrl: the reverse direction must round-trip.
            $url = Yii::$app->urlManager->createUrl(['post/view', 'id' => 17]);
            $this->assertSame('/posts/17', $url);
        });
    }

    private function fakeSwooleRequest(string $method, string $uri): \Swoole\Http\Request
    {
        $request = new \Swoole\Http\Request();
        $request->server = ['request_method' => $method, 'request_uri' => $uri];
        $request->header = ['host' => 'test.local'];
        $request->get = [];
        $request->post = [];
        $request->files = [];
        $request->cookie = [];

        return $request;
    }
}
