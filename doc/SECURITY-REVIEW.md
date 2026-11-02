# 代码漏洞排查与修复报告

> 项目：`yii2s/yii2-swoole`
> 日期：2026-11-02
> 范围：`src/` 全部 23 个文件 + `tests/` 回归验证
> 结论：发现并修复 **6 个缺陷**（2 个 P0 崩溃/数据错误、3 个 P1 安全、1 个 P2 并发正确性），新增 4 个回归测试 + 1 个断言。
> 验证：PHPUnit **72 tests / 251 assertions 全绿**；PHPStan level 5 **零错误**；php-cs-fixer **0 需修复**。

---

## 一、概述

本次按 `REVIEW.md` 建议的 P0→P3 切入顺序对源码做了独立安全评审，重点攻击连接池并发、请求状态隔离、静态文件路径穿越、代理头信任、Cookie 转发、队列 worker、缓存 TTL 等声称。对每一处疑点都通过**真实复现脚本**确认（非纯静态推断），修复后再回归。

评审方法：
1. 通读 `src/` 全部实现，对照 Yii2 / Swoole 官方行为逐点核查；
2. 对可疑点用 `swoole-cli` 写独立探针脚本在真实 Redis/MySQL 上复现；
3. 修复后新增回归测试并跑全量三件套（PHPUnit / PHPStan / php-cs-fixer）。

---

## 二、发现并修复的问题清单

### P0-1 队列 worker `queue/serve` 空队列时进程崩溃（致命）

| 项 | 内容 |
|---|---|
| 严重度 | **P0 / Fatal**（`queue/serve` 是文档指定的生产消费 worker，完全不可用） |
| 位置 | `src/Queue/CoroutineRedisQueue.php`（修复前 `run()` 内的空队列轮询分支） |
| 复现 | `php yii queue/serve`（空队列）→ `Fatal error: Uncaught Swoole\Error: API must be called in the coroutine`，定位到 `CoroutineRedisQueue.php:172` |

**原因**：`run(0)` 无限轮询模式下，队列为空时调用 `Swoole\Coroutine::sleep(0.05)`。但 `queue/serve` 是控制台命令，运行在主线程（`Coroutine::getCid() === -1`），Swoole 在协程外调用 `sleep` 直接抛 `Swoole\Error`。而队列空时立即命中该分支，worker 一启动就崩。

**修复**：新增 `idle(float $seconds)`，协程内走 `Coroutine::sleep`，协程外回退阻塞式 `usleep()`：

```200:208:src/Queue/CoroutineRedisQueue.php
    private function idle(float $seconds): void
    {
        if (\Swoole\Coroutine::getCid() === -1) {
            usleep((int) round($seconds * 1_000_000));
            return;
        }

        \Swoole\Coroutine::sleep($seconds);
    }
```

`run()` 的空队列分支改为 `$this->idle(0.05);`（`src/Queue/CoroutineRedisQueue.php:172`）。

**验证**：`queue/serve --limit=1` 正常消费 1 个任务；`queue/serve`（空队列）启动 2 秒后仍存活、stderr 无输出（修复前立即崩）。

---

### P0-2 `Cache::add()` 带 TTL 时过期时间被静默丢弃（数据错误）

| 项 | 内容 |
|---|---|
| 严重度 | **P0 / 数据错误**（缓存永不过期，`add()` 的 `$duration` 参数完全失效） |
| 位置 | `src/Cache/CoroutineRedisCache.php` `addValue()`（修复前 L71） |
| 复现 | `$redis->set('k','v',['NX','EX',60])` → 写入成功但 `TTL=-1`（无过期） |

**原因**：phpredis 的 `SET` 选项数组要求 `EX` 以**关联键**传递（`['NX','EX'=>60]`），数字索引的 `['NX','EX',60]` 中 `EX` 被当作普通值，expiry 被静默丢弃。原有测试 `testAddDoesNotOverwrite` 只测了 `$duration=0` 分支，未覆盖此路径。

**修复**：

```71:71:src/Cache/CoroutineRedisCache.php
            $result = $this->redis->executeCommand('SET', [$key, $value, ['NX', 'EX' => (int) ceil($duration)]]);
```

**验证**：新增 `testAddWithDurationSetsExpiration`，断言 TTL 落在 `(0, 60]` 区间。

---

### P1-1 Cookie 丢失 `SameSite` 属性（CSRF 防护减弱）

