# Yii2S Swoole 加速扩展包 — 开发文档

> 项目：`yii2s/yii2-swoole`
> 运行时：Swoole 6.2.2（swoole-cli 内嵌 PHP 8.4.14，Windows）
> 状态：核心功能已完成并通过真实环境回归测试（2026-11-01）

---

## 一、开发思路

### 1. 目标

让传统 Yii2 应用以**常驻内存 + 协程**模式运行在 Swoole HTTP Server 之上：

- 业务代码尽量不改（仍用 `Yii::$app->db`、`Yii::$app->redis`、`Yii::$app->cache`、`Yii::$app->session`）；
- 每个请求由协程处理，I/O（MySQL/Redis/HTTP）自动协程化，不阻塞事件循环；
- 通过连接池控制资源上限，避免协程环境下的连接风暴。

### 2. 核心难点与对策

| 难点 | 对策 |
|---|---|
| Yii2 设计给 FPM：请求结束进程清空，全局状态可随意用 | 常驻进程下禁止超全局变量；请求级状态全部协程隔离（见 §3.4） |
| `$_GET/$_POST/$_SERVER/$_COOKIE/$_SESSION` 全进程共享，协程下会串请求 | `CoroutineRequest` 从 Swoole Request 对象取数；`CoroutineSession` 用 Redis 存储 + 协程内存 |
| 单个 PDO 连接不能被多个协程并发使用 | 连接池 + **协程粘性借用**：每个协程借一条独立连接，事务期间强绑定 |
| 长生命周期对象持有请求状态（request/response/user） | 每请求结束重建这四个组件、归还池连接、flush 日志 |
| Windows 无法加载 swoole DLL | 使用 `swoole-cli.exe`（自带 PHP 8.4.14 运行时）作为运行环境 |

### 3. 关键设计

#### 3.1 运行模型

```
swoole-cli example/yii swoole/start        （控制台入口，创建 console Application）
  └─ SwooleController::actionStart()
       └─ HttpServer::start()
            ├─ Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL])   ← I/O 全自动协程化
            └─ Coroutine\run()
                 └─ Swoole\Coroutine\Http\Server->handle('/', ...)
                      └─ 每个请求一个协程：
                           RequestDispatcher::dispatch()
                             ├─ getApplication()            ← 首次创建，之后复用（常驻内存核心）
                             ├─ request->setSwooleRequest() ← Swoole→Yii2 请求转换
                             ├─ $app->handleRequest()       ← 标准 Yii2 路由/控制器流程
                             ├─ response->finalize()        ← 内存中完成 formatter 管线
                             ├─ SwooleResponseSender::send()
                             └─ finally: RequestStateManager::cleanup()
```

#### 3.2 连接池（`Pools/ConnectionPool.php`）

- 基于 `Swoole\Coroutine\Channel` 实现，懒创建连接，`maxActive` 封顶；
- 池耗尽时协程阻塞等待 `waitTimeout`，超时抛异常（明确错误路径，不无限等待）；
- 空闲连接可配置健康检查（DB: `SELECT 1`，Redis: `PING`）；
- 归还/销毁成对出现，连接创建失败自动回退池计数。

#### 3.3 协程粘性借用（DB/Redis 共用模式）

```
borrow():
  协程 Context 中已有连接 → 直接复用（粘性）
  没有 → 从池借一条，绑定到 Swoole\Coroutine\Context

好处：
  - 同一协程内的多条语句保证在同一条连接上执行（事务正确性）
  - Context 随协程销毁自动 GC，即使请求异常结束也不会泄漏连接

release():
  请求结束时由 RequestStateManager 调用；若协程内事务未提交则先回滚再归还（兜底）
```

#### 3.4 请求状态重建（`Runtime/RequestStateManager.php`）

每个请求结束后：

1. 重新 `set('request'/'response'/'session'/'user', 原始定义)`，下个请求拿到全新实例；
2. 遍历所有组件，调用实现了 `release()` 的组件归还池连接；
3. `Yii::getLogger()->flush(true)` —— FPM 的"请求结束自动 flush 日志"在常驻进程不存在，必须手动做。

