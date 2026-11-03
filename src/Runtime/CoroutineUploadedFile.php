<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

use Yii;
use yii\helpers\Html;
use yii\web\UploadedFile;

/**
 * Coroutine-safe replacement for `yii\web\UploadedFile`.
 *
 * The stock class reads the process-global `$_FILES` array and caches the
 * parsed result in a static property. Under a resident coroutine server that
 * is unsafe: while one request is between "bind the request" and "read the
 * upload", a concurrent request replaces `$_FILES` (and clears the static
 * cache), so a request can end up reading another request's file.
 *
 * This class never touches `$_FILES`. It reads the already-normalized file
 * table from [[CoroutineRequest::getFileParams()]], which is bound to the
 * current coroutine, and keeps no static cache at all.
 *
 * Migration is a single import swap in the code that handles uploads:
 *
 * ```php
 * use Yii2S\Swoole\Runtime\CoroutineUploadedFile as UploadedFile;
 *
 * $file = UploadedFile::getInstance($model, 'avatar');
 * if ($file !== null && $file->saveAs('@app/uploads/' . $file->name)) {
 *     // ...
 * }
 * ```
 *
 * It also fixes `saveAs()` for Swoole: the temp file is written by Swoole,
 * not by PHP's own multipart handler, so `move_uploaded_file()` (used by the
 * stock implementation) returns false. Here the file is renamed/copied.
 */
final class CoroutineUploadedFile extends UploadedFile
{
    /**
     * {@inheritdoc}
     */
    public static function getInstance($model, $attribute): ?static
    {
        return static::getInstanceByName(Html::getInputName($model, $attribute));
    }

    /**
     * {@inheritdoc}
     */
    public static function getInstances($model, $attribute): array
    {
        return static::getInstancesByName(Html::getInputName($model, $attribute));
    }

    /**
     * {@inheritdoc}
     */
    public static function getInstanceByName($name): ?static
    {
        if (!self::hasCoroutineRequest()) {
            /** @var static|null $file */
            $file = parent::getInstanceByName($name);

            return $file;
        }

        $files = self::normalize(self::requestFiles());

        return isset($files[$name]) ? new static($files[$name]) : null;
    }

    /**
     * {@inheritdoc}
     */
    public static function getInstancesByName($name): array
    {
        if (!self::hasCoroutineRequest()) {
            return parent::getInstancesByName($name);
        }

        $files = self::normalize(self::requestFiles());
        if (isset($files[$name])) {
            return [new static($files[$name])];
        }

        $results = [];
        foreach ($files as $key => $file) {
            if (strpos((string) $key, "{$name}[") === 0) {
                $results[] = new static($file);
            }
        }

        return $results;
    }

    /**
     * Nothing to reset: the file table is always read from the current
     * request, there is no static cache to invalidate.
     */
    public static function reset(): void
    {
    }

    /**
     * Saves the uploaded file.
     *
     * Swoole writes uploaded payloads to temp files itself, so
     * `move_uploaded_file()` - which only accepts files tracked by PHP's own
     * multipart parser - always fails here. Renaming (same filesystem) or
     * copying plus unlinking is the equivalent operation.
     *
     * {@inheritdoc}
     */
    public function saveAs($file, $deleteTempFile = true): bool
    {
        if ($this->getHasError()) {
            return false;
        }
        if ((string) $this->tempName === '') {
            return false;
        }

        $targetFile = Yii::getAlias($file);

        if (!$deleteTempFile) {
            return @copy($this->tempName, $targetFile);
        }

        if (@rename($this->tempName, $targetFile)) {
            return true;
        }
        // Different filesystems (common when the temp dir is tmpfs).
        if (@copy($this->tempName, $targetFile)) {
            @unlink($this->tempName);

            return true;
        }

        return false;
    }

    private static function hasCoroutineRequest(): bool
    {
        $app = Yii::$app;

        return $app instanceof \yii\web\Application
            && $app->getRequest() instanceof CoroutineRequest;
    }

    /**
     * @return array<string, mixed> PHP $_FILES-shaped entries of this request
     */
    private static function requestFiles(): array
    {
        $request = Yii::$app->getRequest();

        return $request instanceof CoroutineRequest ? $request->getFileParams() : [];
    }

    /**
     * Reformats PHP's $_FILES structure into `field[index] => file` entries,
     * mirroring `yii\web\UploadedFile::loadFiles()` without the static cache.
     *
     * @param array<string, mixed> $files
     * @return array<string, array<string, mixed>>
     */
    private static function normalize(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $info) {
            if (!is_array($info) || !isset($info['name'])) {
                continue;
            }
            self::normalizeRecursive(
                (string) $key,
                $info['name'],
                $info['tmp_name'] ?? '',
                $info['type'] ?? '',
                $info['size'] ?? 0,
                $info['error'] ?? UPLOAD_ERR_OK,
                $info['full_path'] ?? null,
                $info['tmp_resource'] ?? null,
                $normalized
            );
        }

        return $normalized;
    }

    /**
     * @param mixed $names
     * @param mixed $tempNames
     * @param mixed $types
     * @param mixed $sizes
     * @param mixed $errors
     * @param mixed $fullPaths
     * @param mixed $tempResources
     * @param array<string, array<string, mixed>> $normalized
     */
    private static function normalizeRecursive(
        string $key,
        $names,
        $tempNames,
        $types,
        $sizes,
        $errors,
        $fullPaths,
        $tempResources,
        array &$normalized
    ): void {
        if (is_array($names)) {
            foreach ($names as $index => $name) {
                self::normalizeRecursive(
                    $key . '[' . $index . ']',
                    $name,
                    is_array($tempNames) ? ($tempNames[$index] ?? '') : '',
                    is_array($types) ? ($types[$index] ?? '') : '',
                    is_array($sizes) ? ($sizes[$index] ?? 0) : 0,
                    is_array($errors) ? ($errors[$index] ?? UPLOAD_ERR_OK) : UPLOAD_ERR_OK,
                    is_array($fullPaths) ? ($fullPaths[$index] ?? null) : null,
                    is_array($tempResources) ? ($tempResources[$index] ?? null) : null,
                    $normalized
                );
            }

            return;
        }

        if ((int) $errors === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $normalized[$key] = [
            'name' => $names,
            'tempName' => $tempNames,
            'tempResource' => is_resource($tempResources) ? $tempResources : null,
            'type' => $types,
            'size' => $sizes,
            'error' => $errors,
            'fullPath' => is_string($fullPaths) ? $fullPaths : null,
        ];
    }
}
