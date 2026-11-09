<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * Yii2 feature-point compatibility suite: every test drives one area of the
 * framework through the real Swoole server and asserts the behaviour a
 * standard Yii2 application (previously on PHP-FPM) depends on.
 *
 * @group compat
 * @group integration
 */
class CompatHttpFeaturesTest extends CompatTestCase
{
    /**
     * @testdox Pretty URL rules, parameter binding and URL generation
     */
    public function testRoutingRulesAndUrlGeneration(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/hello/bob');
            $this->assertSame(200, $res['status']);
            $this->assertSame('bob', $res['json']['name'], 'rule parameter is bound to the action argument');
            $this->assertSame('/compat/hello/bob', $res['json']['url']);

            $res = $this->http('GET', '/compat/post/42');
            $this->assertSame(200, $res['status']);
            $this->assertSame(42, $res['json']['id'], 'typed route parameter is cast by the action signature');

            $res = $this->http('GET', '/compat/hello/BOB');
            $this->assertSame(404, $res['status'], 'a rule that requires [a-z]+ must not match uppercase');

            $res = $this->http('GET', '/compat/hello');
            $this->assertSame(200, $res['status'], 'default route parsing still works without a rule match');
            $this->assertSame('world', $res['json']['name'], 'action argument default is used');

            $res = $this->http('GET', '/compat/urls');
            $this->assertSame('/compat/hello/bob', $res['json']['relative'], 'Url::to honors the rule');
            $this->assertStringStartsWith('http://127.0.0.1:', $res['json']['absolute'], 'absolute URLs use the request host');
            $this->assertSame('', $res['json']['baseUrl'], 'the application is mounted at the domain root');
        });
    }

    /**
     * @testdox Request object exposes query, body, headers, cookies and client facts
     */
    public function testRequestObjectInputs(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/open/request?x=1&y=two', [
                'headers' => [
                    'X-Compat' => 'hdr',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'User-Agent' => 'compat-agent',
                ],
            ]);
            $body = $res['json'];
            $this->assertSame(200, $res['status']);
            $this->assertSame('GET', $body['method']);
            $this->assertSame(['x' => '1', 'y' => 'two'], $body['query'], 'query params come from the coroutine request');
            $this->assertSame('hdr', $body['header'], 'custom headers are readable');
            $this->assertSame('compat-agent', $body['userAgent']);
            $this->assertTrue($body['isAjax'], 'X-Requested-With drives isAjax');
            $this->assertSame('open/request', $body['pathInfo']);
            $this->assertSame('/open/request?x=1&y=two', $body['url']);
            $this->assertStringStartsWith('http://127.0.0.1:', $body['hostInfo']);
            $this->assertSame('127.0.0.1', $body['userIP']);
            $this->assertSame(sha1(''), $body['rawBodySha1']);

            $res = $this->http('POST', '/open/request', [
                'body' => ['a' => '1', 'nested' => ['k' => 'v']],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);
            $this->assertSame(200, $res['status']);
            $this->assertSame('POST', $res['json']['method']);
            $this->assertSame(['a' => '1', 'nested' => ['k' => 'v']], $res['json']['post'], 'urlencoded bodies are parsed');
            $this->assertStringContainsString('application/x-www-form-urlencoded', (string) $res['json']['contentType']);

            $json = json_encode(['j' => ['k' => 'v']], JSON_THROW_ON_ERROR);
            $res = $this->http('POST', '/open/request', [
                'body' => $json,
                'headers' => ['Content-Type' => 'application/json'],
            ]);
            $this->assertSame(200, $res['status']);
            $this->assertSame(['j' => ['k' => 'v']], $res['json']['post'], 'JSON bodies are parsed');
            $this->assertSame(sha1($json), $res['json']['rawBodySha1'], 'raw body is intact');
            $this->assertStringContainsString('application/json', (string) $res['json']['contentType']);

            $res = $this->http('PUT', '/open/request', ['body' => 'put-body']);
            $this->assertSame(200, $res['status']);
            $this->assertSame('PUT', $res['json']['method'], 'non-GET/POST verbs reach the request object');

            // Trusted proxy: the right-most untrusted X-Forwarded-For hop wins
            // (10.1.2.3 matches the configured 10.0.0.0/8 block).
            $res = $this->http('GET', '/open/request', [
                'headers' => ['X-Forwarded-For' => '198.51.100.7, 10.1.2.3'],
            ]);
            $this->assertSame(200, $res['status']);
            $this->assertSame('198.51.100.7', $res['json']['userIP'], 'trusted proxy resolves the client IP');
        });
    }

    /**
     * @testdox Response formats, status codes and custom headers
     */
    public function testResponseFormatsAndStatus(): void
    {
        $this->withServer(function (): void {
            // identity encoding keeps the wire length equal to the body length
            $res = $this->http('GET', '/compat/format?as=json', ['headers' => ['Accept-Encoding' => 'identity']]);
            $this->assertSame(200, $res['status']);
            $this->assertSame(['format' => 'json', 'nested' => ['a' => 1]], $res['json']);
            $this->assertStringContainsString('application/json', $this->header($res['headers'], 'content-type'));
            $this->assertSame(
                (string) strlen($res['body']),
                $this->header($res['headers'], 'content-length'),
                'in-memory bodies are framed with Content-Length (not chunked) so HTTP/1.0 clients work'
            );
            $this->assertSame('', $this->header($res['headers'], 'transfer-encoding'));

            $res = $this->http('GET', '/compat/format?as=xml');
            $this->assertSame(200, $res['status']);
            $this->assertStringContainsString('application/xml', $this->header($res['headers'], 'content-type'));
            $this->assertStringContainsString('<name>compat</name>', $res['body'], 'XML formatter output');

            $res = $this->http('GET', '/compat/format?as=html');
            $this->assertSame(200, $res['status']);
            $this->assertStringContainsString('text/html', $this->header($res['headers'], 'content-type'));
            $this->assertSame('<h1>compat-html</h1>', $res['body']);

            $res = $this->http('GET', '/compat/format?as=raw');
            $this->assertSame(200, $res['status']);
            $this->assertStringContainsString('text/plain', $this->header($res['headers'], 'content-type'));
            $this->assertSame('compat-raw', $res['body'], 'raw content is not wrapped');

            $res = $this->http('GET', '/compat/status');
            $this->assertSame(201, $res['status'], 'statusCode set by the action is honored');
            $this->assertSame('yes', $this->header($res['headers'], 'x-compat'), 'custom headers are sent');
        });
    }

    /**
     * @testdox Redirect responses keep status and Location
     */
    public function testRedirect(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/redirect');
            $this->assertSame(302, $res['status']);
            $this->assertStringContainsString('/compat/target', $this->header($res['headers'], 'location'));

            $res = $this->http('GET', '/compat/target');
            $this->assertSame(['at' => 'target'], $res['json'], 'the redirect target is reachable');
        });
    }

    /**
     * @testdox Signed cookie round-trip and tamper rejection
     */
    public function testSignedCookies(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/cookie?set=v1');
            $this->assertSame(200, $res['status']);
            $this->assertNull($res['json']['value'], 'the cookie is set on the response, not readable yet');
            $this->assertArrayHasKey('compat_cookie', $this->cookies);
            $this->assertNotSame('v1', $this->cookies['compat_cookie'], 'the value must be signed, not plaintext');

            $res = $this->http('GET', '/compat/cookie');
            $this->assertSame('v1', $res['json']['value'], 'the signed cookie round-trips');

            $res = $this->http('GET', '/compat/cookie', ['cookies' => 'compat_cookie=evil']);
            $this->assertNull($res['json']['value'], 'a tampered cookie must be dropped');
        });
    }

    /**
     * @testdox CSRF token issuance and validation on unsafe verbs
     */
    public function testCsrfProtection(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/csrf');
            $this->assertSame(200, $res['status']);
            $token = (string) $res['json']['token'];
            $param = (string) $res['json']['param'];
            $this->assertNotSame('', $token);

            $res = $this->http('POST', '/compat/submit', ['body' => ['x' => '1']]);
            $this->assertSame(400, $res['status'], 'POST without a token must be rejected');

            $res = $this->http('POST', '/compat/submit', ['body' => [$param => $token, 'x' => '1']]);
            $this->assertSame(200, $res['status'], 'POST with the token must pass');
            $this->assertSame('1', $res['json']['post']['x']);

            $res = $this->http('POST', '/compat/submit', [
                'body' => 'x=1',
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'X-CSRF-Token' => 'wrong-token',
                ],
            ]);
            $this->assertSame(400, $res['status'], 'a wrong token in the header must be rejected');
        });
    }

    /**
     * @testdox Session persistence, request isolation and id regeneration
     */
    public function testSessionLifecycle(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/session');
            $first = $res['json'];
            $this->assertSame(1, $first['hits'], 'first request starts the counter');
            $this->assertTrue($first['active']);
            $this->assertNotSame('', $first['id']);
            $firstId = $first['id'];

            $res = $this->http('GET', '/compat/session');
            $this->assertSame(2, $res['json']['hits'], 'the session survives across requests');
            $this->assertSame($firstId, $res['json']['id']);

            // A client without the cookie gets its own session.
            $saved = $this->cookies;
            $this->cookies = [];
            $res = $this->http('GET', '/compat/session');
            $this->assertSame(1, $res['json']['hits'], 'a fresh client must not see another session');
            $this->assertNotSame($firstId, $res['json']['id']);

            $this->cookies = $saved;
            $res = $this->http('GET', '/compat/session');
            $this->assertSame(3, $res['json']['hits'], 'the original session is intact');
        });
    }

    /**
     * @testdox Login/logout over sessions, with pre-login data preserved
     */
    public function testAuthenticationLifecycle(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/session');
            $sessionBeforeLogin = (string) $res['json']['id'];

            $res = $this->http('GET', '/compat/login?id=1');
            $this->assertSame(200, $res['status']);
            $this->assertSame(1, $res['json']['id'], 'user id comes from the identity');
            $this->assertFalse($res['json']['guest']);
            $this->assertTrue($res['json']['sessionDataKept'], 'pre-login session data must survive regeneration');

            $sessionAfterLogin = (string) $res['json']['session'];
            $this->assertNotSame($sessionBeforeLogin, $sessionAfterLogin, 'login regenerates the session id');

            $res = $this->http('GET', '/compat/whoami');
            $this->assertSame(1, $res['json']['id'], 'the identity is restored from the session');
            $this->assertSame('auth-key-1', $res['json']['authKey']);
            $this->assertFalse($res['json']['guest']);

            $res = $this->http('GET', '/compat/session');
            $this->assertSame(2, $res['json']['hits'], 'session payload survived login');

            $res = $this->http('GET', '/compat/logout');
            $this->assertTrue($res['json']['guest'], 'logout clears the identity');

            $res = $this->http('GET', '/compat/whoami');
            $this->assertTrue($res['json']['guest'], 'later requests are guests again');
        });
    }

    /**
     * @testdox Flash messages live exactly one request after being read
     */
    public function testFlashMessages(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/flash?do=set');
            $this->assertNull($res['json']['before']);
            $this->assertSame('saved', $res['json']['stored'], 'setFlash stores the message');

            $res = $this->http('GET', '/compat/flash?do=read');
            $this->assertSame('saved', $res['json']['before'], 'the flash is visible in the next request');
            $this->assertNull($res['json']['stored'], 'reading it removes it');

            $res = $this->http('GET', '/compat/flash?do=read');
            $this->assertNull($res['json']['before'], 'the flash is gone afterwards');
        });
    }

    /**
     * @testdox Multipart uploads through the official UploadedFile API
     */
    public function testUploads(): void
    {
        $this->withServer(function (): void {
            $single = random_bytes(4096);
            $singlePath = tempnam(sys_get_temp_dir(), 'compat-upl');
            file_put_contents($singlePath, $single);

            $multiA = random_bytes(512);
            $multiB = random_bytes(1024);
            $pathA = tempnam(sys_get_temp_dir(), 'compat-upl');
            $pathB = tempnam(sys_get_temp_dir(), 'compat-upl');
            file_put_contents($pathA, $multiA);
            file_put_contents($pathB, $multiB);

            try {
                $res = $this->upload('/open/upload', [
                    ['field' => 'upload', 'path' => $singlePath, 'name' => 'single.bin'],
                    ['field' => 'files[0]', 'path' => $pathA, 'name' => 'a.bin'],
                    ['field' => 'files[1]', 'path' => $pathB, 'name' => 'b.bin'],
                ], ['meta' => 'v1']);

                $this->assertSame(200, $res['status'], (string) $res['body']);
                $this->assertCount(1, $res['json']['upload']);
                $this->assertSame('single.bin', $res['json']['upload'][0]['name']);
                $this->assertSame(sha1($single), $res['json']['upload'][0]['sha1'], 'uploaded bytes are intact');

                $this->assertCount(2, $res['json']['files'], 'array-style file fields normalize to two entries');
                $this->assertSame('a.bin', $res['json']['files'][0]['name']);
                $this->assertSame(sha1($multiA), $res['json']['files'][0]['sha1']);
                $this->assertSame('b.bin', $res['json']['files'][1]['name']);
                $this->assertSame(sha1($multiB), $res['json']['files'][1]['sha1']);
            } finally {
                @unlink($singlePath);
                @unlink($pathA);
                @unlink($pathB);
            }
        });
    }

    /**
     * @testdox CoroutineUploadedFile saves with the default $deleteTempFile flag
     */
    public function testCoroutineUploadedFileSaves(): void
    {
        $this->withServer(function (): void {
            $payload = random_bytes(2048);
            $tmp = tempnam(sys_get_temp_dir(), 'compat-save');
            file_put_contents($tmp, $payload);

            try {
                $res = $this->upload('/open/upload-save', [
                    ['field' => 'upload', 'path' => $tmp, 'name' => 'saved.bin'],
                ]);

                $this->assertSame(200, $res['status'], (string) $res['body']);
                $this->assertTrue($res['json']['saved'], 'saveAs() must not rely on move_uploaded_file()');
                $this->assertSame(sha1($payload), $res['json']['sha1'], 'saved bytes must be identical');
                $this->assertSame('saved.bin', $res['json']['name']);
                $this->assertSame('bin', $res['json']['extension']);
                $this->assertFalse($res['json']['tempStillThere'], 'the temp file is consumed by the move');
            } finally {
                @unlink($tmp);
            }
        });
    }

    /**
     * @testdox Concurrent uploads never read each other's temp file
     */
    public function testConcurrentUploadsAreIsolated(): void
    {
        $this->withServer(function (): void {
            $payloads = [];
            $paths = [];
            for ($i = 0; $i < 4; $i++) {
                $payloads[$i] = random_bytes(1024 + $i);
                $paths[$i] = tempnam(sys_get_temp_dir(), 'compat-conc');
                file_put_contents($paths[$i], $payloads[$i]);
            }

            try {
                $waitGroup = new WaitGroup();
                $results = [];
                for ($i = 0; $i < 4; $i++) {
                    $waitGroup->add();
                    Coroutine::create(function () use ($i, $paths, &$results, $waitGroup): void {
                        try {
                            $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $this->port);
                            $client->addFile($paths[$i], 'upload', 'application/octet-stream', 'f' . $i . '.bin');
                            $client->post('/open/upload-save', []);
                            $results[$i] = json_decode((string) $client->body, true);
                            $client->close();
                        } finally {
                            $waitGroup->done();
                        }
                    });
                }
                $waitGroup->wait();
                ksort($results);

                foreach ($results as $i => $result) {
                    $this->assertTrue($result['saved'] ?? false, "upload {$i} must be saved");
                    $this->assertSame('f' . $i . '.bin', $result['name'], "upload {$i} must keep its own file name");
                    $this->assertSame(sha1($payloads[$i]), $result['sha1'], "upload {$i} must keep its own bytes");
                }
            } finally {
                foreach ($paths as $path) {
                    @unlink($path);
                }
            }
        });
    }

    /**
     * @testdox sendFile() downloads, byte ranges and 416 handling
     */
    public function testFileDownloads(): void
    {
        $this->withServer(function (): void {
            $content = str_repeat('0123456789', 100);

            $res = $this->http('GET', '/compat/download');
            $this->assertSame(200, $res['status']);
            $this->assertSame($content, $res['body'], 'sendFile() must send the whole body');
            $this->assertStringContainsString('text/plain', $this->header($res['headers'], 'content-type'));
            $this->assertStringContainsString('attachment', $this->header($res['headers'], 'content-disposition'));

            $res = $this->http('GET', '/compat/download', ['headers' => ['Range' => 'bytes=10-19']]);
            $this->assertSame(206, $res['status'], 'a Range request must yield 206');
            $this->assertSame(substr($content, 10, 10), $res['body']);
            $this->assertSame('bytes 10-19/1000', $this->header($res['headers'], 'content-range'));

            $res = $this->http('GET', '/compat/download', ['headers' => ['Range' => 'bytes=999999-']]);
            $this->assertSame(416, $res['status'], 'unsatisfiable ranges map to 416');
        });
    }

    /**
     * @testdox Exception to HTTP status mapping (404 / 500 / unknown route)
     */
    public function testErrorHandling(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/error404');
            $this->assertSame(404, $res['status'], 'HttpException carries its status code');
            $this->assertStringContainsString('compat-nope', $res['body']);

            $res = $this->http('GET', '/compat/error500');
            $this->assertSame(500, $res['status'], 'unexpected exceptions map to 500');

            $res = $this->http('GET', '/compat/nope');
            $this->assertSame(404, $res['status'], 'unknown routes 404');
        });
    }

    /**
     * @testdox One-line Bootstrap integration works on the very first request
     */
    public function testOneLineBootstrapOnFirstRequest(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer('app-auto.php');
            try {
                $res = $this->http('GET', '/compat/index');
                $this->assertSame(
                    200,
                    $res['status'],
                    'request/response must already be the coroutine classes on the first request: ' . $res['body']
                );
                $this->assertSame('yii2stest-compat', $res['json']['app'] ?? null);

                // No trustedProxies configured here: forwarding headers must
                // be ignored (a client cannot spoof its own address).
                $res = $this->http('GET', '/compat/request', [
                    'headers' => ['X-Forwarded-For' => '203.0.113.9'],
                ]);
                $this->assertSame('127.0.0.1', $res['json']['userIP'], 'untrusted X-Forwarded-For is ignored');

                // The one-line integration config omits every 'class' key:
                // pin the coroutine class names so the auto-replacement can
                // never silently regress to the stock classes.
                $this->assertSame(\Yii2S\Swoole\Runtime\CoroutineRequest::class, $res['json']['requestClass']);
                $this->assertSame(\Yii2S\Swoole\Runtime\CoroutineResponse::class, $res['json']['responseClass']);
            } finally {
                $this->stopServer();
            }
        });
    }

    /**
     * @testdox Exceptions render through errorHandler.errorAction
     */
    public function testErrorActionRendersCustomErrorPage(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer('app-error.php');
            try {
                $res = $this->http('GET', '/definitely-missing');
                $this->assertSame(404, $res['status']);
                $this->assertStringContainsString('compat-custom-error', $res['body'], 'the configured error action must render');
                $this->assertStringContainsString('Not Found', $res['body']);
            } finally {
                $this->stopServer();
            }
        });
    }

    /**
     * @testdox Database, transactions, cache, Redis, logging and pool fan-out
     */
    public function testResidentServices(): void
    {
        $this->withServer(function (): void {
            $res = $this->http('GET', '/compat/db');
            $this->assertSame(200, $res['status'], (string) $res['body']);
            $this->assertTrue($res['json']['rolledBack'], 'transaction rollback must leave no row behind');
            $this->assertSame($res['json']['before'], $res['json']['after']);
            $this->assertGreaterThan(0, $res['json']['before'], 'the fixture table is reachable');

            $res = $this->http('GET', '/compat/cache');
            $this->assertSame(200, $res['status'], (string) $res['body']);
            $this->assertTrue($res['json']['hit'], 'cache set/get round-trips');

            $res = $this->http('GET', '/compat/redis');
            $this->assertSame(200, $res['status'], (string) $res['body']);
            $this->assertSame('ok', $res['json']['value']);
            $this->assertGreaterThanOrEqual(1, $res['json']['counter'], 'INCR works on the pooled connection');

            $res = $this->http('GET', '/compat/concurrent');
            $this->assertSame(200, $res['status'], (string) $res['body']);
            $this->assertSame([0, 1, 2, 3, 4], $res['json']['values'], 'each sub-coroutine borrows its own connection');

            $res = $this->http('GET', '/compat/log');
            $this->assertSame(200, $res['status']);
            $logFile = sys_get_temp_dir() . '/yii2stest-compat.log';
            $this->waitFor(static fn (): bool => is_file($logFile)
                && str_contains((string) file_get_contents($logFile), 'compat-log-entry'), 3.0);
        });
    }

    /**
     * @testdox Parallel requests keep their own query parameters
     */
    public function testConcurrentRequestsAreIsolated(): void
    {
        $this->withServer(function (): void {
            $waitGroup = new WaitGroup();
            $results = [];
            for ($i = 0; $i < 5; $i++) {
                $waitGroup->add();
                Coroutine::create(function () use ($i, &$results, $waitGroup): void {
                    try {
                        $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $this->port);
                        $client->get('/compat/slow?ms=150&tag=' . $i);
                        $results[$i] = [
                            'status' => (int) $client->statusCode,
                            'json' => json_decode((string) $client->body, true),
                        ];
                        $client->close();
                    } finally {
                        $waitGroup->done();
                    }
                });
            }
            $waitGroup->wait();
            ksort($results);

            $this->assertCount(5, $results);
            foreach ($results as $i => $result) {
                $this->assertSame(200, $result['status']);
                $this->assertSame((string) $i, $result['json']['query'], "request {$i} must see only its own parameters");
            }
        });
    }

    /**
     * @testdox Concurrent requests never share a session or identity
     */
    public function testConcurrentSessionsAndIdentitiesAreIsolated(): void
    {
        $this->withServer(function (): void {
            // Two independent clients, each with its own session.
            $first = $this->http('GET', '/compat/session');
            $jarA = $this->cookies;
            $idA = (string) $first['json']['id'];

            $this->cookies = [];
            $second = $this->http('GET', '/compat/session');
            $jarB = $this->cookies;
            $idB = (string) $second['json']['id'];
            $this->assertNotSame($idA, $idB);

            $cookieA = $this->cookieHeaderFrom($jarA);
            $cookieB = $this->cookieHeaderFrom($jarB);

            $waitGroup = new WaitGroup();
            $results = [];
            $targets = ['a' => $cookieA, 'b' => $cookieB];
            foreach ($targets as $key => $cookie) {
                $waitGroup->add();
                Coroutine::create(function () use ($key, $cookie, &$results, $waitGroup): void {
                    try {
                        $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $this->port);
                        $client->setHeaders(['Cookie' => $cookie]);
                        $client->get('/compat/session?client=' . $key);
                        $results[$key] = json_decode((string) $client->body, true);
                        $client->close();
                    } finally {
                        $waitGroup->done();
                    }
                });
            }
            $waitGroup->wait();

            $this->assertSame($idA, $results['a']['id'], 'client A must keep its own session');
            $this->assertSame($idB, $results['b']['id'], 'client B must keep its own session');
            $this->assertSame(2, $results['a']['hits'], 'each session counts its own requests');
            $this->assertSame(2, $results['b']['hits'], 'each session counts its own requests');

            // Concurrent logins must not leak the identity into the other session.
            $logins = [];
            $waitGroup = new WaitGroup();
            $loginsById = [1 => $cookieA, 2 => $cookieB];
            foreach ($loginsById as $id => $cookie) {
                $waitGroup->add();
                Coroutine::create(function () use ($id, $cookie, &$logins, $waitGroup): void {
                    try {
                        $client = new \Swoole\Coroutine\Http\Client('127.0.0.1', $this->port);
                        $client->setHeaders(['Cookie' => $cookie]);
                        $client->get('/compat/login?id=' . $id);
                        $logins[$id] = json_decode((string) $client->body, true);
                        $client->close();
                    } finally {
                        $waitGroup->done();
                    }
                });
            }
            $waitGroup->wait();

            $this->assertSame(1, $logins[1]['id'] ?? null);
            $this->assertSame(2, $logins[2]['id'] ?? null);
        });
    }

    /**
     * @param callable(): void $test
     */
    private function withServer(callable $test): void
    {
        $this->runInCoroutine(function () use ($test): void {
            $this->startServer();
            try {
                $test();
            } finally {
                $this->stopServer();
            }
        });
    }
}
