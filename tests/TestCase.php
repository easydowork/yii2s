<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest;

use Yii;
use yii\helpers\ArrayHelper;

/**
 * This is the base class for all yii2s/swoole unit tests.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public static $params;

    /**
     * Clean up after test case.
     */
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        Yii::getLogger()->flush();
    }

    /**
     * Clean up after test.
     * By default the application created with [[mockApplication]] will be destroyed.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->destroyApplication();
    }

    /**
     * Returns a test configuration param from /data/config.php.
     * @param string $name params name
     * @param mixed $default default value to use when param is not set
     * @return mixed the value of the configuration param
     */
    public static function getParam($name, $default = null)
    {
        if (static::$params === null) {
            static::$params = require __DIR__ . '/data/config.php';
        }

        return isset(static::$params[$name]) ? static::$params[$name] : $default;
    }

    /**
     * Populates Yii::$app with a new console application.
     * The application will be destroyed on tearDown() automatically.
     * @param array $config the application configuration, if needed
     * @param string $appClass name of the application class to create
     */
    protected function mockApplication($config = [], $appClass = '\yii\console\Application')
    {
        new $appClass(ArrayHelper::merge([
            'id' => 'testapp',
            'basePath' => __DIR__,
        ], $config));
    }

    /**
     * Populates Yii::$app with a new web application.
     * @param array $config the application configuration, if needed
     */
    protected function mockWebApplication($config = [])
    {
        $this->mockApplication(ArrayHelper::merge([
            'components' => [
                'request' => [
                    'cookieValidationKey' => 'yii2stest-cookie-key',
                ],
            ],
        ], $config), '\yii\web\Application');
    }

    /**
     * Destroys application in Yii::$app by setting it to null.
     */
    protected function destroyApplication()
    {
        if (Yii::$app && Yii::$app->has('session', true)) {
            Yii::$app->session->close();
        }
        Yii::$app = null;
    }

    /**
     * Runs the given callback inside a Swoole coroutine.
     *
     * Most of the package behavior (pooling, coroutine context, hooks) only
     * exists inside a coroutine, so coroutine-dependent tests must be wrapped
     * with this helper.
     *
     * @param callable $callback
     * @return mixed the callback return value
     */
    protected function runInCoroutine(callable $callback)
    {
        $result = null;
        $exception = null;

        \Swoole\Coroutine\run(function () use ($callback, &$result, &$exception): void {
            try {
                $result = $callback();
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }
}
