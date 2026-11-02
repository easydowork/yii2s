<?php

/**
 * PHP-CS-Fixer configuration, mirroring the official Yii2 repository rules.
 *
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$header = '@link https://github.com/yii2s/yii2-swoole
@copyright Copyright (c) 2026 Yii2S Software LLC
@license MIT';

$srcFinder = (new Finder())
    ->in(__DIR__ . '/src');

$testsFinder = (new Finder())
    ->in(__DIR__ . '/tests')
    ->notPath([
        '#^data(/|$)#',
        '#^runtime(/|$)#',
    ]);

$finder = $srcFinder->append($testsFinder);

return (new Config())
    ->setFinder($finder)
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR2' => true,
        'no_unused_imports' => true,
        'phpdoc_scalar' => true,
        'header_comment' => [
            'comment_type' => 'PHPDoc',
            'header' => $header,
            'location' => 'after_open',
        ],
    ]);
