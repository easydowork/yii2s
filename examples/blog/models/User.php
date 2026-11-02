<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

class User extends ActiveRecord implements IdentityInterface
{
    public static function tableName(): string
    {
        return '{{%user}}';
    }

    public static function findIdentity($id): ?self
    {
        return static::findOne($id);
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

    public function validatePassword(string $password): bool
    {
        return Yii::$app->security->validatePassword($password, $this->getAttribute('password_hash'));
    }
}
