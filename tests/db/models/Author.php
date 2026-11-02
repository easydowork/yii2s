<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\db\models;

use yii\db\ActiveRecord;

/**
 * Author AR mapped to the `customer` fixture table.
 *
 * Tracks model events through static counters so tests can assert they fired.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int $created_at
 * @property-read Book[] $books
 */
class Author extends ActiveRecord
{
    /** @var array<string, int> event name => times fired (per process) */
    public static array $eventLog = [];

    public static function tableName(): string
    {
        return '{{%customer}}';
    }

    public function getBooks(): \yii\db\ActiveQuery
    {
        return $this->hasMany(Book::class, ['author_id' => 'id']);
    }

    public function rules(): array
    {
        return [
            [['name', 'email'], 'required'],
            [['name'], 'string', 'max' => 64],
            [['email'], 'email'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $key = 'beforeSave:' . ($insert ? 'insert' : 'update');
        self::$eventLog[$key] = (self::$eventLog[$key] ?? 0) + 1;

        return true;
    }

    public function afterFind(): void
    {
        self::$eventLog['afterFind'] = (self::$eventLog['afterFind'] ?? 0) + 1;
        parent::afterFind();
    }
}
