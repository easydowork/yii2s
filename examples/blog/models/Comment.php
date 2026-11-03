<?php

namespace app\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Reader comment, moderated from the admin panel.
 *
 * @property int $id
 * @property int $post_id
 * @property string $author_name
 * @property string $author_email
 * @property string $content
 * @property int $status
 * @property string $ip
 * @property int $created_at
 * @property Post $post
 */
class Comment extends ActiveRecord
{
    public const STATUS_PENDING = 0;

    public const STATUS_APPROVED = 1;

    public static function tableName(): string
    {
        return '{{%comment}}';
    }

    /**
     * Note: `status` and `ip` are deliberately not part of the rules - they
     * are assigned by the controller, never by the request payload.
     */
    public function rules(): array
    {
        return [
            [['author_name', 'author_email', 'content'], 'required'],
            [['post_id'], 'integer'],
            [['author_name'], 'string', 'max' => 64],
            [['author_email'], 'email'],
            [['author_email'], 'string', 'max' => 128],
            [['content'], 'string', 'max' => 1000],
            [['post_id'], 'exist', 'targetClass' => Post::class, 'targetAttribute' => 'id'],
        ];
    }

    public function behaviors(): array
    {
        return [
            ['class' => TimestampBehavior::class, 'updatedAtAttribute' => false],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'author_name' => 'Name',
            'author_email' => 'Email',
            'content' => 'Comment',
        ];
    }

    public function getPost(): ActiveQuery
    {
        return $this->hasOne(Post::class, ['id' => 'post_id']);
    }

    /**
     * @return Comment[]
     */
    public static function approvedFor(int $postId): array
    {
        return static::find()
            ->where(['post_id' => $postId, 'status' => self::STATUS_APPROVED])
            ->orderBy(['created_at' => SORT_DESC])
            ->all();
    }
}
