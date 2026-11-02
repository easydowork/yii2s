<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use Swoole\Http\Request as SwooleRequest;
use Yii;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\Request;

/**
 * Yii2 web request backed by a Swoole HTTP request.
 *
 * All request data is read from the coroutine-local Swoole request instead of
 * PHP superglobals ($_GET, $_POST, $_SERVER, $_COOKIE), which are shared by
 * every coroutine in the process and therefore unsafe in a resident server.
 *
 * Note: `$_GET`-based routing (`urlManager` without pretty URLs) is not
 * supported; enable pretty URLs so routes are parsed from `pathInfo`.
 */
class CoroutineRequest extends Request
{
    private ?SwooleRequest $swooleRequest = null;

    private ?string $resolvedPathInfo = null;

    private ?string $resolvedUrl = null;

    private ?string $resolvedHostInfo = null;

    private ?HeaderCollection $headerCollection = null;

    private ?CookieCollection $cookieCollection = null;

    private ?array $queryParams = null;

    private ?array $bodyParams = null;

    /**
     * Binds the request to a Swoole HTTP request for the current coroutine.
     */
    public function setSwooleRequest(SwooleRequest $request): void
    {
        $this->swooleRequest = $request;
        $this->resolvedPathInfo = null;
        $this->resolvedUrl = null;
        $this->resolvedHostInfo = null;
        $this->headerCollection = null;
        $this->cookieCollection = null;
        $this->queryParams = null;
        $this->bodyParams = null;
    }

    public function getSwooleRequest(): ?SwooleRequest
    {
        return $this->swooleRequest;
    }

    public function resolve(): array
    {
        [$route, $params] = Yii::$app->getUrlManager()->parseRequest($this);

        return [$route, $params + $this->getQueryParams()];
    }

    public function getMethod(): string
    {
        return strtoupper($this->serverParam('request_method') ?? 'GET');
    }

    public function getHeaders(): HeaderCollection
    {
        if ($this->headerCollection === null) {
            $collection = new HeaderCollection();
            $headers = $this->swooleRequest === null ? [] : $this->swooleRequest->header;
            foreach ($headers as $name => $value) {
                $collection->set(strtolower((string) $name), $value);
            }
            $this->headerCollection = $collection;
        }

        return $this->headerCollection;
    }

    public function getQueryParams(): array
    {
        if ($this->queryParams === null) {
            // Swoole sets ->get to null when the request has no query string,
            // although the ide-helper stubs claim it is always an array.
            $get = $this->swooleRequest === null ? null : $this->swooleRequest->get;
            $this->queryParams = is_array($get) ? $get : [];
        }

        return $this->queryParams;
    }

    public function setQueryParams($values): void
    {
        // Parent signature is untyped; cast defensively.
        $this->queryParams = (array) $values;
    }

    public function getBodyParams(): array
    {
        if ($this->bodyParams === null) {
            $this->bodyParams = $this->parseBodyParams();
        }

        return $this->bodyParams;
    }

    public function setBodyParams($values): void
    {
        $this->bodyParams = is_array($values) ? $values : [];
    }

    public function getRawBody(): string
    {
        $raw = $this->swooleRequest?->rawContent();

        return is_string($raw) ? $raw : '';
    }

    public function getContentType(): ?string
    {
        $contentType = $this->getHeaders()->get('Content-Type');

        return $contentType !== null ? strtolower($contentType) : null;
    }

    public function getPathInfo(): string
    {
        if ($this->resolvedPathInfo === null) {
            $path = parse_url($this->getRequestUri(), PHP_URL_PATH) ?: '/';
            $this->resolvedPathInfo = ltrim((string) $path, '/');
        }

        return $this->resolvedPathInfo;
    }

    public function getScriptUrl(): string
    {
        return '';
    }

    public function getScriptFile(): string
    {
        return '';
    }

    public function getUrl(): string
    {
        if ($this->resolvedUrl === null) {
            $uri = $this->getRequestUri();
            $queryString = $this->serverParam('query_string');
            $this->resolvedUrl = $queryString !== null && $queryString !== ''
                ? $uri . '?' . $queryString
                : $uri;
        }

        return $this->resolvedUrl;
    }

    public function getQueryString(): string
    {
        return $this->serverParam('query_string') ?? '';
    }

    public function getHostInfo(): ?string
    {
        if ($this->resolvedHostInfo === null) {
            $secure = $this->getIsSecureConnection();
            $http = $secure ? 'https' : 'http';
            $host = $this->getHeaders()->get('Host');
            if ($host !== null) {
                $this->resolvedHostInfo = $http . '://' . $host;
            }
        }

        return $this->resolvedHostInfo;
    }

    public function setHostInfo($value): void
    {
        $this->resolvedHostInfo = $value;
    }

    public function getIsSecureConnection(): bool
    {
        $https = strtolower((string) $this->serverParam('https'));
        if ($https === 'on' || $https === '1') {
            return true;
        }

        return strtolower((string) $this->getHeaders()->get('X-Forwarded-Proto')) === 'https';
    }

    public function getUserIP(): ?string
    {
        return $this->serverParam('remote_addr');
    }

    public function getUserHost(): ?string
    {
        return $this->serverParam('remote_host');
    }

    public function getCookies(): CookieCollection
    {
        if ($this->cookieCollection === null) {
            $cookies = [];
            // ->cookie is null when the request carries no cookies.
            $rawCookies = $this->swooleRequest === null ? null : $this->swooleRequest->cookie;
            if (is_array($rawCookies)) {
                foreach ($rawCookies as $name => $value) {
                    $cookies[(string) $name] = new Cookie([
                        'name' => (string) $name,
                        'value' => (string) $value,
                    ]);
                }
            }
            $this->cookieCollection = new CookieCollection($cookies);
        }

        return $this->cookieCollection;
    }

    private function getRequestUri(): string
    {
        return $this->serverParam('request_uri') ?? '/';
    }

    private function serverParam(string $name): ?string
    {
        $value = $this->swooleRequest?->server[$name] ?? null;

        return $value !== null ? (string) $value : null;
    }

    private function parseBodyParams(): array
    {
        $contentType = $this->getContentType();
        if ($contentType === null) {
            return [];
        }

        if (str_contains($contentType, 'application/json')) {
            $body = $this->getRawBody();
            if ($body === '') {
                return [];
            }
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')
            || str_contains($contentType, 'multipart/form-data')) {
            return is_array($this->swooleRequest?->post) ? $this->swooleRequest->post : [];
        }

        return [];
    }
}
