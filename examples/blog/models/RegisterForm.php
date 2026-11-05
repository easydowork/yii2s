<?php

namespace app\models;

use Yii;
use yii\base\Model;

/**
 * Public signup. New accounts always start as an `author` (least privilege);
 * an admin can promote them later.
 */
class RegisterForm extends Model
{
    public string $username = '';

    public string $email = '';

    public string $password = '';

    public string $password_repeat = '';

    public function rules(): array
    {
        return [
            [['username', 'email', 'password', 'password_repeat'], 'required'],
            [['username'], 'trim'],
            [['username'], 'string', 'min' => 3, 'max' => 64],
            [['username'], 'unique', 'targetClass' => User::class, 'message' => 'This username is already taken.'],
            [['email'], 'trim'],
            [['email'], 'email'],
            [['email'], 'string', 'max' => 128],
            [['password'], 'string', 'min' => 6],
            [['password_repeat'], 'compare', 'compareAttribute' => 'password', 'message' => 'Passwords do not match.'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'username' => 'Username',
            'email' => 'Email',
            'password' => 'Password',
            'password_repeat' => 'Repeat password',
        ];
    }

    public function register(): ?User
    {
        if (!$this->validate()) {
            return null;
        }

        $user = new User();
        $user->username = $this->username;
        $user->email = $this->email !== '' ? $this->email : null;
        $user->role = User::ROLE_AUTHOR;
        $user->status = User::STATUS_ACTIVE;
        $user->auth_key = Yii::$app->security->generateRandomString();
        $user->setPassword($this->password);

        if (!$user->save()) {
            $this->addErrors($user->getErrors());

            return null;
        }

        return $user;
    }
}
