<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use Swoole\Http\Response as SwooleResponse;
use Yii;
use yii\web\HeaderCollection;
use yii\web\Response;

/**
 * Sends a finalized Yii2 response through the Swoole HTTP response object.
 */
class SwooleResponseSender
{
    public function send(SwooleResponse $swooleResponse, Response $response): void
    {
        if (!$swooleResponse->isWritable()) {
            return;
        }

        // sendFile()/sendStreamAsFile() put the payload in `stream`
        // ([handle, begin, end]) and leave `content` empty; without this the
        // response would be a 200 with a Content-Length and no body.
        $stream = $response->stream;
        $streaming = is_array($stream) && isset($stream[0], $stream[1], $stream[2]) && is_resource($stream[0]);

        $swooleResponse->status($response->statusCode);
        // Streamed bodies are written with chunked transfer encoding, which
        // makes Swoole drop any Content-Length and log a warning about it.
        $this->sendHeaders($swooleResponse, $response->getHeaders(), $streaming);
        $this->sendCookies($swooleResponse, $response->getCookies());

        if ($streaming) {
            $this->sendStream($swooleResponse, $stream);
            $swooleResponse->end();

            return;
        }

        // Send the whole body in one call so Swoole frames it with
        // Content-Length instead of chunked encoding. `write()` would force
        // Transfer-Encoding: chunked, which HTTP/1.0 keep-alive clients (and
        // some proxies) cannot parse.
        $content = $response->content;
        $swooleResponse->end($content === null ? '' : (string) $content);
    }

    private function sendHeaders(SwooleResponse $swooleResponse, HeaderCollection $headers, bool $skipContentLength = false): void
    {
        foreach ($headers as $name => $values) {
            if ($skipContentLength && strcasecmp((string) $name, 'Content-Length') === 0) {
                continue;
            }
            foreach ((array) $values as $value) {
                $swooleResponse->header($name, (string) $value);
            }
        }
    }

    private function sendCookies(SwooleResponse $swooleResponse, \yii\web\CookieCollection $cookies): void
    {
        foreach ($cookies as $cookie) {
            $expire = $this->normalizeExpire($cookie->expire);

            $value = (string) $cookie->value;
            // Official `Response::sendCookies()` semantics: signed cookies get
            // hashed (name+value through the request's validation key). URL
            // encoding is Swoole's cookie() job - it encodes by itself, and a
            // manual encoding here would double-encode the signature.
            $request = Yii::$app->getRequest();
            if ($request->enableCookieValidation && $cookie->value !== null) {
                $value = Yii::$app->security->hashData(
                    serialize([$cookie->name, $value]),
                    (string) $request->cookieValidationKey
                );
            }

            $swooleResponse->cookie(
                $cookie->name,
                $value,
                $expire,
                (string) $cookie->path,
                (string) $cookie->domain,
                (bool) $cookie->secure,
                (bool) $cookie->httpOnly,
                (string) $cookie->sameSite
            );
        }
    }

    /**
     * Streams the byte range prepared by `Response::sendStreamAsFile()`.
     *
     * @param array{0: resource, 1: int, 2: int} $stream [handle, begin, end]
     */
    private function sendStream(SwooleResponse $swooleResponse, array $stream): void
    {
        [$handle, $begin, $end] = $stream;

        fseek($handle, (int) $begin);
        $chunkSize = 1024 * 1024;

        while (!feof($handle)) {
            $position = (int) ftell($handle);
            if ($position > $end) {
                break;
            }
            if ($position + $chunkSize > $end) {
                $chunkSize = $end - $position + 1;
            }

            $chunk = fread($handle, $chunkSize);
            if ($chunk === false || $chunk === '' || !$swooleResponse->write($chunk)) {
                break;
            }
        }

        fclose($handle);
    }

    /**
     * Converts the various [[\yii\web\Cookie::$expire]] representations into a
     * Unix timestamp understood by the Swoole response.
     */
    private function normalizeExpire(mixed $expire): int
    {
        if ($expire instanceof \DateTimeInterface) {
            return $expire->getTimestamp();
        }
        if ($expire instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($expire)->getTimestamp();
        }
        if (is_string($expire) && !is_numeric($expire)) {
            return (int) strtotime($expire);
        }

        return (int) $expire;
    }
}
