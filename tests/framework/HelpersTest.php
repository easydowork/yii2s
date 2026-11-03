<?php

declare(strict_types=1);

namespace yii2stest\framework;

use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\StringHelper;
use yii\helpers\Inflector;
use yii2stest\TestCase;

/**
 * Yii2 helper classes under the coroutine runtime ? verifying no state
 * pollution between concurrent coroutines.
 *
 * @group helpers
 */
class HelpersTest extends TestCase
{
    public function testArrayHelperOperations(): void
    {
        $this->runInCoroutine(function (): void {
            $data = [
                ["id" => 1, "name" => "alice", "role" => "admin"],
                ["id" => 2, "name" => "bob", "role" => "user"],
                ["id" => 3, "name" => "carol", "role" => "admin"],
            ];
            $this->assertSame("bob", ArrayHelper::getValue($data, "1.name"));
            $indexed = ArrayHelper::index($data, "id");
            $this->assertSame("carol", $indexed[3]["name"]);
            $mapped = ArrayHelper::map($data, "id", "name");
            $this->assertSame(["1" => "alice", "2" => "bob", "3" => "carol"], $mapped);
            $cols = ArrayHelper::getColumn($data, "role");
            $this->assertSame(["admin", "user", "admin"], $cols);
        });
    }

    public function testArrayHelperMergeInCoroutine(): void
    {
        $this->runInCoroutine(function (): void {
            $a = ["components" => ["db" => ["dsn" => "mysql:host=x"], "cache" => ["class" => "Cache"]]];
            $b = ["components" => ["db" => ["username" => "root"], "log" => ["target" => "file"]]];
            $merged = ArrayHelper::merge($a, $b);
            $this->assertSame("mysql:host=x", $merged["components"]["db"]["dsn"]);
            $this->assertSame("root", $merged["components"]["db"]["username"]);
            $this->assertArrayHasKey("log", $merged["components"]);
        });
    }

    public function testHtmlAndJson(): void
    {
        $this->runInCoroutine(function (): void {
            $this->assertSame("&lt;script&gt;", Html::encode("<script>"));
            $this->assertSame("<b>bold</b>", Html::tag("b", "bold"));
            $json = Json::encode(["key" => "value", "num" => 42]);
            $decoded = Json::decode($json);
            $this->assertSame("value", $decoded["key"]);
            $this->assertSame(42, $decoded["num"]);
        });
    }

    public function testStringHelperAndInflector(): void
    {
        $this->runInCoroutine(function (): void {
            $this->assertSame("hello.php", StringHelper::basename("/path/to/hello.php"));
            $this->assertSame(5, StringHelper::byteLength("hello"));
            $this->assertSame(11, StringHelper::byteLength("hello world"));
            $this->assertSame("hello-world", Inflector::camel2id("helloWorld"));
            $this->assertSame("HelloWorld", Inflector::id2camel("hello-world"));
        });
    }

    public function testConcurrentHelperCalls(): void
    {
        $this->runInCoroutine(function (): void {
            $results = [];
            $wg = new \Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 5; $i++) {
                $wg->add();
                \Swoole\Coroutine::create(function () use ($i, $wg, &$results): void {
                    $val = ArrayHelper::getValue(["k" => $i * 10], "k");
                    $json = Json::encode(["i" => $i]);
                    $decoded = Json::decode($json);
                    $results[$i] = $val + $decoded["i"];
                    $wg->done();
                });
            }
            $wg->wait();
            ksort($results);
            $this->assertSame([0, 11, 22, 33, 44], array_values($results), "concurrent helper calls must not pollute state");
        });
    }
}
