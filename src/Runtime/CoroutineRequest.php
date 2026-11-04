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
use yii\base\InvalidConfigException;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\NotFoundHttpException;
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

    private ?array $fileParams = null;

    private array $rawCookies = [];

    /**
     * @var array<string> IPs or CIDR blocks of trusted reverse proxies
     * (e.g. `['127.0.0.1', '10.0.0.0/8']`). Only a request coming from one of
     * these peers may set the client IP through `X-Forwarded-For`. Empty by
     * default: forwarding headers are never trusted, matching stock Yii.
     */
    public array $trustedProxies = [];

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
        $this->fileParams = null;
        $this->rawCookies = [];

        // yii\web\UploadedFile reads the $_FILES superglobal directly (see
        // its loadFiles()), so the official upload API only works when it is
        // populated per request. Filling it here keeps it bound to the
        // request lifecycle - RequestStateManager::cleanup() empties it
        // again - and reset() clears UploadedFile's own static cache, which
        // would otherwise leak the previous request's files.
        $_FILES = $this->getFileParams();
        if (class_exists(\yii\web\UploadedFile::class)) {
            \yii\web\UploadedFile::reset();
        }
    }

    public function getSwooleRequest(): ?SwooleRequest
    {
        return $this->swooleRequest;
    }

    public function resolve(): array
    {
        $result = Yii::$app->getUrlManager()->parseRequest($this);
        if ($result === false) {
            // enableStrictParsing: no rule matched. Stock Request throws 404;
            // destructuring `false` here used to produce a 500 instead.
            throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'));
        }

        [$route, $params] = $result;

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

    /**
     * Uploaded files as the PHP-standard $_FILES structure, converted from
     * Swoole's `$request->files`. This feeds `yii\web\UploadedFile`, so
     * multipart uploads work through the regular Yii2 API.
     *
     * Swoole already materialized the payload into temporary files, but the
     * shape depends on the field name and (across builds/paths) on how the
     * parser was invoked:
     *
     * - single-file field (`upload`) -> one entry shaped like $_FILES;
     * - array-style fields (`files[0]`, `files[1]`) with the standard C++
     *   parser -> the attribute-grouped structure PHP produces natively
     *   (`['name' => [...], 'tmp_name' => [...], ...]`);
     * - the same fields on some build paths -> an entry *group* (file
     *   entries under one field key), which is converted to the grouped
     *   shape above.
     *
     * All three normalize to what `UploadedFile::getInstancesByName()`
     * expects; Swoole error codes are mapped to the PHP UPLOAD_ERR_*
     * constants.
     */
    public function getFileParams(): array
    {
        if ($this->fileParams === null) {
            $files = $this->swooleRequest?->files;
            $this->fileParams = is_array($files) ? $this->convertUploadedFiles($files) : [];
        }

        return $this->fileParams;
    }

    /**
     * @param array<string, mixed> $files raw Swoole uploaded-file entries
     * @return array<string, mixed> PHP $_FILES-shaped entries
     */
    private function convertUploadedFiles(array $files): array
    {
        $converted = [];
        foreach ($files as $key => $entry) {
            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }
            if (is_string($entry['name']) && isset($entry['tmp_name'])) {
                // Single-file field - already the PHP shape.
                $converted[(string) $key] = $this->convertFileEntry($entry);
                continue;
            }
            if (is_array($entry['name'])) {
                // Attribute-grouped multi-file shape (PHP's native $_FILES
                // structure) - map the Swoole error codes in place.
                if (isset($entry['error']) && is_array($entry['error'])) {
                    $entry['error'] = array_map(
                        fn (mixed $code): int => $this->mapUploadError((int) $code),
                        $entry['error']
                    );
                }
                $converted[(string) $key] = $entry;
                continue;
            }

            // Entry group: many file entries under one field key. Convert to
            // the attribute-grouped $_FILES structure PHP produces natively.
            $grouped = ['name' => [], 'type' => [], 'tmp_name' => [], 'size' => [], 'error' => []];
            $hasFiles = false;
            foreach ($entry as $index => $item) {
                if (!is_array($item) || !isset($item['name'], $item['tmp_name']) || !is_string($item['name'])) {
                    continue;
                }
                $file = $this->convertFileEntry($item);
                foreach ($grouped as $attribute => $values) {
                    $grouped[$attribute][$index] = $file[$attribute];
                }
                $hasFiles = true;
            }
            if ($hasFiles) {
                $converted[(string) $key] = $grouped;
            }
        }

        return $converted;
    }

    /**
     * @param array<string, mixed> $entry one Swoole file entry
     * @return array{name: string, type: string, tmp_name: string, size: int, error: int}
     */
    private function convertFileEntry(array $entry): array
    {
        return [
            'name' => (string) $entry['name'],
            'type' => (string) ($entry['type'] ?? ''),
            'tmp_name' => (string) $entry['tmp_name'],
            'size' => (int) ($entry['size'] ?? 0),
            'error' => $this->mapUploadError((int) ($entry['error'] ?? 0)),
        ];
    }

    /**
     * Maps Swoole upload error codes to the PHP UPLOAD_ERR_* constants.
     */
    private function mapUploadError(int $swooleError): int
    {
        // Codes that align with PHP semantics pass through; the remaining
        // Swoole-side failures map to the closest PHP semantic.
        return match ($swooleError) {
            \UPLOAD_ERR_OK,
            \UPLOAD_ERR_INI_SIZE,
            \UPLOAD_ERR_FORM_SIZE,
            \UPLOAD_ERR_PARTIAL,
            \UPLOAD_ERR_NO_FILE,
            \UPLOAD_ERR_CANT_WRITE => $swooleError,
            5, 6, 8 => \UPLOAD_ERR_CANT_WRITE,
            default => \UPLOAD_ERR_NO_TMP_DIR,
        };
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

    public function getBaseUrl(): string
    {
        // Explicit empty base: the application sits at the domain root and
        // URLs must not carry a script-name prefix. The parent derives this
        // from dirname(getScriptUrl()), which yields "." for an empty script
        // URL and makes every published asset URL relative - "./assets/..."
        // breaks on any nested page (e.g. /admin/default/login resolves the
        // asset to /admin/default/assets/...). Asset URLs must be rooted.
        return '';
    }

    public function getScriptFile(): string
    {
        // Real path (basic-app convention): feeds Yii's '@webroot' alias so
        // AssetManager::publish targets <app>/web/assets like under FPM.
        return Yii::getAlias('@app') . '/web/index.php';
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

    public function getServerName(): ?string
    {
        $host = $this->getHostName();

        return is_string($host) ? $host : null;
    }

    public function getServerPort(): ?int
    {
        // Honor the forwarding headers first (same as the stock implementation).
        foreach ($this->portHeaders as $portHeader) {
            if ($this->getHeaders()->has($portHeader)) {
                $port = $this->getHeaders()->get($portHeader);
                if ($port !== null) {
                    return (int) $port;
                }
            }
        }

        // Fall back to the actual listening port carried by Swoole (the stock
        // code reads $_SERVER['SERVER_PORT'], which is empty in a coroutine).
        $port = $this->serverParam('server_port');

        return $port !== null ? (int) $port : null;
    }

    public function getIsSecureConnection(): bool
    {
        $https = strtolower((string) $this->serverParam('https'));
        if ($https === 'on' || $https === '1') {
            return true;
        }

        // Honor Yii2's [[secureProtocolHeaders]] configuration (defaults to
        // `X-Forwarded-Proto => ['https']`) instead of hard-coding the header,
        // so deployments not sitting behind a trusted reverse proxy can disable
        // proxy-header trust. Headers are read via the coroutine-safe
        // [[getHeaders()]], never the process-global superglobals.
        foreach ($this->secureProtocolHeaders as $header => $values) {
            $headerValue = $this->getHeaders()->get($header);
            if ($headerValue === null) {
                continue;
            }
            foreach ($values as $value) {
                if (strcasecmp((string) $headerValue, (string) $value) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Client IP. Behind a trusted reverse proxy (see [[trustedProxies]]) the
     * right-most untrusted address of `X-Forwarded-For` wins, so rate
     * limiting, audit logs and `yii\web\User` see the real client.
     */
    public function getUserIP(): ?string
    {
        $remote = $this->serverParam('remote_addr');
        if ($remote === null || $this->trustedProxies === [] || !$this->isTrustedProxy($remote)) {
            return $remote;
        }

        $forwarded = $this->getHeaders()->get('X-Forwarded-For');
        if (!is_string($forwarded) || $forwarded === '') {
            return $remote;
        }

        $chain = array_values(array_filter(
            array_map('trim', explode(',', $forwarded)),
            static fn (string $ip): bool => $ip !== ''
        ));

        // Walk from the closest proxy backwards and return the first address
        // that is not itself a trusted proxy - that is the real client.
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!$this->isTrustedProxy($chain[$i])) {
                return $chain[$i];
            }
        }

        return $chain[0] ?? $remote;
    }

    private function isTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedProxies as $trusted) {
            if (str_contains($trusted, '/')) {
                if (self::ipInCidr($ip, $trusted)) {
                    return true;
                }
            } elseif (strcasecmp($trusted, $ip) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $subnet = $parts[0];
        $bits = $parts[1] ?? null;
        if ($bits === null || $bits === '' || !ctype_digit($bits)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }

    public function getUserHost(): ?string
    {
        return $this->serverParam('remote_host');
    }

    public function getCookies(): CookieCollection
    {
        if ($this->cookieCollection === null) {
            // ->cookie is null when the request carries no cookies.
            $rawCookies = $this->swooleRequest === null ? null : $this->swooleRequest->cookie;
            $this->rawCookies = is_array($rawCookies) ? $rawCookies : [];
            $this->cookieCollection = new CookieCollection($this->loadCookies());
        }

        return $this->cookieCollection;
    }

    /**
     * Official `yii\web\Request::loadCookies()` semantics applied to the
     * Swoole cookie table: signed cookies are hash-validated through
     * [[cookieValidationKey]] and unwrapped, invalid ones are dropped. Never
     * trust raw cookie values - the auto-login identity cookie flows through
     * here, and skipping validation would let anyone impersonate a user.
     *
     * @return array<string, Cookie>
     */
    protected function loadCookies(): array
    {
        $rawCookies = $this->rawCookies;
        $cookies = [];
        if ($this->enableCookieValidation) {
            if ($this->cookieValidationKey === '') {
                throw new InvalidConfigException(get_class($this) . '::cookieValidationKey must be configured with a secret key.');
            }

            foreach ($rawCookies as $name => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $data = Yii::$app->security->validateData($value, $this->cookieValidationKey);
                if ($data === false) {
                    continue; // tampered or foreign cookie - drop silently
                }
                // Match stock yii\web\Request: the HMAC only proves the payload
                // was produced by this application, so object instantiation is
                // disabled on top (a leaked/weak cookieValidationKey must not
                // turn cookie parsing into PHP object injection).
                $data = @unserialize($data, ['allowed_classes' => false]);
                if (is_array($data) && count($data) === 2 && $data[0] === (string) $name) {
                    $cookies[(string) $name] = new Cookie([
                        'name' => (string) $name,
                        'value' => (string) $data[1],
                    ]);
                }
            }

            return $cookies;
        }

        foreach ($rawCookies as $name => $value) {
            $cookies[(string) $name] = new Cookie([
                'name' => (string) $name,
                'value' => (string) $value,
            ]);
        }

        return $cookies;
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
