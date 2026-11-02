<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\db\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Book AR mapped to the per-test `yii2s_ar_book` table (created and dropped
 * by ActiveRecordTest), with an author_id FK to `customer`.
 *
 * @property int $id
 * @property int $author_id
 * @property string $title
 * @property int $created_at
 * @property-read Author|null $author
 */
class Book extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%yii2s_ar_book}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => false,
            ],
        ];
    }

    public function getAuthor(): ActiveQuery
    {
        return $this->hasOne(Author::class, ['id' => 'author_id']);
    }
}
