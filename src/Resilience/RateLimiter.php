<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Resilience;

use Yii2S\Swoole\Redis\CoroutineRedisConnection;

/**
 * Fixed-window rate limiter backed by Redis (`INCR` + `EXPIRE`).
 *
 * One atomic increment per request; the window is established on the first
 * hit. Suitable for guarding login endpoints, public APIs and other spots that
 * must bound the request rate per key (IP / user / route).
 */
class RateLimiter
{
    public function __construct(
        private CoroutineRedisConnection $redis,
        private string $prefix = 'yii2s:ratelimit:',
    ) {
    }

    /**
     * Whether the request identified by `$key` is allowed within the window.
     *
     * @param string $key the rate-limit bucket (e.g. an IP or user id)
     * @param int $limit maximum requests per window
     * @param int $windowSeconds window length in seconds
     */
    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $redisKey = $this->prefix . $key;
        $count = (int) $this->redis->executeCommand('INCR', [$redisKey]);

        if ($count === 1) {
            $this->redis->executeCommand('EXPIRE', [$redisKey, $windowSeconds]);
        }

        return $count <= $limit;
    }

    /**
     * Remaining requests available in the current window (0 when exhausted).
     */
    public function remaining(string $key, int $limit): int
    {
        $count = (int) $this->redis->executeCommand('GET', [$this->prefix . $key]);

        return max(0, $limit - $count);
    }
}
