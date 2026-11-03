<?php

namespace yii2stest\framework\models;

use yii\db\ActiveRecord;

class FwComment extends ActiveRecord
{
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;

    public static function getDb(): \Yii2S\Swoole\Db\CoroutineDbConnection
    {
        return \yii2stest\framework\FwDb::$connection ?? throw new \RuntimeException("FwDb connection not initialized");
    }

    public static function tableName(): string
    {
        return '{{%fw_comment}}';
    }

    public function rules(): array
    {
        return [
            [['post_id', 'content'], 'required'],
            [['content'], 'string', 'max' => 1000],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_APPROVED]],
        ];
    }

    public function getPost(): \yii\db\ActiveQuery
    {
        return $this->hasOne(FwPost::class, ['id' => 'post_id'])->inverseOf('comments');
    }
}
