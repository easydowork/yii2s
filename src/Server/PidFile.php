<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

/**
 * Manages the JSON pid file of a running server process.
 *
 * The pid file stores the process id plus the original command line, which
 * allows the `swoole/stop` and `swoole/reload` console commands to control a
 * server that was started from another terminal.
 */
final class PidFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Writes the given data as JSON to the pid file.
     *
     * The file is written with owner-only permissions and through no symlink:
     * the default location is inside the (sticky, shared) system temp
     * directory, where another local user could otherwise pre-create a
     * symlink and have the server overwrite an arbitrary file it can write.
     *
     * @param array<string, mixed> $data
     * @throws \RuntimeException when the path is a symlink
     */
    public function write(array $data): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        clearstatcache(true, $this->path);
        if (is_link($this->path)) {
            throw new \RuntimeException(sprintf(
                'Refusing to write pid file "%s": it is a symlink. Remove it (or configure another pidFile) and retry.',
                $this->path
            ));
        }

        file_put_contents($this->path, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($this->path, 0600);
    }

    /**
     * Reads and decodes the pid file.
     *
     * The stat cache is cleared first: this file is written by *other*
     * processes (e.g. the `swoole/stop` command), so a cached negative
     * is_file() result inside a resident server would otherwise hide the
     * stop flag forever.
     *
     * @return array<string, mixed>|null null when the file does not exist or is invalid
     */
    public function read(): ?array
    {
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->path), true);

        return is_array($data) ? $data : null;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function remove(): void
    {
        if ($this->exists()) {
            @unlink($this->path);
        }
    }

    /**
     * Whether the process recorded in the pid file is still alive.
     */
    public function isAlive(): bool
    {
        $data = $this->read();
        if ($data === null || !isset($data['pid']) || !is_int($data['pid'])) {
            return false;
        }

        return self::isProcessAlive($data['pid']);
    }

    /**
     * Process liveness check (Linux/macOS).
     *
     * posix_kill() is the primary mechanism (available in PHP by default on
     * both); EPERM (1) means the process exists but is not ours. The /proc
     * check is a fallback for Linux installs without the posix extension.
     */
    public static function isProcessAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            if (posix_kill($pid, 0)) {
                return true;
            }
            // EPERM (1) means the process exists but is not ours.
            return posix_get_last_error() === 1;
        }

        return file_exists('/proc/' . $pid);
    }
}
