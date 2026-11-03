<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\framework;

use yii\base\DynamicModel;
use yii2stest\DatabaseTestCase;

/**
 * The validator family: built-in validators, scenarios, inline custom
 * validators, error collection ? under the coroutine runtime.
 *
 * @group db
 * @group mysql
 */
class ValidatorsTest extends DatabaseTestCase
{
    use FwSchema;

    protected $driverName = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFwSchema();
        \yii2stest\framework\FwDb::$connection = $this->getConnection(false);
    }

    protected function tearDown(): void
    {
        $this->dropFwSchema();
        parent::tearDown();
    }

    private function validate(array $rules, array $attrs, string $scenario = "default"): DynamicModel
    {
        $model = DynamicModel::validateData($attrs, $rules);
        if ($scenario !== "default") {
            $model->scenario = $scenario;
        }

        return $model;
    }

    public function testBuiltInValidators(): void
    {
        $this->runInCoroutine(function (): void {
            $m = $this->validate([
                ["u", "required"],
                ["s", "string", "min" => 2, "max" => 5],
                ["i", "integer", "min" => 1],
                ["e", "email"],
                ["u2", "url"],
                ["r", "in", "range" => ["a", "b"]],
                ["re", "match", "pattern" => "/^[a-z]+$/"],
            ], [
                "u" => "user", "s" => "abc", "i" => 5, "e" => "a@b.io",
                "u2" => "https://example.com", "r" => "a", "re" => "hello",
            ]);
            $this->assertFalse($m->hasErrors());

            $bad = $this->validate([
                ["s", "string", "min" => 2, "max" => 5],
                ["e", "email"],
                ["r", "in", "range" => ["a", "b"]],
                ["re", "match", "pattern" => "/^[a-z]+$/"],
            ], [
                "s" => "toolongvalue", "e" => "not-email", "r" => "c", "re" => "123",
            ]);
            $this->assertTrue($bad->hasErrors());
            $this->assertArrayHasKey("s", $bad->errors);
            $this->assertArrayHasKey("e", $bad->errors);
            $this->assertArrayHasKey("r", $bad->errors);
            $this->assertArrayHasKey("re", $bad->errors);
        });
    }

    public function testRequiredAndDefaultAndFilter(): void
    {
        $this->runInCoroutine(function (): void {
            $m = $this->validate([
                ["name", "filter", "filter" => "trim"],
                ["name", "required"],
                ["code", "default", "value" => "AUTO"],
            ], ["name" => "  spaced  "]);
            $this->assertFalse($m->hasErrors());
            $attributes = $m->getAttributes();
            $this->assertSame("spaced", $attributes["name"], "trim filter applied");
            $this->assertSame("AUTO", $attributes["code"], "default applied for missing attribute");
        });
    }

    public function testUniqueValidator(): void
    {
        $this->runInCoroutine(function (): void {
            // fw_user has username 'alice' (seeded)
            $m = $this->validate([
                ["username", "unique", "targetClass" => \yii2stest\framework\models\FwUser::class, "targetAttribute" => "username"],
            ], ["username" => "alice"]);
            $this->assertTrue($m->hasErrors(), "duplicate username must fail unique");

            $ok = $this->validate([
                ["username", "unique", "targetClass" => \yii2stest\framework\models\FwUser::class, "targetAttribute" => "username"],
            ], ["username" => "brand-new-user"]);
            $this->assertFalse($ok->hasErrors(), "fresh username passes");
        });
    }

    public function testExistValidator(): void
    {
        $this->runInCoroutine(function (): void {
            $ok = $this->validate([
                ["author_id", "exist", "targetClass" => \yii2stest\framework\models\FwUser::class, "targetAttribute" => "id"],
            ], ["author_id" => 1]);
            $this->assertFalse($ok->hasErrors());

            $bad = $this->validate([
                ["author_id", "exist", "targetClass" => \yii2stest\framework\models\FwUser::class, "targetAttribute" => "id"],
            ], ["author_id" => 9999]);
            $this->assertTrue($bad->hasErrors(), "nonexistent author must fail exist");
        });
    }

    public function testInlineCustomValidator(): void
    {
        $this->runInCoroutine(function (): void {
            $called = 0;
            $m = $this->validate([
                ["v", function ($attribute, $params, $validator) use (&$called): void {
                    $called++;
                    // DB access from an inline validator coroutine (proves it works)
                    $total = \yii2stest\framework\FwDb::$connection->createCommand(
                        "SELECT COUNT(*) FROM {{%fw_post}}"
                    )->queryScalar();
                    if ((int) $this->$attribute > 100) {
                        // $this is bound to the DynamicModel by InlineValidator,
                        // which static analysis cannot see.
                        // @phpstan-ignore method.notFound
                        $this->addError($attribute, "value too high (posts: $total)");
                    }
                }],
            ], ["v" => 999999]);
            $this->assertSame(1, $called, "inline validator ran (DB access from coroutine)");
            $this->assertTrue($m->hasErrors(), "custom rule must add error");
        });
    }

    public function testCompareValidator(): void
    {
        $this->runInCoroutine(function (): void {
            $m = $this->validate([
                ["a", "compare", "compareAttribute" => "b"],
            ], ["a" => 10, "b" => 10]);
            $this->assertFalse($m->hasErrors());

            $bad = $this->validate([
                ["a", "compare", "compareAttribute" => "b"],
            ], ["a" => 11, "b" => 10]);
            $this->assertTrue($bad->hasErrors());
        });
    }
}
