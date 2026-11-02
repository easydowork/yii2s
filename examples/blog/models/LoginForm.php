<?php

namespace app\models;

use Yii;
use yii\base\Model;

class LoginForm extends Model
{
    public string $username = '';
    public string $password = '';
    public bool $rememberMe = true;

    private ?User $_user = null;

    public function rules(): array
    {
        return [
            [['username', 'password'], 'required'],
            [['username'], 'string', 'max' => 64],
            [['password'], 'string', 'max' => 128],
            [['rememberMe'], 'boolean'],
            [['password'], 'validatePassword'],
        ];
    }

    public function validatePassword(string $attribute): void
    {
        $user = $this->getUser();
        if ($user === null || !$user->validatePassword($this->password)) {
            $this->addError($attribute, 'Incorrect username or password.');
        }
    }

    public function login(): bool
    {
        if ($this->validate() && ($user = $this->getUser()) !== null) {
            return Yii::$app->user->login($user, $this->rememberMe ? 3600 * 24 * 30 : 0);
        }

        return false;
    }

    private function getUser(): ?User
    {
        if ($this->_user === null) {
            $this->_user = User::findByUsername($this->username);
        }

        return $this->_user;
    }
}
