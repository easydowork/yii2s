<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

use yii\base\BaseObject;
use yii\web\IdentityInterface;

/**
 * In-memory identity: the auth feature points must be verifiable without
 * depending on a users table, so only ids 1 and 2 exist.
 */
class CompatIdentity extends BaseObject implements IdentityInterface
{
    public function __construct(private readonly int $id, array $config = [])
    {
        parent::__construct($config);
    }

    public static function findIdentity($id): ?self
    {
        return in_array((int) $id, [1, 2], true) ? new self((int) $id) : null;
    }

    public static function findIdentityByAccessToken($token, $type = null): ?self
    {
        return null;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAuthKey(): string
    {
        return 'auth-key-' . $this->id;
    }

    public function validateAuthKey($authKey): bool
    {
        return $this->getAuthKey() === $authKey;
    }
}
