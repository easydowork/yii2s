<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class Post extends ActiveRecord
{
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;

    public static function tableName(): string
    {
        return '{{%post}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['title', 'content'], 'required'],
            [['title'], 'string', 'max' => 200],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_PUBLISHED]],
            [['status'], 'default', 'value' => self::STATUS_PUBLISHED],
        ];
    }

    public function attributeLabels(): array
    {
        return ['title' => 'Title', 'content' => 'Content', 'status' => 'Status'];
    }

    public function getAuthor(): \yii\db\ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'author_id']);
    }
}
