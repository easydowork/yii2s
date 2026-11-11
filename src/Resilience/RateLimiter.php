<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Resilience;

use Yii;
use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use yii\base\ActionFilter;
use yii\base\InvalidConfigException;
use yii\web\TooManyRequestsHttpException;

/**
 * IP rate limit as a controller filter.
 *
 * Attach it in `behaviors()`. Each request increments a Redis fixed window
 * keyed by the client IP. Guests are limited the same way as logged-in users.
 * Over the limit the filter throws [[TooManyRequestsHttpException]] (HTTP 429).
 *
 * [[allow()]] is the same window, for a key you choose yourself.
 */
class RateLimiter extends ActionFilter
{
    /**
     * Redis connection, or the id of an application component that is one.
     * Resolved on the first check so attaching the filter does not open a
     * connection.
     */
    public CoroutineRedisConnection|string $redis = 'redis';

    public string $prefix = 'yii2s:ratelimit:';

    public int $limit = 60;

    public int $window = 60;

    /**
     * Split the window per action. Off by default: one IP shares one window
     * across the actions this filter applies to (`only` / `except`).
     */
    public bool $perAction = false;

    public string $errorMessage = 'Rate limit exceeded.';

    public bool $enableRateLimitHeaders = true;

    public function beforeAction($action)
    {
        $request = Yii::$app->getRequest();
        $ip = (string) ($request->getUserIP() ?: 'unknown');
        $key = $this->perAction ? $ip . ':' . $action->getUniqueId() : $ip;

        if (!$this->allow($key, $this->limit, $this->window)) {
            $this->addHeaders(0);
            throw new TooManyRequestsHttpException($this->errorMessage);
        }

        $this->addHeaders($this->remaining($key, $this->limit));

        return true;
    }

    /**
     * Whether `$key` is still inside the window.
     *
     * @param string $key bucket, for example an IP or a user id
     * @param int $limit maximum requests per window
     * @param int $windowSeconds window length in seconds
     */
    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        if ($limit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Rate limit and window seconds must be at least 1.');
        }

        $redisKey = $this->prefix . $key;
        // Atomic fixed window via Lua: INCR and EXPIRE must not be separate
        // round-trips - if the worker dies between them, the key is left
        // without a TTL and the caller is rate-limited forever.
        $count = (int) $this->connection()->executeCommand('EVAL', [
            "local c = redis.call('INCR', KEYS[1])\n" .
            "if c == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end\n" .
            "return c",
            [$redisKey, (string) $windowSeconds],
            1,
        ]);

        return $count <= $limit;
    }

    /**
     * Remaining requests in the current window (0 when exhausted).
     */
    public function remaining(string $key, int $limit): int
    {
        $count = (int) $this->connection()->executeCommand('GET', [$this->prefix . $key]);

        return max(0, $limit - $count);
    }

    private function addHeaders(int $remaining): void
    {
        if (!$this->enableRateLimitHeaders) {
            return;
        }

        Yii::$app->getResponse()->getHeaders()
            ->set('X-Rate-Limit-Limit', (string) $this->limit)
            ->set('X-Rate-Limit-Remaining', (string) $remaining)
            ->set('X-Rate-Limit-Reset', (string) $this->window);
    }

    private function connection(): CoroutineRedisConnection
    {
        if ($this->redis instanceof CoroutineRedisConnection) {
            return $this->redis;
        }

        $component = Yii::$app->get($this->redis);
        if (!$component instanceof CoroutineRedisConnection) {
            throw new InvalidConfigException('RateLimiter::$redis must be a CoroutineRedisConnection.');
        }

        return $this->redis = $component;
    }
}