#### 3.5 兼容性开关

所有池化组件都提供 `enableCoroutinePooling`：
`false` 时回退 Yii2 标准行为（共享单连接），便于排障和传统部署。

---

## 二、已实现的功能

### 源码结构（`src/`，命名空间 `Yii2S\Swoole\`）

```
src/
├── Bootstrap.php                    应用引导：注册 swoole 控制台命令、设置 memory_limit
├── Console/SwooleController.php     php yii swoole/start [--host --port]
├── Coroutine/CoroutineContext.php   协程上下文存取封装（协程外自动回退进程内存储）
├── Pools/ConnectionPool.php         通用协程连接池
├── Db/CoroutineDbConnection.php     协程安全 MySQL 连接（连接池 + 粘性借用）
├── Redis/CoroutineRedisConnection.php  协程安全 Redis（原生 phpredis + 池）
├── Cache/CoroutineRedisCache.php    Redis 缓存（兼容 yii\caching\Cache 全 API）
├── Session/CoroutineSession.php     Redis 会话（完全绕开 $_SESSION）
├── Runtime/
│   ├── CoroutineRequest.php         Swoole→Yii2 请求适配（不依赖任何超全局变量）
│   ├── CoroutineResponse.php        内存 finalize（beforeSend/prepare/afterSend）
│   ├── SwooleResponseSender.php     Yii2 Response → Swoole Response（头/Cookie/内容）
│   └── RequestStateManager.php      请求结束的状态重建与资源归还
└── Server/
    ├── RequestDispatcherInterface.php  调度器接口（可替换自定义实现）
    ├── RequestDispatcher.php        默认调度器（含连接池 prewarm）
    └── HttpServer.php               单进程协程 HTTP 服务器
```

## 二点五、测试体系（对齐 Yii2 官方规范）

测试结构完全模仿 yii2 官方仓库（`yii2-master/tests/`）的组织方式：

```
tests/
├── bootstrap.php                    官方风格引导：error_reporting(-1)、YII_DEBUG、别名注册
├── TestCase.php                     基类：mockApplication/mockWebApplication/
│                                    destroyApplication/getParam/runInCoroutine
├── DatabaseTestCase.php             数据库测试基类（fixture 准备，模仿官方 db/DatabaseTestCase）
├── data/
│   ├── config.php                   测试配置（MySQL/Redis），支持 config.local.php 本地覆盖
│   ├── mysql.sql                    数据库 fixture（官方风格 SQL 文件）
│   ├── app.php                      集成测试用最小应用配置
│   └── server.php                   集成测试用独立服务器入口
├── pools/ConnectionPoolTest.php     池：懒创建/复用/耗尽超时/丢弃/关闭
├── db/CoroutineDbConnectionTest.php DB：查询/插入/事务提交回滚/粘性连接/
│                                    release 幂等/5 协程独立连接/非池化回退
├── redis/CoroutineRedisConnectionTest.php  Redis：读写删/命令代理/粘性/并发独立连接
├── cache/CoroutineRedisCacheTest.php 缓存：set/get/add 语义/过期 TTL/ArrayAccess
├── session/CoroutineSessionTest.php 会话：读写删/跨实例持久化(cookie)/regenerateID/迭代器
├── runtime/CoroutineRequestTest.php 请求适配：method/path/query/body/headers/
│                                    HTTPS 识别/cookies
└── server/HttpServerTest.php        集成测试：进程内启动真实服务器 + 协程客户端
                                     全链路请求（200/参数回显/404）+ 优雅停机
```

运行方式（必须用 swoole-cli，协程 + swoole 扩展均在其中）：

```powershell
# 全量测试
D:\swoole-cli-v6.2.2-msys2-x64\bin\swoole-cli.exe vendor/phpunit/phpunit/phpunit --no-coverage

# 单套件
...\swoole-cli.exe vendor/phpunit/phpunit/phpunit --no-coverage tests/db

# 静态分析（phpstan 需要 proc_open，用本机 PHP 跑）
D:\EServer-data\childApp\php\php-8.4\php.exe vendor/phpstan/phpstan/phpstan analyse --no-progress
```