| 项 | 内容 |
|---|---|
| 严重度 | **P1 / 安全**（CSRF/session cookie 的 `SameSite` 被静默剥离） |
| 位置 | `src/Runtime/SwooleResponseSender.php` `sendCookies()`（修复前） |
| 复现 | 读源码即可证：`yii\web\Cookie::$sameSite` 默认 `SAME_SITE_LAX`，但 `cookie()` 调用只传了 7 个参数，漏掉第 8 参 `samesite` |

**原因**：Swoole `Response::cookie()` 第 8 个参数才是 `samesite`，原实现未转发，导致 Yii2 默认的 `SameSite=Lax` 从未下发到浏览器。

**修复**：转发 `sameSite`；同时补全 `expire` 的归一化——原先只处理 `DateInterval`，对 `DateTimeInterface` / 字符串日期会因 `strict_types` 直接 `TypeError`：

```54:63:src/Runtime/SwooleResponseSender.php
            $swooleResponse->cookie(
                $cookie->name,
                (string) $cookie->value,
                $expire,
                (string) $cookie->path,
                (string) $cookie->domain,
                (bool) $cookie->secure,
                (bool) $cookie->httpOnly,
                (string) $cookie->sameSite
            );
```

**验证**：CSRF 集成测试新增断言——`set-cookie` 头必须含 `samesite`（大小写不敏感）。

---

### P1-2 `getIsSecureConnection()` 硬编码信任 `X-Forwarded-Proto`（可伪造 HTTPS）

| 项 | 内容 |
|---|---|
| 严重度 | **P1 / 安全**（直连部署时可伪造 https，影响 `secure` cookie、URL 生成） |
| 位置 | `src/Runtime/CoroutineRequest.php` `getIsSecureConnection()`（修复前） |
| 复现 | `curl -H 'X-Forwarded-Proto: https' http://直连IP/` → `getIsSecureConnection()` 返回 `true` |

**原因**：原实现无条件信任 `X-Forwarded-Proto`，且**无视** Yii2 的 `secureProtocolHeaders` 配置，部署者无法关闭代理头信任。

**修复**：改为按 Yii2 `secureProtocolHeaders` 配置解析（默认行为不变，仍信任 `X-Forwarded-Proto: https`；配置 `secureProtocolHeaders => []` 即可关闭）：

```197:222:src/Runtime/CoroutineRequest.php
    public function getIsSecureConnection(): bool
    {
        $https = strtolower((string) $this->serverParam('https'));
        if ($https === 'on' || $https === '1') {
            return true;
        }

        foreach ($this->secureProtocolHeaders as $header => $values) {
            $headerValue = $this->getHeaders()->get($header);
            if ($headerValue === null) {
                continue;
            }
            foreach ($values as $value) {
                if (strcasecmp((string) $headerValue, (string) $value) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
```

**验证**：新增 `testSecureConnectionHonorsSecureProtocolHeadersConfig`，断言配置为空数组后不再信任该头。

---

### P1-3 静态文件服务可泄露源码/敏感文件（信息泄露）

| 项 | 内容 |
|---|---|
| 严重度 | **P1 / 安全**（documentRoot 下 `.php`/`.env`/`.sql` 等被原样返回） |
| 位置 | `src/Server/StaticFileServer.php`（修复前 `serve()`） |
| 复现 | `documentRoot` 放一个 `secret.php` → `curl /secret.php` 返回 200 + 源码明文 |

**原因**：静态文件服务只校验「有扩展名 + realpath 前缀」，未拦截可执行/敏感类型。路径穿越本身是安全的（realpath 前缀比较），但**源码/敏感文件泄露**是真实面。

**修复**：新增可执行/敏感扩展黑名单 + 隐藏文件（dotfile）拦截，命中即回落 Yii2 路由（404）：

```56:82:src/Server/StaticFileServer.php
    private const BLOCKED_EXTENSIONS = [
        'php' => true, 'php3' => true, 'php4' => true, 'php5' => true,
        'php7' => true, 'php8' => true, 'phtml' => true, 'phar' => true,
        'phps' => true, 'pht' => true, 'inc' => true, 'cgi' => true,
        'pl' => true, 'py' => true, 'rb' => true, 'sh' => true, 'bat' => true,
        'asp' => true, 'aspx' => true, 'jsp' => true, 'htaccess' => true,
        'env' => true, 'sql' => true, 'ini' => true, 'log' => true,
    ];
```

```117:123:src/Server/StaticFileServer.php
        // Never serve hidden files (dotfiles) or executable/sensitive files.
        if (str_starts_with(pathinfo($path, PATHINFO_BASENAME), '.')) {
            return false;
        }
        if (isset(self::BLOCKED_EXTENSIONS[$extension])) {
            return false;
        }
```

