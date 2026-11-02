<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use Swoole\Http\Response as SwooleResponse;
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
            $expire = $cookie->expire;
            if ($expire instanceof \DateInterval) {
                // yii\web\Cookie allows \DateInterval values; convert to timestamp.
                $expire = (new \DateTimeImmutable())->add($expire)->getTimestamp();
            }

            $swooleResponse->cookie(
                $cookie->name,
                (string) $cookie->value,
                $expire,
                $cookie->path,
                $cookie->domain,
                $cookie->secure,
                $cookie->httpOnly
            );
        }
    }
}
