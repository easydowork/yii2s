<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\server;

use Swoole\Coroutine\Http\Client;
use yii2stest\ServerTestCase;

/**
 * @group server
 * @group integration
 */
class HttpServerFormTest extends ServerTestCase
{
    public function testFormUrlencodedJsonAndMultipartUpload(): void
    {
        $this->runInCoroutine(function (): void {
            $this->startServer(__DIR__ . '/../data/app-form.php');
            try {
                // 1. urlencoded form fields.
                $client = new Client('127.0.0.1', $this->port);
                $this->assertTrue($client->post('/site/echo', ['field' => 'v1', 'num' => '42']));
                $this->assertSame(200, $client->statusCode);
                $body = json_decode((string) $client->body, true);
                $this->assertSame('v1', $body['post']['field'] ?? null);
                $this->assertSame('42', $body['post']['num'] ?? null);
                $client->close();

                // 2. JSON body.
                $client = new Client('127.0.0.1', $this->port);
                $client->setHeaders(['Content-Type' => 'application/json']);
                $this->assertTrue($client->post('/site/echo', json_encode(['j' => ['k' => 'v']], JSON_THROW_ON_ERROR)));
                $this->assertSame(200, $client->statusCode);
                $body = json_decode((string) $client->body, true);
                $this->assertSame('v', $body['post']['j']['k'] ?? null, 'JSON bodies must parse into bodyParams');
                $client->close();

                // 3. multipart upload: fields + a binary file, verified by sha1.
                $payload = random_bytes(64 * 1024);
                $tmpFile = tempnam(sys_get_temp_dir(), 'upl');
                file_put_contents($tmpFile, $payload);

                $client = new Client('127.0.0.1', $this->port);
                $client->addFile($tmpFile, 'upload', 'application/octet-stream', 'upload-test-file.bin');
                $this->assertTrue($client->post('/site/upload', ['meta' => 'upload-test']));
                $this->assertSame(200, $client->statusCode, (string) $client->body);
                $body = json_decode((string) $client->body, true);
                $this->assertSame('upload-test', $body['post']['meta'] ?? null, 'multipart fields must reach bodyParams');
                $this->assertCount(1, $body['upload'] ?? [], 'single upload must appear via UploadedFile');
                $this->assertSame('upload-test-file.bin', $body['upload'][0]['name'] ?? null);
                $this->assertSame(64 * 1024, $body['upload'][0]['size'] ?? null);
                $this->assertSame(0, $body['upload'][0]['error'] ?? -1, 'upload error must map to UPLOAD_ERR_OK');
                $this->assertSame(sha1($payload), $body['upload'][0]['contentSha1'] ?? null, 'uploaded content must be byte-identical');
                $client->close();
                unlink($tmpFile);

                // 4. Multiple files with array-style names (files[0]/files[1]):
                // the shape real multipart forms produce.
                $payloadA = random_bytes(1024);
                $payloadB = random_bytes(2048);
                $tmpA = tempnam(sys_get_temp_dir(), 'upl');
                $tmpB = tempnam(sys_get_temp_dir(), 'upl');
                file_put_contents($tmpA, $payloadA);
                file_put_contents($tmpB, $payloadB);

                $client = new Client('127.0.0.1', $this->port);
                $client->addFile($tmpA, 'files[0]', 'application/octet-stream', 'a.bin');
                $client->addFile($tmpB, 'files[1]', 'application/octet-stream', 'b.bin');
                $this->assertTrue($client->post('/site/upload', ['kind' => 'multi']));
                $this->assertSame(200, $client->statusCode, (string) $client->body);
                $body = json_decode((string) $client->body, true);
                $this->assertCount(2, $body['files'] ?? [], 'both array-style files must survive: ' . json_encode($body['files'] ?? null));
                $this->assertSame('a.bin', $body['files'][0]['name'] ?? null);
                $this->assertSame(sha1($payloadA), $body['files'][0]['contentSha1'] ?? null, 'first file content must be byte-identical');
                $this->assertSame('b.bin', $body['files'][1]['name'] ?? null);
                $this->assertSame(sha1($payloadB), $body['files'][1]['contentSha1'] ?? null, 'second file content must be byte-identical');
                $client->close();
                unlink($tmpA);
                unlink($tmpB);
            } finally {
                $this->stopServer();
            }
        });
    }
}