当前状态：**35 tests / 106 assertions 全部通过；phpstan level 5 零错误**。

测试约定（从官方仓库继承）：
- 测试命名空间 `yii2stest\`（对应官方 `yiiunit\`）；
- DB 测试凭证走 `tests/data/config.php` + `config.local.php` 覆盖（不提交真实凭证）；
- 每个依赖协程的测试用 `TestCase::runInCoroutine()` 包裹（池化、协程上下文只在协程内存在）；
- 集成测试的 server 协程必须在 `finally` 中停止，否则 `Coroutine\run()` 会等待未退出的协程导致挂死。

### 功能清单

- [x] 单进程协程 HTTP Server，常驻内存复用 Yii2 Application 实例
- [x] MySQL 连接池：懒创建、超时等待、健康检查、事务安全（粘性借用 + 请求结束兜底回滚）
- [x] Redis 连接池：同上，连接损坏时自动丢弃重建
- [x] `SWOOLE_HOOK_ALL` 钩子：PDO/Redis/curl/file 等 I/O 全部协程化
- [x] Swoole↔Yii2 请求/响应双向转换（headers、cookies、GET/POST/JSON body、HTTPS 识别、客户端 IP）
- [x] 协程安全的 Cache / Session（Redis 存储，TTL 过期，cookie 写回响应）
- [x] 每请求状态重建 + 资源归还 + 日志 flush
- [x] SIGINT/SIGTERM 优雅停机
- [x] 守护化：pid 文件管理 + `swoole/stop` `swoole/status` `swoole/reload` 命令 + Windows 停服标志机制
- [x] 控制台命令 `swoole/start`（Bootstrap 自动注册，无需手动配置 controllerMap）
- [x] 连接池预热（prewarm：服务器启动时提前建池）

### 实测结果（127.0.0.1，MySQL8 root/root，Redis 本机）

| 端点 | 结果 |
|---|---|
| `GET /` | `{"php":"8.4.14","swoole":"6.2.2"}` ✓ |
| `GET /demo/db` | 连接池查询 user 表 ✓ |
| `GET /demo/transaction` | `{"committed":true,"id":9}` 事务+自增ID ✓ |
| `GET /demo/redis` | set/get/incr ✓ |
| `GET /demo/cache` | Redis 缓存读写 ✓ |
| `GET /demo/session` | visits 1→2 同一 sessionId，跨请求保持 ✓ |
| `GET /demo/concurrent` | 10 协程并行 `SLEEP(0.1)` 查询，总耗时 **111ms**（串行需 1000ms）✓ |
| 连续 30 请求 | 30/30 全部 200 ✓ |

示例应用在 `example/`（配置 `example/config/web.php`，控制器 `app/controllers/DemoController.php`）。

---

## 三、实现过程中的关键坑（供后续维护参考）

1. **`Connection::beginTransaction()` 返回 `Transaction` 而非 void** —— 覆写时必须保持返回值，否则 `$db->beginTransaction()` 拿到 null。
2. **`yii\caching\Cache::$keyPrefix` 父类无类型** —— 子类属性不能加 `string` 类型声明，会直接 fatal。
3. **`getLastInsertID($sequenceName = '')` 默认值是空串不是 null** —— 传 null 会导致 `quoteTableName(null)` 在 PHP 8.4 下抛 deprecation。
4. **PHP 8.x 构造器可选参数不能排在必选参数前** —— `ConnectionPool` 构造器已按规范排序。
5. **Windows/cygwin 路径混用分隔符** —— `dirname(__DIR__)` 对混合路径失效，入口脚本统一用 `__DIR__ . '/..'`。
6. **控制台入口需剔除 web 专用组件**（request/response），但要保留 `swooleHttpServer` 让 Bootstrap 注册命令。
7. **请求结束必须手动 flush 日志** —— 常驻进程没有 FPM 的生命周期钩子。
8. **真实 Swoole Request 的 `get/post/cookie` 为空时是 null** —— swoole ide-helper 把它们声明为 array，静态分析会误报"恒真"，运行时必须 `is_array` 防御。
9. **`SessionIterator` 绑死 `$_SESSION`** —— 需自建 `SessionDataIterator` 子类实现协程安全迭代。
10. **server 协程必须保证退出** —— 集成测试中 server 协程一旦残留，`Coroutine\run()` 会等待所有协程导致挂死；测试须在 `finally` 中 `stop()`。
11. **Swoole Channel 不能在协程外调用** —— `ConnectionPool::close()` 与 DB/Redis 组件需协程外回退路径（detached 连接）。
12. **cygwin 版 swoole-cli 不支持 `Swoole\Timer`** —— Timer::tick 挂死整个事件循环；周期任务用 watcher 协程 + `Coroutine::sleep` 循环实现。
13. **`shutdown()` 不能从 Timer 回调直接调用**（死锁）；从普通协程调用安全。
14. **`Coroutine\run()` 等待所有协程结束才返回** —— watcher 循环条件不能是 `isRunning`（它只在 run() 返回后才变 false，形成循环等待）；用显式 `stopRequested` 标志让 watcher 先退出。
15. **PHP stat cache 负缓存** —— 停服标志文件由其他进程创建，常驻服务器内 `is_file()` 会永远读到缓存的"不存在"；`PidFile::read()` 必须先 `clearstatcache(true, $path)`。
16. **本机 PHP 进程内 exec/popen/proc_open 均被系统策略限制** —— `swoole/reload` 的自动 respawn 做了降级：失败时输出原始启动命令提示手动重启。
17. **协程版 `Http\Server` 不支持 `enable_static_handler`** —— 实测所有请求（含已存在的静态文件）都进 handle 回调；静态文件必须 PHP 层实现（`StaticFileServer`）。
18. **phpredis 的 `zRangeByScore` 不接受原始 LIMIT 参数** —— 用 options 数组风格 `['limit' => [0, 1]]`，`executeCommand('ZRANGEBYSCORE', ...)` 直传 6 个参数会炸。
19. **`Coroutine::sleep(0)` 无效** —— 定时器最小 0.001 秒。
20. **PowerShell heredoc 会吞掉 `||` 和 `&&`** —— 用 heredoc 写含逻辑运算符的 PHP 代码后必须复查（出现 `a; b` 形式的语法错误即为中招）。

---

## 三点五、工程化（已对齐官方规范）

### Git

- 已初始化仓库（main 分支），基线 commit `5b0b49c`，工程化 commit `0b5fd58`；
- `.gitignore` 排除 `vendor/`、`composer.lock`（库包规范）、第三方参考仓库、runtime 与本地缓存。

### 代码风格（php-cs-fixer）

`.php-cs-fixer.dist.php` 采用与官方 yii2 相同的规则结构：`@PSR2` + `no_unused_imports` + `phpdoc_scalar` + `header_comment`（MIT 头），作用域 `src/` + `tests/`（排除 `tests/data`）。

```powershell
composer cs          # 检查
composer cs-fix      # 自动修复
```

### 静态分析（phpstan）

`phpstan.neon`：level 5，通过 `scanFiles`/`scanDirectories` 引入 Yii.php 与 swoole ide-helper。

```powershell
composer static
```

### CI（GitHub Actions）

`.github/workflows/ci.yml`，三个并行 job：

| Job | 内容 |
|---|---|
| PHPUnit | PHP 8.1~8.4 矩阵 × MySQL 8 / Redis 7 service 容器 × `setup-php`（pecl 安装 swoole） |
| PHPStan | level 5，`--error-format=github` 行内标注 |
| Coding standard | `php-cs-fixer check --diff` |

### 本地 CI（无需 Docker）

`scripts/ci-local.ps1` 把 CI 三个 job 的命令原样串成一键入口（本机 MySQL8/Redis 等价于 CI 的 service 容器）：

```powershell
powershell -File scripts/ci-local.ps1              # 全部 job
powershell -File scripts/ci-local.ps1 phpunit      # 单个 job：phpunit / static / coding-standard
```

本机分工：phpunit 用 swoole-cli 运行（协程 + swoole 扩展）；phpstan 与 php-cs-fixer 用本机 PHP 8.4 运行（swoole-cli 的 cygwin 环境缺少 proc_open，phpstan 依赖它）。swoole-cli 路径可用环境变量 `SWOOLE_CLI` 覆盖。

---

## 四、尚未实现（Roadmap）

> 工程化三件套（git 基线 / php-cs-fixer / CI + 本地 CI）已于本次完成并从本清单移除。

### 高优先级（建议按此顺序做）

1. [x] **守护化**（已完成）：pid 文件（JSON：pid/host/port/command/started_at）、
   `swoole/stop` `swoole/status` `swoole/reload` 命令、Windows 停服标志文件 +
   watcher 协程轮询（500ms）。Linux 生产环境建议 supervisor/systemd 托管；
   Windows 下 `reload` 会尽力 respawn，失败时提示手动重启（本机 exec/popen 受限）
2. [x] **静态文件服务**（已完成，可选项）：生产架构下静态文件应由 nginx 直接服务（见下文部署章节），
   扩展包内不做静态处理。`HttpServer::documentRoot` 仅作为**开发环境/无反向代理场景**的可选便利：
   注入 `RequestDispatcher::staticRoot` 后由 `StaticFileServer` 提供 MIME 映射、Cache-Control、
   realpath 前缀防穿越校验；未命中的路径自动落到 Yii2 路由。默认关闭（null）。
   注：协程版 `Http\Server` 不支持 Swoole 原生 `enable_static_handler`（实测所有请求都进 handle 回调），
   所以即使要做也只能 PHP 层实现
3. [x] **Queue 组件**（已完成）：`Queue\CoroutineRedisQueue`，任务存 ZSET（score=可执行时间戳）统一延迟投递与可见性超时；
   预留用 ZRANGEBYSCORE+ZREM 原子抢占；失败任务 ttr 后重试（attempts+1），超过 maxAttempts 进死信列表；
   `run(N)` 处理至多 N 个可见任务（队列空即返回），`run(0)` 无限轮询供 `queue/serve` 控制台 worker 使用
   （Bootstrap 检测到 queue 组件自动注册；完整兼容 yii\queue\Queue 接口列为后续增强）
4. [x] **协程 Log Target**（已完成）：`Log\CoroutineFileTarget` 缓冲批量写——请求管理器每请求 flush(true) 不再触发
   每请求开关文件，攒够 maxBufferMessages 才落盘，进程退出/flushNow 兜底
5. [ ] **多进程模型**：已决定采用"多实例 + SO_REUSEPORT"方案——HttpServer 构造时已启用 reuse_port，
   Linux 生产环境直接跑 N 个 `swoole/start` 实例绑同一端口即可利用多核（配合 supervisor/systemd 托管）；
   进程内 fork 的 worker 池不做（Windows 无法测试，且多实例方案更贴近 12-factor）

### 中优先级

- [x] **User 组件**（已验证无需实现）：`RequestStateManager` 每请求重建 `user` 组件（含 identity 缓存），
  session 协程安全，原版 `yii\web\User` 直接可用；集成测试覆盖了会话与状态隔离
- [x] **CSRF 适配**（已验证天然工作）：CSRF cookie 走协程安全的 Request/Response cookie 通道，
  集成测试覆盖 token 签发→无 token 400→带 token 200 全流程；example 已默认开启
- [x] **Redis MULTI/事务**（已验证天然工作）：协程粘性借用保证 multi/exec 落在同一连接，
  phpredis 的 MULTI/DISCARD/PIPELINE 全部可用，测试固化该行为
- [x] **优雅停机增强**（已完成）：`RequestStateManager` 在途请求计数 + `HttpServer::gracefulTimeout`
  （默认 10s）——stop() 先等在途请求排空（有界）再 shutdown，慢请求测试验证响应仍会送达
- [x] **Basic Auth**（已验证天然工作）：`CoroutineRequest` 的 header 通道使官方
  `yii\filters\auth\HttpBasicAuth` 直接可用（`getAuthUser/getAuthPassword` 从 Authorization header
  解析，`PHP_AUTH_*` 本就用不到）；6 个单测固化行为（密码含冒号/空密码/非 Basic scheme/大小写）
- [x] **命令健壮性**（已完成）：`swoole/start` 防双开守卫（pid 文件进程存活即拒绝启动并提示 stop 命令）、
  `queue/serve --limit` 负数校验；自定义应用类已由 `RequestDispatcher` 的 app config `'class'` 键支持

### 低优先级 / 长期

- [x] **队列失败重试、死信**（已完成，随 Queue 组件交付）：ttr 后自动重试、超 maxAttempts 进死信列表；
  多消费 worker 无需代码——Redis 队列预留是原子的，直接跑多个 `queue/serve` 进程即可
- [ ] MySQL 主从分离（`masters`/`slaves` 配置透传，池化模式下未支持）
- [x] **连接池指标导出**（已完成）：`ConnectionPool::getMetrics()`（size/active/idle/waiting 瞬时值 +
  total_created/borrowed/timeouts/discarded 累计值）；`RequestDispatcher` 的 `/__pool` 调试端点
  （`enablePoolMetricsEndpoint`，**默认关闭**，生产建议 nginx 屏蔽该路径或仅内网开启）；
  发现过程中顺带修复了 `prewarm()` 的组件发现 bug（`getComponents(false)` 只返回已实例化组件，
  池组件从未被预热到——现统一由 `PoolMetricsCollector` 按 `HasConnectionPool` 接口发现）
- [ ] 压测基准报告（wrk/ab，对比 FPM 模式）
- [ ] 发布到 Packagist（需 GitHub 仓库 + 打 tag；CI 已就绪）

### 已知边界（设计取舍，非缺陷）

- 需要 `urlManager.enablePrettyUrl = true`（`?r=` 路由依赖 `$_GET`，协程不安全）
- 单进程模型适合 API 服务；CPU 密集任务应交给独立进程
- 主从/读写分离配置在池化模式下未透传

---

## 五、快速上手

```powershell
# 1. 初始化测试库（自动建库建表插数据）
D:\swoole-cli-v6.2.2-msys2-x64\bin\swoole-cli.exe example\init-db.php

