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

    private bool $useStrictMode = true;

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
     * Strict session mode (Yii2 default: enabled): a session id supplied by
     * the client is only accepted when the corresponding session already
     * exists. Without this an attacker can plant a known id (session
     * fixation) and take over the session once the victim logs in.
     *
     * Overridden to store the value locally instead of writing the
     * `session.use_strict_mode` ini setting, which has no effect here.
     */
    public function setUseStrictMode($value): void
    {
        $this->useStrictMode = (bool) $value;
    }

    public function getUseStrictMode(): bool
    {
        return $this->useStrictMode;
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

        $fromClientCookie = $this->resolveId();
        $this->opened = true;
        $this->loadData($fromClientCookie);
        $this->updateFlashCounters();
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
        if (!$this->opened) {
            // Native session_regenerate_id() keeps the payload. Load it before
            // switching ids so data written earlier in the request (cart,
            // returnUrl, flash) survives a login-triggered regeneration.
            $fromClientCookie = $this->resolveId();
            $this->loadData($fromClientCookie);
        }

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

    // --- flash messages -------------------------------------------------
    // yii\web\Session implements the flash API on top of the process-global
    // $_SESSION array, which is shared by every coroutine. These overrides
    // keep the exact same counter semantics but operate on the coroutine-local
    // payload instead.

    public function setFlash($key, $value = true, $removeAfterAccess = true): void
    {
        $this->open();
        $counters = $this->flashCounters();
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->data[$key] = $value;
        $this->data[$this->flashParam] = $counters;
        $this->persist();
    }

    public function addFlash($key, $value = true, $removeAfterAccess = true): void
    {
        $this->open();
        $counters = $this->flashCounters();
        $counters[$key] = $removeAfterAccess ? -1 : 0;
        $this->data[$this->flashParam] = $counters;

        if (empty($this->data[$key])) {
            $this->data[$key] = [$value];
        } elseif (is_array($this->data[$key])) {
            $this->data[$key][] = $value;
        } else {
            $this->data[$key] = [$this->data[$key], $value];
        }
        $this->persist();
    }

    public function getFlash($key, $defaultValue = null, $delete = false): mixed
    {
        $this->open();
        $counters = $this->flashCounters();
        if (!isset($counters[$key])) {
            return $defaultValue;
        }

        $value = $this->data[$key] ?? $defaultValue;
        if ($delete) {
            $this->removeFlash($key);
        } elseif ($counters[$key] < 0) {
            // Mark for deletion in the next request.
            $counters[$key] = 1;
            $this->data[$this->flashParam] = $counters;
            $this->persist();
        }

        return $value;
    }

    public function getAllFlashes($delete = false): array
    {
        $this->open();
        $counters = $this->flashCounters();
        $flashes = [];
        foreach (array_keys($counters) as $key) {
            if (array_key_exists($key, $this->data)) {
                $flashes[$key] = $this->data[$key];
                if ($delete) {
                    unset($counters[$key], $this->data[$key]);
                } elseif ($counters[$key] < 0) {
                    $counters[$key] = 1;
                }
            } else {
                unset($counters[$key]);
            }
        }

        $this->data[$this->flashParam] = $counters;
        $this->persist();

        return $flashes;
    }

    public function removeFlash($key): mixed
    {
        $this->open();
        $counters = $this->flashCounters();
        $value = isset($this->data[$key], $counters[$key]) ? $this->data[$key] : null;
        unset($counters[$key], $this->data[$key]);
        $this->data[$this->flashParam] = $counters;
        $this->persist();

        return $value;
    }

    public function removeAllFlashes(): void
    {
        $this->open();
        foreach (array_keys($this->flashCounters()) as $key) {
            unset($this->data[$key]);
        }
        unset($this->data[$this->flashParam]);
        $this->persist();
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

    /**
     * The native implementation looks at the process-global `$_COOKIE` array,
     * which Swoole never populates. `yii\web\User::renewAuthStatus()` uses
     * this method to decide whether it may read the identity from the
     * session, so without the override every request after a login would be
     * treated as a guest.
     */
    public function getHasSessionId(): bool
    {
        $app = Yii::$app;
        if (!$app instanceof \yii\web\Application) {
            return false;
        }

        $cookie = $app->getRequest()->getCookies()->get($this->getName());

        return is_string($cookie->value ?? null) && $cookie->value !== '';
    }

    private function storageKey(): string
    {
        return $this->keyPrefix . $this->sessionId;
    }

    /**
     * Resolves the session id for this request. An explicitly assigned id
     * (setId()) wins over the client cookie, matching yii\web\Session
     * semantics (session_id($this->getId())).
     *
     * @return bool whether the id was taken from the client cookie
     */
    private function resolveId(): bool
    {
        if ($this->sessionId !== null) {
            return false;
        }

        $cookieId = $this->resolveClientSessionId();
        if ($cookieId !== null) {
            $this->sessionId = $cookieId;
            $this->idFromClient = true;

            return true;
        }

        $this->sessionId = $this->generateSessionId();

        return false;
    }

    /**
     * Loads the session payload for the current id.
     */
    private function loadData(bool $fromClientCookie): void
    {
        $raw = $this->redis->executeCommand('GET', [$this->storageKey()]);
        $exists = is_string($raw) && $raw !== '';

        if ($this->useStrictMode && $fromClientCookie && !$exists) {
            // Uninitialized id supplied by the client: never adopt it,
            // otherwise an attacker-chosen id survives the login.
            $this->sessionId = $this->generateSessionId();
            $this->idFromClient = false;
            $this->data = [];

            return;
        }

        $decoded = $exists ? @unserialize($raw) : null;
        $this->data = is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, int> flash message counters
     */
    private function flashCounters(): array
    {
        $counters = $this->data[$this->flashParam] ?? [];

        return is_array($counters) ? $counters : [];
    }

    /**
     * Native sessions age flash counters when the session starts: 0 becomes 1
     * (visible for one more request) and anything above 0 drops the message.
     */
    protected function updateFlashCounters(): void
    {
        $counters = $this->flashCounters();
        if ($counters === []) {
            unset($this->data[$this->flashParam]);

            return;
        }

        $changed = false;
        foreach ($counters as $key => $count) {
            if ((int) $count > 0) {
                unset($counters[$key], $this->data[$key]);
                $changed = true;
            } elseif ((int) $count === 0) {
                $counters[$key] = 1;
                $changed = true;
            }
        }

        $this->data[$this->flashParam] = $counters;
        if ($changed) {
            $this->persist();
        }
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
