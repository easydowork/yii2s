<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Serves static files from a document root, used as a PHP-level fallback
 * because the coroutine Http\Server ignores Swoole's native
 * `enable_static_handler`.
 *
 * Security: only regular files below the document root are served, path
 * traversal is blocked via realpath prefix comparison and symlinks are
 * resolved before the check.
 */
class StaticFileServer
{
    private const DEFAULT_MIME_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'map' => 'application/json',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
    ];

    /**
     * Extensions that must never be served as raw files: server-executable
     * scripts and sensitive files that could leak source code or secrets if a
     * document root accidentally contains them.
     */
    private const BLOCKED_EXTENSIONS = [
        'php' => true,
        'php3' => true,
        'php4' => true,
        'php5' => true,
        'php7' => true,
        'php8' => true,
        'phtml' => true,
        'phar' => true,
        'phps' => true,
        'pht' => true,
        'inc' => true,
        'cgi' => true,
        'pl' => true,
        'py' => true,
        'rb' => true,
        'sh' => true,
        'bat' => true,
        'asp' => true,
        'aspx' => true,
        'jsp' => true,
        'htaccess' => true,
        'env' => true,
        'sql' => true,
        'ini' => true,
        'log' => true,
    ];

    /** @var int how long browsers may cache static assets (seconds) */
    public int $maxAge = 86400;

    public function __construct(private readonly string $documentRoot)
    {
    }

    /**
     * Tries to serve the requested path as a static file.
     *
     * @return bool true when the request has been served; false when the
     *              request should fall through to the application
     */
    public function serve(Request $swooleRequest, Response $swooleResponse, ?string $path = null): bool
    {
        if ($path === null) {
            $uri = (string) ($swooleRequest->server['request_uri'] ?? '');
            if (in_array($uri, ['', '/'], true)) {
                return false;
            }

            // Strip the query string; decoding happens after validation.
            $parsed = parse_url($uri, PHP_URL_PATH);
            if (!is_string($parsed)) {
                return false;
            }
            $path = $parsed;
        }

        if ($path === '' || $path === '/') {
            return false;
        }

        // Decode URL escapes. Decoding must happen *before* validation: an
        // encoded null byte (`%00`) or encoded separators (`%2e%2e%2f`) would
        // otherwise pass the checks below and reach realpath(), which throws
        // a ValueError on null bytes - an uncaught exception here kills the
        // request coroutine (DoS).
        $path = urldecode($path);

        // Reject null bytes (path truncation) and backslashes (Windows-style
        // separators that pathinfo()/realpath() treat inconsistently).
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        // Only serve paths that look like asset files (with an extension).
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return false;
        }

        // Reject any `..` path segment outright (defense in depth; the
        // realpath prefix check below is the authoritative guard).
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return false;
        }

        // Never serve hidden files (dotfiles) or executable/sensitive files.
        if (str_starts_with(pathinfo($path, PATHINFO_BASENAME), '.')) {
            return false;
        }
        if (isset(self::BLOCKED_EXTENSIONS[$extension])) {
            return false;
        }

        $root = realpath($this->documentRoot);
        $file = realpath($this->documentRoot . $path);
        if ($root === false || $file === false || !is_file($file)) {
            return false;
        }
        if ($file !== $root && !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return false; // path traversal attempt
        }

        if (!$swooleResponse->isWritable()) {
            return false;
        }

        $swooleResponse->status(200);
        $swooleResponse->header('Content-Type', self::DEFAULT_MIME_TYPES[$extension] ?? 'application/octet-stream');
        $swooleResponse->header('Cache-Control', 'public, max-age=' . $this->maxAge);
        $swooleResponse->sendfile($file);

        return true;
    }
}
