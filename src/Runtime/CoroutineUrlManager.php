<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use yii\web\UrlManager;

/**
 * A [[UrlManager]] that caches route resolution per process.
 *
 * Route matching walks every rule on every request, which is a real cost once
 * an app grows a large rule table (the resident process pays it forever). The
 * result of [[parseRequest()]] depends on the request method, host and path
 * and the (static) rules, so it can be memoized safely. Host is part of the
 * key because a rule may match `http://admin.example.com/...` and a different
 * rule the same path on another host.
 *
 * The cache is a bounded per-process array, not Redis: it holds plain
 * `[route, params]` results, is never evicted across requests within a worker,
 * and caps out at [[maxParseCache]] entries to bound memory.
 */
class CoroutineUrlManager extends UrlManager
{
    /**
     * @var int maximum number of cached route resolutions (0 disables the cache)
     */
    public int $maxParseCache = 1000;

    /**
     * @var array<string, array{0: string, 1: array}|false>
     */
    private array $parseCache = [];

    public function parseRequest($request): array|bool
    {
        if ($this->maxParseCache <= 0) {
            return parent::parseRequest($request);
        }

        $key = $request->getMethod() . ' ' . (string) $request->getHostInfo() . ' ' . $request->getPathInfo();
        if (array_key_exists($key, $this->parseCache)) {
            return $this->parseCache[$key];
        }

        $result = parent::parseRequest($request);

        if (count($this->parseCache) < $this->maxParseCache) {
            $this->parseCache[$key] = $result;
        }

        return $result;
    }

    /**
     * @return int number of entries currently cached
     */
    public function getParseCacheSize(): int
    {
        return count($this->parseCache);
    }
}
