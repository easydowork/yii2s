<?php

namespace yii2stest\framework\models;

use yii\db\ActiveRecord;

class FwPostTag extends ActiveRecord
{
    public static function getDb(): \Yii2S\Swoole\Db\CoroutineDbConnection
    {
        return \yii2stest\framework\FwDb::$connection ?? throw new \RuntimeException("FwDb connection not initialized");
    }

    public static function tableName(): string
    {
        return '{{%fw_post_tag}}';
    }

    public function rules(): array
    {
        return [
            [['post_id', 'tag_id'], 'required'],
            [['post_id', 'tag_id'], 'integer'],
        ];
    }
}
