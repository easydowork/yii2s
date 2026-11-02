<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\console;

use Yii;
use Yii2S\Swoole\Console\SwooleController;
use Yii2S\Swoole\Server\HttpServer;
use Yii2S\Swoole\Server\RequestDispatcher;
use yii\console\Application as ConsoleApplication;
use yii2stest\TestCase;

/**
 * `swoole/check` configuration doctor: reads the web app config statically
 * (from the dispatcher's file) and probes db/redis reachability through
 * temporary connections. Must fail with actionable output and a non-zero
 * exit code on broken configs, pass cleanly on healthy ones.
 *
 * @group console
 */
class SwooleCheckTest extends TestCase
{
    private string $configFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configFile = sys_get_temp_dir() . '/yii2stest-check-' . bin2hex(random_bytes(4)) . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
        parent::tearDown();
    }

    private function buildController(array $webComponents): SwooleController
    {
        file_put_contents($this->configFile, '<?php return ' . var_export([
            'id' => 'yii2stest-check',
            'components' => $webComponents,
        ], true) . ';');

        new ConsoleApplication([
            'id' => 'yii2stest-check',
            'basePath' => dirname(__DIR__),
            'components' => [],
            'controllerMap' => [
                'swoole' => ['class' => SwooleController::class],
            ],
        ]);

        $server = new HttpServer([
            'dispatcher' => new RequestDispatcher($this->configFile),
        ]);
        Yii::$app->set('swooleHttpServer', $server);

        /** @var SwooleController $controller */
        $controller = Yii::$app->controllerMap['swoole'];
        if (is_array($controller)) {
            $controller = Yii::createObject($controller);
        }

        return $controller;
    }

    private function healthyComponents(): array
    {
        $dbConfig = $this->getParam('databases')['mysql'];
        unset($dbConfig['fixture']);
        $redisConfig = $this->getParam('redis');
        unset($redisConfig['fixture']);

        return [
            'request' => ['cookieValidationKey' => 'check-test-key'],
            'urlManager' => ['enablePrettyUrl' => true],
            'db' => array_merge(['class' => \Yii2S\Swoole\Db\CoroutineDbConnection::class], $dbConfig),
            'redis' => array_merge(['class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class], $redisConfig),
        ];
    }

    public function testCheckPassesWithHealthyConfiguration(): void
    {
        $controller = $this->buildController($this->healthyComponents());
        $exit = $controller->actionCheck();
        $this->assertSame(0, $exit, 'a healthy configuration must pass swoole/check');
    }

    public function testCheckFailsWhenRedisIsUnreachable(): void
    {
        $components = $this->healthyComponents();
        $components['redis']['port'] = 1; // nothing listens here
        $controller = $this->buildController($components);
        $exit = $controller->actionCheck();
        $this->assertNotSame(0, $exit, 'an unreachable redis must fail swoole/check');
    }

    public function testCheckFailsWithoutPrettyUrl(): void
    {
        $components = $this->healthyComponents();
        $components['urlManager']['enablePrettyUrl'] = false;
        $controller = $this->buildController($components);
        $exit = $controller->actionCheck();
        $this->assertNotSame(0, $exit, 'prettyUrl=false is unsupported and must be reported');
    }

    public function testCheckFailsWithoutCookieValidationKey(): void
    {
        $components = $this->healthyComponents();
        unset($components['request']);
        $controller = $this->buildController($components);
        $exit = $controller->actionCheck();
        $this->assertNotSame(0, $exit, 'missing cookieValidationKey must be reported');
    }

    public function testCheckSkipsMissingComponents(): void
    {
        $controller = $this->buildController([
            'request' => ['cookieValidationKey' => 'k'],
            'urlManager' => ['enablePrettyUrl' => true],
        ]);
        $exit = $controller->actionCheck();
        $this->assertSame(0, $exit, 'no db/redis configured => nothing to fail');
    }

    public function testCheckFailsWhenConfigFileMissing(): void
    {
        new ConsoleApplication([
            'id' => 'yii2stest-check',
            'basePath' => dirname(__DIR__),
            'controllerMap' => [
                'swoole' => ['class' => SwooleController::class],
            ],
        ]);
        $server = new HttpServer([
            'dispatcher' => new RequestDispatcher('/nonexistent/web.php'),
        ]);
        Yii::$app->set('swooleHttpServer', $server);
        /** @var SwooleController $controller */
        $controller = Yii::createObject(SwooleController::class);
        $exit = $controller->actionCheck();
        $this->assertNotSame(0, $exit, 'a missing app config file must fail the check');
    }
}
