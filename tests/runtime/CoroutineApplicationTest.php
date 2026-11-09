<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\runtime;

use Yii2S\Swoole\Runtime\CoroutineApplication;
use Yii2S\Swoole\Runtime\CoroutineRequest;
use Yii2S\Swoole\Runtime\CoroutineResponse;
use yii2stest\TestCase;

/**
 * @group runtime
 */
class CoroutineApplicationTest extends TestCase
{
    public function testConcurrentRendersDoNotShareViewState(): void
    {
        $dir = sys_get_temp_dir() . '/yii2s-view-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/index.php', '<?php');
        file_put_contents(
            $dir . '/t.php',
            '<?php $this->title = $who; echo "S-"; \Swoole\Coroutine::sleep(0.05); echo $this->title;'
        );

        try {
            $this->runInCoroutine(function () use ($dir): void {
                $app = new CoroutineApplication([
                    'id' => 'view-iso',
                    'basePath' => $dir,
                    'components' => [
                        'request' => [
                            'class' => CoroutineRequest::class,
                            'cookieValidationKey' => 'view-iso-key-view-iso',
                            'scriptFile' => $dir . '/index.php',
                            'scriptUrl' => '/index.php',
                            'enableCsrfValidation' => false,
                        ],
                        'response' => ['class' => CoroutineResponse::class],
                    ],
                ]);

                $channel = new \Swoole\Coroutine\Channel(2);
                foreach (['Alice', 'Bob'] as $who) {
                    \Swoole\Coroutine::create(function () use ($app, $dir, $who, $channel): void {
                        $channel->push($app->view->renderFile($dir . '/t.php', ['who' => $who]));
                    });
                }

                $results = [$channel->pop(2), $channel->pop(2)];
                sort($results);
                $this->assertSame(['S-Alice', 'S-Bob'], $results);
            });
        } finally {
            @unlink($dir . '/t.php');
            @unlink($dir . '/index.php');
            @rmdir($dir);
        }
    }

    public function testConcurrentErrorHandlersDoNotShareTheException(): void
    {
        $dir = sys_get_temp_dir() . '/yii2s-error-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/index.php', '<?php');

        try {
            $this->runInCoroutine(function () use ($dir): void {
                $app = new CoroutineApplication([
                    'id' => 'error-iso',
                    'basePath' => $dir,
                    'components' => [
                        'request' => [
                            'class' => CoroutineRequest::class,
                            'cookieValidationKey' => 'error-iso-key-error-iso',
                            'scriptFile' => $dir . '/index.php',
                            'scriptUrl' => '/index.php',
                            'enableCsrfValidation' => false,
                        ],
                        'response' => ['class' => CoroutineResponse::class],
                    ],
                ]);

                $channel = new \Swoole\Coroutine\Channel(2);
                foreach (['alice', 'bob'] as $who) {
                    \Swoole\Coroutine::create(function () use ($app, $who, $channel): void {
                        $handler = $app->getErrorHandler();
                        $handler->exception = new \RuntimeException($who);
                        \Swoole\Coroutine::sleep(0.05);
                        $channel->push($handler->exception->getMessage());
                    });
                }

                $results = [$channel->pop(2), $channel->pop(2)];
                sort($results);
                $this->assertSame(['alice', 'bob'], $results);
            });
        } finally {
            @unlink($dir . '/index.php');
            @rmdir($dir);
        }
    }

    public function testLanguageStaysOnTheCoroutineThatSetIt(): void
    {
        $dir = sys_get_temp_dir() . '/yii2s-lang-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/index.php', '<?php');

        try {
            $this->runInCoroutine(function () use ($dir): void {
                $app = new CoroutineApplication([
                    'id' => 'lang-iso',
                    'basePath' => $dir,
                    'language' => 'zh-CN',
                    'components' => [
                        'request' => [
                            'class' => CoroutineRequest::class,
                            'cookieValidationKey' => 'lang-iso-key-lang-iso-key',
                            'scriptFile' => $dir . '/index.php',
                            'scriptUrl' => '/index.php',
                            'enableCsrfValidation' => false,
                        ],
                        'response' => ['class' => CoroutineResponse::class],
                    ],
                ]);

                $this->assertSame('zh-CN', $app->language);

                $channel = new \Swoole\Coroutine\Channel(2);
                foreach (['en' => 'en', 'ja' => 'ja'] as $who => $language) {
                    \Swoole\Coroutine::create(function () use ($app, $who, $language, $channel): void {
                        $app->language = $language;
                        \Swoole\Coroutine::sleep(0.05);
                        $channel->push($who . ':' . $app->language . ':' . $app->formatter->locale);
                    });
                }

                $results = [$channel->pop(2), $channel->pop(2)];
                sort($results);
                $this->assertSame(['en:en:en', 'ja:ja:ja'], $results);
                $this->assertSame('zh-CN', $app->language, 'the configuring coroutine keeps the default language');
            });
        } finally {
            @unlink($dir . '/index.php');
            @rmdir($dir);
        }
    }
}
