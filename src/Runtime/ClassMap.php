<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Runtime;

/**
 * Replacements for Yii classes that keep process-wide state.
 *
 * `Yii.php` loads `classes.php` into `Yii::$classMap`. [[Yii::autoload()]]
 * reads that map first, so each stock class is swapped by overwriting its
 * entry after `Yii.php` has loaded and before the class is used:
 *
 * ```php
 * Yii::$classMap['yii\base\Widget'] = __DIR__ . '/compat/Widget.php';
 * ```
 *
 * Add another row to [[files()]] when the next class needs the same treatment.
 */
final class ClassMap
{
    public static function install(): void
    {
        if (!class_exists(\Yii::class, false)) {
            throw new \RuntimeException('ClassMap::install() must run after Yii.php is loaded.');
        }

        foreach (self::files() as $class => $file) {
            if (class_exists($class, false)) {
                $loaded = (new \ReflectionClass($class))->getFileName();
                if (realpath((string) $loaded) !== realpath($file)) {
                    throw new \RuntimeException($class . ' was already loaded from ' . $loaded);
                }
                continue;
            }

            \Yii::$classMap[$class] = $file;
        }
    }

    /**
     * @return array<class-string, string>
     */
    private static function files(): array
    {
        return [
            \yii\base\Widget::class => __DIR__ . '/compat/Widget.php',
        ];
    }
}
