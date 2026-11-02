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

        $swooleResponse->status($response->statusCode);
        $this->sendHeaders($swooleResponse, $response->getHeaders());
        $this->sendCookies($swooleResponse, $response->getCookies());

        $content = $response->content;
        if ($content !== null && $content !== '') {
            $swooleResponse->write($content);
        }

        $swooleResponse->end();
    }

    private function sendHeaders(SwooleResponse $swooleResponse, HeaderCollection $headers): void
    {
        foreach ($headers as $name => $values) {
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