# 2. 启动服务器
D:\swoole-cli-v6.2.2-msys2-x64\bin\swoole-cli.exe example\yii swoole/start
# 可选参数：--host=0.0.0.0 --port=8080

# 3. 访问
# http://127.0.0.1:9501/
# http://127.0.0.1:9501/demo/db
# http://127.0.0.1:9501/demo/concurrent
```

接入现有 Yii2 应用只需在配置中：

```php
'bootstrap' => [['class' => \Yii2S\Swoole\Bootstrap::class]],
'components' => [
    'request'  => ['class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class, /* ... */],
    'response' => ['class' => \Yii2S\Swoole\Runtime\CoroutineResponse::class],
    'db'       => ['class' => \Yii2S\Swoole\Db\CoroutineDbConnection::class, /* 原 db 配置不变 */],
    'redis'    => ['class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class],
    'cache'    => ['class' => \Yii2S\Swoole\Cache\CoroutineRedisCache::class],
    'session'  => ['class' => \Yii2S\Swoole\Session\CoroutineSession::class],
    'swooleHttpServer' => [
        'class' => \Yii2S\Swoole\Server\HttpServer::class,
        'host' => '127.0.0.1',
        'port' => 9501,
        'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php'),
    ],
],
```

## 五点五、压测基准

`scripts/bench.php` 是内置的协程压测器（无外部依赖，Linux 同样可用）：

```powershell
swoole-cli scripts/bench.php --path=/demo/db --concurrency=100 --duration=5
```

### 基准环境

- Windows 11 + msys2 swoole-cli（Swoole 6.2.2 / PHP 8.4.14），**单进程** Swoole HTTP 服务器
- 客户端与服务器同机（CPU 竞争，数字偏保守）；MySQL 8 / Redis 7 本机
- keep-alive 连接，预热 1s 不计入

### 结果（5s 采样，零 5xx）

| 端点 | 并发 | QPS | p50 | p99 | 说明 |
|---|---|---|---|---|---|
| `/`（纯框架） | 100 | 8132 | 9.7ms | 19.9ms | 路由+JSON 序列化 |
| `/demo/db`（2 条 SQL） | 100 | 2384 | 33.4ms | 43.7ms | 连接池借还 |
| `/demo/redis`（3 命令） | 100 | 2851 | 27.9ms | 33.5ms | 池化 Redis |
| `/demo/concurrent`（10 并行 SQL） | 5~10 | ~8 | 128ms | 3s* | 见下方容量分析 |

\* p99 3s = borrow 等待超时（waitTimeout=3s）：每请求 10 个子协程各借 1 连接，
并发 10 时需要 100 连接当量，远超池容量 20，部分查询排队到超时（demo 把超时吞成 error payload 返回 200）。
**连接池是硬容量边界**：业务峰值需要的并发连接数不要超过 `poolMaxActive`，超容量即排队/超时。

### 压测发现的两个真实 bug（均已修复）

1. **子协程连接泄漏**：`demo/concurrent` 每请求 10 个子协程各借 1 连接，存入各自的
   `CoroutineContext`；请求结束时 `release()` 只归还主协程的连接——子协程借的连接全部泄漏，
   几个请求后池被漏光（当时 `/__pool` 显示 db 池 active=20、idle=0）。
   修复：`ConnectionPool::trackBorrow()` 登记借出协程 cid，`sweep()`（每请求结束时由
   RequestStateManager 调用）回收借用方已死亡的连接（销毁重建，正确性优先）。
2. **msys2 平台 WeakMap 跨协程不可见**：子协程对 WeakMap 的写入在其他协程读不到
   （独立脚本实锤），导致最初基于 WeakMap 的追踪失效、基于 defer 的归还直接把进程打崩。
   修复：登记表改用普通数组（`spl_object_id` 索引）。**该平台的 WeakMap 不要跨协程使用。**

## 六、生产部署（nginx + 多实例）

标准架构：**nginx 负责静态文件与 TLS 终结，Swoole 服务器只处理动态请求**——扩展包不需要做静态文件服务。
多实例（利用多核）：`swoole/start` 构造时已启用 `SO_REUSEPORT`，直接启动 N 个实例绑同一端口即可，
由 supervisor/systemd 托管。

```nginx
server {
    listen 80;
    server_name example.com;

    root /var/www/app/public;   # 静态资源目录
    index index.html;

    # 静态文件：nginx 直接服务（命中不了才走动态）
    location ~* \.(css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|eot|map|json)$ {
        expires 30d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

    # 动态请求：转发给 Swoole 服务器（多实例同一端口由内核负载均衡）
    location / {
        proxy_pass http://127.0.0.1:9501;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

对应的 supervisor 配置（多实例）：

```ini
[program:yii2s]
command=/usr/local/bin/swoole-cli /var/www/app/yii swoole/start --host=127.0.0.1 --port=9501
numprocs=4                      ; 4 个实例共享 9501 端口（reuse_port）
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true                ; 实例退出即自动拉起（等价于 reload 的生产形态）
stopsignal=TERM                 ; 触发优雅停机
stopwaitsecs=25
```

要点：

- `X-Forwarded-Proto/For` 会被 `CoroutineRequest` 自动识别（HTTPS 判定、真实 IP）；
- 静态文件由 nginx 直接返回，动态路径全部走 Swoole；`documentRoot` 配置此时不需要；
- 队列消费独立于 HTTP 服务器运行：`php yii queue/serve`，同样用 supervisor 托管。
