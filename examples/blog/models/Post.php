<?php

namespace app\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property string $title
 * @property string $content
 * @property string|null $cover relative path under web/ (e.g. uploads/x.png)
 * @property int $status
 * @property int $views baseline view count persisted in the database
 * @property int $author_id
 * @property int $created_at
 * @property int $updated_at
 * @property User $author
 * @property Comment[] $comments
 */
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

    public function getAuthor(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'author_id']);
    }

    public function getComments(): ActiveQuery
    {
        return $this->hasMany(Comment::class, ['post_id' => 'id']);
    }

    public function getCommentCount(): int
    {
        return (int) $this->getComments()->count();
    }

    /**
     * Public URL of the cover image, or null when the post has none.
     */
    public function getCoverUrl(): ?string
    {
        if ($this->cover === null || $this->cover === '') {
            return null;
        }

        return Yii::$app->request->getBaseUrl() . '/' . ltrim((string) $this->cover, '/');
    }
}