**验证**：新增 `testExecutableAndHiddenFilesAreNotServed`，断言 `/secret.php` 与 `/.env` 均返回 404。

---

### P2-1 日志目标并发丢失/重复消息（并发正确性）

| 项 | 内容 |
|---|---|
| 严重度 | **P2 / 正确性**（高并发下日志丢失或重复写，影响排障与审计） |
| 位置 | `src/Log/CoroutineFileTarget.php`（修复前 `collect()`/`flushNow()`） |
| 复现 | 多个请求协程并发触发 `Yii::getLogger()->flush(true)`，`messages` 的「读-改-写 + 重置」无锁 |

**原因**：常驻服务器每请求都会 `flush` 日志，`collect()` 的 `array_merge` / `export` / `$this->messages = []` 在协程并发下产生竞态。

**修复**：新增基于 Swoole Channel 的进程内互斥锁（协程外自动跳过，保证 CLI 兼容）：

```55:84:src/Log/CoroutineFileTarget.php
    public function collect($messages, $final): void
    {
        $this->lock();
        try {
            $this->messages = array_merge($this->messages, $messages);
            if (count($this->messages) >= $this->maxBufferMessages) {
                $this->export();
                $this->messages = [];
            }
        } finally {
            $this->unlock();
        }
    }
```

---

## 三、测试与验证

| 检查项 | 命令 | 结果 |
|---|---|---|
| PHPUnit 全量 | `swoole-cli vendor/phpunit/phpunit/phpunit --no-coverage` | **OK（72 tests, 251 assertions）** |
| PHPStan | `php vendor/phpstan/phpstan/phpstan analyse --no-progress` | **[OK] No errors（level 5）** |
| php-cs-fixer | `php vendor/friendsofphp/php-cs-fixer/php-cs-fixer check --diff` | **0 需修复** |
| queue 端到端 | `example/yii queue/serve --limit=1` | 正常消费 1 个任务 |
| queue 空队列 | `example/yii queue/serve` 空队列运行 2s | 不再崩溃，无错误输出 |

新增/调整的测试：

| 测试 | 覆盖缺陷 |
|---|---|
| `CoroutineRedisCacheTest::testAddWithDurationSetsExpiration` | P0-2 缓存 TTL |
| `CoroutineRedisQueueTest::testServeDoesNotCrashPollingEmptyQueueOutsideCoroutine` | P0-1 队列崩溃 |
| `CoroutineRequestTest::testSecureConnectionHonorsSecureProtocolHeadersConfig` | P1-2 代理头信任 |
| `HttpServerStaticTest::testExecutableAndHiddenFilesAreNotServed` | P1-3 源码泄露 |
| `HttpServerCsrfTest`（新增 `samesite` 断言） | P1-1 Cookie SameSite |

---

## 四、评审过但判定无需修复的点

| 点 | 结论 |
|---|---|
| `StaticFileServer` 路径穿越（`/../config/web.php`） | **安全**——`realpath` 前缀比较已正确拦截，`curl --path-as-is` 实测 404 |
| 连接池 `size` 计数 / `borrow()` 三路路径 / `sweep()` 竞态 | **正确**——`size++` 在 factory 挂起前同步执行，`return()` 前先 unset borrowed，push 不会阻塞；`sweep()` 用 `listCoroutines()` 判定存活 |
| `CoroutineRequest::resolve()` 参数合并顺序 | **与 Yii2 一致**——`$params + getQueryParams()` 匹配官方 `$params + $_GET` |
| `CoroutineSession` 会话固定（fixation） | **与 PHP/Yii2 默认一致**，登录时 `regenerateID()` 已覆盖，非本次范围 |
| `CoroutineRedisCache::flushValues()` SCAN 无 `is_array` 防御 | **可接受**——`executeCommand` 已把 `RedisException` 转成异常，不会返回 `false` |

---

## 五、遗留限制（未修复，非本次范围）

1. `HttpServerGracefulStopTest` 偶发时序失败（随机端口冲突），重跑即绿——测试基建问题，与产品无关（`REVIEW.md` 第 7 节已交代）。
2. `X-Forwarded-Proto` 信任默认仍开启（与 Yii2 默认一致）；直连生产部署建议显式配置 `secureProtocolHeaders => []` 或前置 nginx。
3. `documentRoot` 静态文件服务仍为开发便利，生产推荐 nginx 直出（`README.md` 已注明）。
