<?php

namespace yii2stest\framework\models;

use yii\behaviors\SluggableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

class FwPost extends ActiveRecord
{
    public const STATUS_DRAFT = 0;
    public const STATUS_PUBLISHED = 1;

    public function init(): void
    {
        parent::init();
        $this->version = 1;
        $this->views = 0;
    }

    public static function getDb(): \Yii2S\Swoole\Db\CoroutineDbConnection
    {
        return \yii2stest\framework\FwDb::$connection ?? throw new \RuntimeException("FwDb connection not initialized");
    }

    public function optimisticLock(): string
    {
        return 'version';
    }

    public static function tableName(): string
    {
        return '{{%fw_post}}';
    }

    public function behaviors(): array
    {
        return [
            TimestampBehavior::class,
            [
                'class' => SluggableBehavior::class,
                'attribute' => 'title',
                'ensureUnique' => false,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['title', 'content'], 'required'],
            [['title'], 'string', 'max' => 200],
            [['views'], 'default', 'value' => 0],
            [['views'], 'integer', 'min' => 0],
            [['status'], 'default', 'value' => self::STATUS_PUBLISHED],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_PUBLISHED]],
            [['author_id'], 'exist', 'targetClass' => FwUser::class, 'targetAttribute' => 'id'],
        ];
    }

    public function getAuthor(): \yii\db\ActiveQuery
    {
        return $this->hasOne(FwUser::class, ['id' => 'author_id'])->inverseOf('posts');
    }

    public function getComments(): \yii\db\ActiveQuery
    {
        return $this->hasMany(FwComment::class, ['post_id' => 'id']);
    }

    public function getTags(): \yii\db\ActiveQuery
    {
        return $this->hasMany(FwTag::class, ['id' => 'tag_id'])
            ->viaTable('{{%fw_post_tag}}', ['post_id' => 'id']);
    }
}
