<?php

namespace app\models;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\filters\RateLimitInterface;
use yii\web\IdentityInterface;

/**
 * @property int $id
 * @property string $username
 * @property string $password_hash
 * @property string $auth_key
 * @property string|null $email
 * @property string $role
 * @property int $status
 * @property int $created_at
 */
class User extends ActiveRecord implements IdentityInterface, RateLimitInterface
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_EDITOR = 'editor';

    public const ROLE_AUTHOR = 'author';

    public const STATUS_ACTIVE = 1;

    public const STATUS_DISABLED = 0;

    public static function tableName(): string
    {
        return '{{%user}}';
    }

    public function rules(): array
    {
        return [
            [['username', 'email'], 'trim'],
            [['username'], 'required'],
            [['username'], 'string', 'min' => 3, 'max' => 64],
            [['username'], 'unique'],
            [['email'], 'email'],
            [['email'], 'string', 'max' => 128],
            [['role'], 'in', 'range' => [self::ROLE_ADMIN, self::ROLE_EDITOR, self::ROLE_AUTHOR]],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_DISABLED]],
        ];
    }

    public function behaviors(): array
    {
        return [
            ['class' => \yii\behaviors\TimestampBehavior::class, 'updatedAtAttribute' => false],
        ];
    }

    public static function findIdentity($id): ?self
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    public static function findIdentityByAccessToken($token, $type = null): ?self
    {
        return null;
    }

    public static function findByUsername(string $username): ?self
    {
        return static::findOne(['username' => $username]);
    }

    public function getId(): ?int
    {
        return (int) $this->getAttribute('id');
    }

    public function getAuthKey(): string
    {
        return (string) $this->getAttribute('auth_key');
    }

    public function validateAuthKey($authKey): bool
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Rate-limit contract for `yii\filters\RateLimiter`: 10 requests per
     * 10 seconds per user. The allowance lives in Redis (coroutine pool),
     * so concurrent requests from the same user share one bucket.
     */
    public function getRateLimit($request, $action): array
    {
        return [10, 10];
    }

    public function loadAllowance($request, $action): array
    {
        $raw = Yii::$app->redis->get('blog:rl:' . $this->getId());

        return $raw !== false && $raw !== null
            ? array_map('intval', json_decode((string) $raw, true))
            : [10, time()];
    }

    public function saveAllowance($request, $action, $allowance, $timestamp): void
    {
        Yii::$app->redis->set(
            'blog:rl:' . $this->getId(),
            json_encode([(int) $allowance, (int) $timestamp])
        );
    }

    public function validatePassword(string $password): bool
    {
        return Yii::$app->security->validatePassword($password, $this->getAttribute('password_hash'));
    }

    public function setPassword(string $password): void
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isEditor(): bool
    {
        return $this->role === self::ROLE_EDITOR || $this->isAdmin();
    }

    public function isDisabled(): bool
    {
        return (int) $this->status !== self::STATUS_ACTIVE;
    }

    public static function roleOptions(): array
    {
        return [
            self::ROLE_AUTHOR => 'Author',
            self::ROLE_EDITOR => 'Editor',
            self::ROLE_ADMIN => 'Admin',
        ];
    }

    public function getRoleLabel(): string
    {
        return self::roleOptions()[$this->role] ?? $this->role;
    }

    public function getPosts(): ActiveQuery
    {
        return $this->hasMany(Post::class, ['author_id' => 'id']);
    }
}
