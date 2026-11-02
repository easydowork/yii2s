<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Session;

use Yii;
use Yii2S\Swoole\Redis\CoroutineRedisConnection;
use yii\di\Instance;
use yii\web\Cookie;
use yii\web\Session;

/**
 * Coroutine-safe session component backed by Redis.
 *
 * Replaces native PHP session functions and the process-global $_SESSION array
 * with a Redis key per session (TTL = [[timeout]]) and coroutine-local memory.
 */
class CoroutineSession extends Session
{
    /** @var CoroutineRedisConnection|array|string the redis connection */
    public $redis = 'redis';

    /** @var string prefix for redis session keys */
    public string $keyPrefix = 'yii2s:session:';

    private bool $opened = false;

    private array $data = [];

    private ?string $sessionId = null;

    private bool $idFromClient = false;

    private int $timeoutSeconds = 1440;

    public function init(): void
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, CoroutineRedisConnection::class);
    }

    /**
     * Overrides the parent setter, which writes the PHP ini setting
     * `session.gc_maxlifetime`. This session never uses the native PHP
     * session machinery (garbage collection is handled by the Redis TTL),
     * so the ini write is pointless at best - and it throws once the
     * resident server rebuilds this component on a later request with
     * output already started.
     */
    public function setTimeout($value): void
    {
        $this->timeoutSeconds = (int) $value;
    }

    public function getTimeout(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * Strict mode has no meaning without the native session machinery: the
     * session id always comes from the client cookie or a freshly generated
     * random value, never from an uninitialized store. Overridden purely to
     * avoid the parent's ini write.
     */
    public function setUseStrictMode($value): void
    {
        // accepted for configuration compatibility; intentionally ignored
    }

    /**
     * Garbage collection is handled by the Redis TTL; the probability knobs
     * only affect the native session handler, so they are ignored.
     */
    public function setGcProbability($value): void
    {
        // accepted for configuration compatibility; intentionally ignored
    }

    public function open(): void
    {
        if ($this->opened) {
            return;
        }

        // An explicitly assigned id (setId()) wins over the client cookie,
        // matching yii\web\Session semantics (session_id($this->getId())).
        // Cookie-based resolution only runs when no id was assigned.
        if ($this->sessionId === null) {
            $this->sessionId = $this->resolveSessionId();
        }
        $this->opened = true;

        $raw = $this->redis->executeCommand('GET', [$this->storageKey()]);
        if (is_string($raw) && $raw !== '') {
            $decoded = @unserialize($raw);
            $this->data = is_array($decoded) ? $decoded : [];
        } else {
            $this->data = [];
        }

        $this->touch();
        $this->sendSessionCookie();
    }

    public function close(): void
    {
        if (!$this->opened) {
            return;
        }
        $this->persist();
        $this->opened = false;
    }

    public function destroy(): void
    {
        if ($this->sessionId !== null) {
            $this->redis->executeCommand('DEL', [$this->storageKey()]);
        }
        $this->data = [];
        $this->opened = false;
        $this->expireSessionCookie();
    }

    public function regenerateID($deleteOldSession = true): void
    {
        $oldId = $this->sessionId;
        if ($oldId !== null && $deleteOldSession) {
            $this->redis->executeCommand('DEL', [$this->keyPrefix . $oldId]);
        }

        $this->sessionId = $this->generateSessionId();
        $this->idFromClient = false;
        $this->persist();
        $this->sendSessionCookie();
    }

    public function getId(): ?string
    {
        return $this->sessionId ?? $this->resolveClientSessionId();
    }

    public function setId($value): void
    {
        $this->sessionId = $value;
        $this->idFromClient = true;
    }

    public function get($key, $defaultValue = null): mixed
    {
        $this->open();
        return $this->data[$key] ?? $defaultValue;
    }

    public function set($key, $value): void
    {
        $this->open();
        $this->data[$key] = $value;
        $this->persist();
    }

    public function has($key): bool
    {
        $this->open();
        return isset($this->data[$key]) || array_key_exists($key, $this->data);
    }

    public function remove($key): mixed
    {
        $this->open();
        if (!array_key_exists($key, $this->data)) {
            return null;
        }
        $value = $this->data[$key];
        unset($this->data[$key]);
        $this->persist();

        return $value;
    }

    public function removeAll(): void
    {
        $this->open();
        $this->data = [];
        $this->persist();
    }

    public function count(): int
    {
        $this->open();
        return count($this->data);
    }

    public function getIterator(): \yii\web\SessionIterator
    {
        $this->open();
        return new SessionDataIterator($this->data);
    }

    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet($offset, $item): void
    {
        $this->set($offset, $item);
    }

    public function offsetUnset($offset): void
    {
        $this->remove($offset);
    }

    public function getIsActive(): bool
    {
        return $this->opened;
    }

    private function storageKey(): string
    {
        return $this->keyPrefix . $this->sessionId;
    }

    private function resolveSessionId(): string
    {
        $clientId = $this->resolveClientSessionId();
        if ($clientId !== null) {
            $this->idFromClient = true;
            return $clientId;
        }

        return $this->generateSessionId();
    }

    private function resolveClientSessionId(): ?string
    {
        if ($this->sessionId !== null && $this->idFromClient) {
            return $this->sessionId;
        }

        $app = Yii::$app;
        if (!$app instanceof \yii\web\Application) {
            return null;
        }

        $cookie = $app->getRequest()->getCookies()->get($this->getName());
        $value = $cookie->value ?? null;

        return is_string($value) && $value !== '' && preg_match('/^[a-zA-Z0-9,-]{1,128}$/', $value) === 1
            ? $value
            : null;
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function touch(): void
    {
        $timeout = $this->getTimeout();
        if ($timeout > 0) {
            $this->redis->executeCommand('EXPIRE', [$this->storageKey(), $timeout]);
        }
    }

    private function persist(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        $timeout = max(1, $this->getTimeout());
        $this->redis->executeCommand('SETEX', [$this->storageKey(), $timeout, serialize($this->data)]);
    }

    private function sendSessionCookie(): void
    {
        $app = Yii::$app;
        if (!$app instanceof \yii\web\Application) {
            return;
        }

        $app->getResponse()->getCookies()->add($this->buildCookie($this->sessionId ?? '', 0));
    }

    private function expireSessionCookie(): void
    {
        $app = Yii::$app;
        if (!$app instanceof \yii\web\Application) {
            return;
        }

        $app->getResponse()->getCookies()->add($this->buildCookie('', 1));
    }

    private function buildCookie(string $value, int $expire): Cookie
    {
        $params = $this->getCookieParams();

        return new Cookie([
            'name' => $this->getName(),
            'value' => $value,
            'expire' => $expire,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => $params['secure'] ?? false,
            'httpOnly' => $params['httpOnly'] ?? true,
            'sameSite' => $params['sameSite'] ?? null,
        ]);
    }
}
