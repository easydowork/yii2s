<?php

namespace yii2stest\framework\models;

use yii\behaviors\BlameableBehavior;
use yii\db\ActiveRecord;

class FwUser extends ActiveRecord
{
    public static function getDb(): \Yii2S\Swoole\Db\CoroutineDbConnection
    {
        return \yii2stest\framework\FwDb::$connection ?? throw new \RuntimeException("FwDb connection not initialized");
    }

    public static function tableName(): string
    {
        return '{{%fw_user}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => BlameableBehavior::class,
                'createdByAttribute' => 'created_by',
                'updatedByAttribute' => false,
                'value' => 99, // fixed "current user" for tests
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['username'], 'required'],
            [['username'], 'string', 'max' => 64],
            [['username'], 'unique'],
            [['email'], 'email'],
            [['email'], 'string', 'max' => 128],
            [['status'], 'default', 'value' => 1],
            [['status'], 'in', 'range' => [0, 1]],
        ];
    }

    public function getPosts(): \yii\db\ActiveQuery
    {
        return $this->hasMany(FwPost::class, ['author_id' => 'id'])->inverseOf('author');
    }
}
