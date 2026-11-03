<?php

namespace yii2stest\framework\models;

use yii\db\ActiveRecord;

class FwTag extends ActiveRecord
{
    public static function getDb(): \Yii2S\Swoole\Db\CoroutineDbConnection
    {
        return \yii2stest\framework\FwDb::$connection ?? throw new \RuntimeException("FwDb connection not initialized");
    }

    public static function tableName(): string
    {
        return '{{%fw_tag}}';
    }

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 64],
            [['name'], 'unique'],
        ];
    }

    public function getPosts(): \yii\db\ActiveQuery
    {
        return $this->hasMany(FwPost::class, ['id' => 'post_id'])
            ->viaTable('{{%fw_post_tag}}', ['tag_id' => 'id']);
    }
}
