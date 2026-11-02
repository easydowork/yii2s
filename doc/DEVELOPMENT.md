# Yii2S Swoole — 开发与使用文档（完整版）

> 本文档是项目唯一完整文档（合并了原 `DEVELOPMENT.md` / `ROADMAP.md` / `REVIEW.md` /
> `SECURITY-REVIEW.md`），按「上手 → 接入 → 开发 → 运维 → 测试 → 扩展」的使用旅程组织。
> 快速导览与安装见 [`README.md`](../README.md)；版本变更见 [`../CHANGELOG.md`](../CHANGELOG.md)。

## 目录

- [第一部分 · 快速上手](#第一部分--快速上手)
- [第二部分 · 常驻内存开发守则（业务开发者必读）](#第二部分--常驻内存开发守则业务开发者必读)
- [第三部分 · 架构与设计](#第三部分--架构与设计)
- [第四部分 · 运维与部署](#第四部分--运维与部署)
- [第五部分 · 测试与验证](#第五部分--测试与验证)
- [第六部分 · 扩展与路线图](#第六部分--扩展与路线图)
- [第七部分 · 踩坑实录（29 条）](#第七部分--踩坑实录29-条)
- [附录 A · 独立评审指南](#附录-a--独立评审指南)
- [附录 B · 安全评审记录（两轮）](#附录-b--安全评审记录两轮)

---

# 第一部分 · 快速上手

## 1.1 环境要求

| 依赖 | 版本 |
|---|---|
| PHP | >= 8.2（实测 8.4 / 8.5；Swoole 6 的 PHP 下限即 8.2） |
| ext-swoole | ^6.0（协程 Http Server + 连接池） |
| yiisoft/yii2 | ^2.0 |
| MySQL / Redis | 可选，按使用的组件而定 |

> **目标平台是 Linux**（生产环境）；macOS 可开发（pecl 编译安装即可）。
> 两种安装方式：标准 `pecl install swoole`，或
> [swoole-cli](https://www.swoole.com/page/download)（自带全部扩展的独立二进制）。
> 不支持 Windows 原生运行。

## 1.2 五分钟跑起来（example 目录）

```bash
# 1. 初始化演示数据库（建库建表插数据，需要本地 MySQL）
php example/init-db.php

# 2. 启动服务器
php example/yii swoole/start

# 3. 访问
curl http://127.0.0.1:9501/
curl http://127.0.0.1:9501/demo/db
```

## 1.3 接入现有应用

### 方式一：一行接入（推荐）

现有 Yii2 应用只需在 `bootstrap` 里加一条——**其余零改动**：

```php
// config/web.php
return [
    'bootstrap' => [
        ['class' => \Yii2S\Swoole\Bootstrap::class],
    ],
    // 你已有的 request/response/db/redis 配置原样保留：
    // Bootstrap 会把 stock 类（yii\web\Request、yii\db\Connection、
    // yii\redis\Connection ...）自动替换为协程版，所有参数原封不动；
    // 没有显式配置 swooleHttpServer 时会注册一个默认服务器组件
    //（127.0.0.1:9501，dispatcher 指向 @app/config/web.php）。
];
```

```bash
php yii swoole/start        # 就这么跑起来了
```

> 注意：服务器进程会 `require` 这个 config 文件两次（控制台入口一次、请求分发一次），
> 配置里不要写带副作用的顶层代码。

### 方式二：显式配置（完全控制）

```php
'request' => [
    'class' => \Yii2S\Swoole\Runtime\CoroutineRequest::class,
    'cookieValidationKey' => '...',
    'enableCsrfValidation' => true,       // CSRF 完整可用
],
'response' => [
    'class' => \Yii2S\Swoole\Runtime\CoroutineResponse::class,
],
'log' => [
    'targets' => [[
        'class' => \Yii2S\Swoole\Log\CoroutineFileTarget::class,  // 缓冲批量写
        'maxBufferMessages' => 100,
        'logFile' => '@app/runtime/logs/app.log',
    ]],
],
'db' => [
    'class' => \Yii2S\Swoole\Db\CoroutineDbConnection::class,
    'dsn' => 'mysql:host=127.0.0.1;dbname=app',
    'username' => '...', 'password' => '...',
    'poolMaxActive' => 20,        // 池容量：并发查询的硬上限
    'poolWaitTimeout' => 3.0,     // 借不到连接的等待秒数
],
'redis' => [
    'class' => \Yii2S\Swoole\Redis\CoroutineRedisConnection::class,
    'hostname' => '127.0.0.1',
    'poolMaxActive' => 20,
],
'swooleHttpServer' => [
    'class' => \Yii2S\Swoole\Server\HttpServer::class,
    'host' => '127.0.0.1',
    'port' => 9501,
    'pidFile' => '@app/runtime/swoole-server.pid',
    'documentRoot' => '@app/public',      // 可选：静态文件（开发环境）
    'dispatcher' => new \Yii2S\Swoole\Server\RequestDispatcher('@app/config/web.php'),
],
```

两种方式下，cache / session / queue 都需要显式协程类（无 stock 对应物可自动替换）：

```php
'cache' => ['class' => \Yii2S\Swoole\Cache\CoroutineRedisCache::class, 'redis' => 'redis'],
'session' => ['class' => \Yii2S\Swoole\Session\CoroutineSession::class, 'redis' => 'redis'],
'queue' => [
    'class' => \Yii2S\Swoole\Queue\CoroutineRedisQueue::class,
    'redis' => 'redis',
    'channel' => 'default',
    'ttr' => 60,                  // 失败任务重试延迟
    'maxAttempts' => 3,           // 超过则进死信列表
    'handler' => function (array $payload): void { /* 处理任务 */ },
],
```

`request/response/session/user` 由框架每请求自动重建，原版 `yii\web\User`、
`yii\filters\auth\HttpBasicAuth` 等直接可用。Redis 的 MULTI/PIPELINE、
DB 的事务也都原生支持（连接在协程内粘性借用）。

## 1.4 控制台命令与开发热重载

```bash
php yii swoole/start [--host=] [--port=] [--watch]  # 启动（阻塞），写 pid 文件
php yii swoole/status                               # 查看运行状态
php yii swoole/stop                                 # 优雅停机（在途请求排空后退出）
php yii swoole/reload                               # 停机 + 尽力重新拉起（生产建议交给 supervisor）
php yii swoole/check                                # 配置体检（db/redis 探活、关键配置校验）
php yii queue/serve [--limit=N]                     # 队列消费 worker（无限轮询）
```

**开发热重载**：`--watch` 修改 `@app` 下 PHP 文件后自动优雅重启（约 1 秒内完成，
在途请求不中断）。轮询 mtime 实现、零外部依赖，Linux/macOS 行为一致。
可调：`watchDirs`（默认 `@app`）、`watchInterval`（0.5s）、`watchExcludes`。
**开发专用**——重启进程，生产禁止。

`request/response/session/user` 由框架每请求自动重建；生命周期插槽（定时任务等）
与更多接入细节见 [第四部分](#第四部分--运维与部署) 与 [第六部分](#第六部分--扩展与路线图)。

---

# 第二部分 · 常驻内存开发守则（业务开发者必读）

进程常驻意味着**进程内的一切状态都会跨请求存活**。以下是迁移 FPM 应用时
最容易踩的语义差异，按"会不会炸线上"排序：

### 禁止（会直接产生跨请求数据污染）

1. **请求态数据放进静态属性 / 单例 / 全局变量** —— FPM 里每个请求都是干净进程，
   `Foo::$currentUser = ...` 下个请求就没了；常驻后它**永远在**，并会串到其他用户的
   请求里。请求态一律走 `Yii::$app->request/response/session/user`（这些每请求重建）。
2. **`exit` / `die`** —— 不是结束请求，是**杀死整个服务器进程**。中止请求用
   `throw new \yii\web\HttpException(...)` 或直接 `return`。
3. **直读超全局**：`$_GET/$_POST/$_SERVER/$_COOKIE/$_FILES/$_SESSION` —— 本包已做
   按请求隔离与填充（`$_FILES` 每 request 重建），但语义以 Yii 组件为准；
   `session_start()`/`setcookie()`/`header()` 等过程式 API 完全禁用。
4. **手动 `new PDO` / `new Redis`** —— 阻塞调用且绕过连接池（并发下会耗尽系统连接）；
   一律走 `Yii::$app->db/redis/cache`（池化 + 协程粘性）。

### 小心（语义与 FPM 不同，需确认）

5. **应用级缓存跨请求共享是特性** —— `Yii::$app->cache` 的数据在下个请求仍在；
   不要把「本请求特有的数据」放进去（那是 session/请求属性的事）。
6. **`register_shutdown_function` 不等于"请求结束"** —— 它在**进程退出**时才触发；
   每请求收尾钩子用 `HttpServer::EVENT_AFTER_STOP` 或监听 `Yii::$app` 的请求事件。
7. **类与代码加载发生在首次使用** —— 修改代码后必须 reload（开发用
   `swoole/start --watch`）；opcache 的时间戳校验在常驻进程里对**已加载的类**无效。
8. **阻塞扩展与长 CPU 循环** —— Swoole hook 覆盖常见阻塞（PDO/Redis/文件/stream/sleep），
   但 C 扩展直连（librdkafka 等）与纯 CPU 密集计算会卡住事件循环——挪队列或独立进程。

### 资产与布局（真实场景教训：一个遗漏就是 405/404）

- **布局必须含标准五件套**：`beginPage()/head()/beginBody()/endBody()/endPage()`——
  资产标签、csrf meta 都在这些钩子里输出；**漏一个**，`Html::a(..., ['data-method' => 'post'])`
  的删除/登出链接会以 GET 发出（yii.js 未加载）撞 VerbFilter 405，且 AssetManager 无法发布。
- **`YiiAsset::register($this)` 必须在布局调用**——`yii.js` 提供 `data-method`/`data-confirm`。
- **AssetManager 的两个路径语义分离**：`basePath`（文件系统，`@app/web/assets`）与
  `baseUrl`（URL，显式 `'/assets'`）。`@web` 为空串时 `'@web/assets'` 解析成 `/assets`
  （根路径）——**显式配置是最可控的写法**。
- **`@bower`/`@npm` 别名**：使用 `yidas/yii2-bower-asset` 时需显式注册
  `'@bower' => '@vendor/yidas/yii2-bower-asset/bower'`（真实资产在包内目录，不在
  `vendor/bower-asset`）。
- 完整可运行的参照：仓库 `examples/blog/`（传统 MVC 后台 + Vue3 前后端分离 + 迁移 +
  热重载开发流）。

### 放心用（已验证原生可用）

- `yii\web\User`（登录/identity 持久化）、`HttpBasicAuth`、CSRF、`UploadedFile`、
  事务（含嵌套 savepoint）、Redis MULTI/PIPELINE、`Query::batch()`、
  迁移与 AR 全套 —— 均有测试钉死（见第五部分）。

---

# 第三部分 · 架构与设计

## 3.1 运行模型

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
                             ├─ request->setSwooleRequest() ← Swoole→Yii2 请求转换（含 $_FILES 填充）
                             ├─ $app->handleRequest()       ← 标准 Yii2 路由/控制器流程
                             ├─ response->finalize()        ← 内存中完成 formatter 管线
                             ├─ SwooleResponseSender::send()
                             └─ finally: RequestStateManager::cleanup()
```

## 3.2 核心难点与设计决策

| 难点 / 决策 | 对策与理由 |
|---|---|
| Yii2 设计给 FPM：请求结束进程清空，全局状态可随意用 | 常驻进程下禁止超全局变量；请求级状态全部协程隔离（§3.4） |
| `$_GET/$_POST/$_SERVER/$_COOKIE/$_SESSION` 全进程共享，协程下会串请求 | `CoroutineRequest` 从 Swoole Request 对象取数；`CoroutineSession` 用 Redis 存储 + 协程内存 |
| 单个 PDO 连接不能被多个协程并发使用 | 连接池 + **协程粘性借用**：每个协程借一条独立连接，事务期间强绑定 |
| 长生命周期对象持有请求状态（request/response/user） | 每请求结束重建这四个组件、归还池连接、flush 日志 |
| 池耗尽的错误路径 | 阻塞等待 + `waitTimeout` 超时抛异常，不无限等待；`poolMaxActive` 是并发硬边界 |
| 泄漏回收机制 | `sweep()`（每请求结束由活跃协程同步执行）而非 `Coroutine::defer`——defer 阶段做 channel IO 在 Windows 构建实测崩溃，且 sweep 确定性更强 |
| 借出登记数据结构 | 普通数组（`spl_object_id` 索引）而非 WeakMap——msys2 构建 WeakMap 跨协程写入不可见且会崩进程（独立脚本实锤） |
| 多进程模型 | **多实例 + SO_REUSEPORT**（server factory 第 4 参），supervisor `numprocs=N` 内核负载均衡；进程内 fork 需重验证进程级状态隔离，不做 |
| 静态文件 | PHP 层 `StaticFileServer`（可执行/敏感扩展黑名单 + dotfile 拦截）——**实测协程 `Http\Server` 完全忽略 `enable_static_handler`**；生产推荐 nginx 直出 |
| 请求体解析 | 显式 pin `http_parse_files/post/cookie = true`——协程 `Http\Server` 的 `http_parse_files` 源码默认 **false**（`swoole_http.h`），不显式配置则上传静默为空 |
| respawn 前置 `PHP_BINARY` | pid 文件存无解释器的脚本调用（入口无 shebang），不前置则 reload 永远失败（实测） |
| console `--host/--port` 用 controller 公共属性 | Yii2 匿名选项只赋值 controller 公共属性，不映射 action 方法参数 |

## 3.3 连接池与协程粘性借用（`Pools/ConnectionPool.php`）

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

- 基于 `Swoole\Coroutine\Channel`，懒创建连接，`maxActive` 封顶；
- 池耗尽时协程阻塞等待 `waitTimeout`，超时抛异常（明确错误路径）；
- 空闲连接可配置健康检查（DB: `SELECT 1`，Redis: `PING`）；
- **子协程借用的连接由 `sweep()` 兜底回收**（借用方协程死亡 → 连接销毁重建）。

## 3.4 请求状态重建（`Runtime/RequestStateManager.php`）

每个请求结束后：

1. 重新 `set('request'/'response'/'session'/'user', 原始定义)`，下个请求拿到全新实例；
2. 遍历所有组件，调用实现了 `release()` 的组件归还池连接；
3. `Yii::getLogger()->flush(true)` —— FPM 的"请求结束自动 flush 日志"在常驻进程不存在，必须手动做。

## 3.5 兼容性开关

所有池化组件都提供 `enableCoroutinePooling`：
`false` 时回退 Yii2 标准行为（共享单连接），便于排障和传统部署。

## 3.6 源码结构（`src/`，命名空间 `Yii2S\Swoole\`）

```
src/
├── Bootstrap.php                    应用引导：自动替换组件类、注册命令、设置 memory_limit
├── Console/SwooleController.php     swoole/start [--watch] / stop / status / reload / check
├── Coroutine/CoroutineContext.php   协程上下文存取封装（协程外自动回退进程内存储）
├── Pools/ConnectionPool.php         通用协程连接池（factory/validator/destroyer 注入）
├── Db/CoroutineDbConnection.php     协程安全 MySQL 连接（连接池 + 粘性借用 + 协程外 detached 直连）
├── Redis/CoroutineRedisConnection.php  协程安全 Redis（原生 phpredis + 池）
├── Cache/CoroutineRedisCache.php    Redis 缓存（兼容 yii\caching\Cache 全 API）
├── Session/CoroutineSession.php     Redis 会话（完全绕开 $_SESSION）
├── Queue/CoroutineRedisQueue.php    Redis 队列（ZSET：延迟投递/重试/死信）
├── Runtime/
│   ├── CoroutineRequest.php         Swoole→Yii2 请求适配（含 cookie 签名验证、上传转换）
│   ├── CoroutineResponse.php        内存 finalize（beforeSend/prepare/afterSend）
│   ├── SwooleResponseSender.php     响应下发（headers/cookies/内容）
│   └── RequestStateManager.php      每请求状态重建 + 统一回收
├── Server/
│   ├── HttpServer.php               服务器生命周期（start/stop/watchdog/信号/flag watcher）
│   ├── RequestDispatcher.php        请求分发（静态文件 / __pool / Yii2）
│   ├── StaticFileServer.php         PHP 层静态文件（开发用，默认关闭）
│   ├── PidFile.php                  pid 文件（JSON + 进程存活探测）
│   └── RequestDispatcherInterface.php
├── Log/CoroutineFileTarget.php      缓冲批量日志（Channel 互斥 + 官方轮转）
├── Session/CoroutineSession.php
└── Console/QueueController.php      queue/serve 命令
```

---

# 第四部分 · 运维与部署

## 4.1 生命周期插槽（定时任务等 Swoole 特性的挂载点）

`HttpServer` 暴露三个 Yii2 风格事件，是所有"长生命周期特性"（定时任务、预热、
指标上报、优雅收尾）的官方挂载点：

| 事件 | 触发时机 | 典型用途 |
|---|---|---|
| `EVENT_BEFORE_START` | 服务器启动前（协程已就绪） | 预加载缓存、启动定时任务协程 |
| `EVENT_AFTER_START` | 监听就绪、开始接受流量 | 健康上报到注册中心、打点 |
| `EVENT_AFTER_STOP` | 优雅停机完成 | 指标落盘、通知下游 |

### 示例：周期任务

```php
use Yii2S\Swoole\Server\HttpServer;
use Swoole\Coroutine;

'swooleHttpServer' => [
    'class' => HttpServer::class,
    'on beforeStart' => function (): void {
        // 每 60 秒执行一次的周期任务：协程循环 + sleep 即是 cron 语义
        Coroutine::create(function (): void {
            while (true) {
                doCleanupWork();
                Coroutine::sleep(60);
            }
        });
    },
    'on afterStop' => function (): void {
        flushMetrics();
    },
],
```

要点：回调运行在服务器协程空间内，可直接创建子协程、借用池连接；
周期任务用「协程 + sleep 循环」实现（不要用 `Timer`——timer 回调里做池借还
语义差且与优雅停机交互复杂）；优雅停机时协程随进程退出，周期任务内部
应保证单轮操作原子性。

> 后续可选增强（未实现，避免过度设计）：声明式 `PeriodicTask` 组件
> （`tasks => ['cleanup' => ['every' => 60, 'handler' => ...]]`）。等出现
> 3 个以上真实周期任务场景再做。

## 4.2 生产部署（nginx + 多实例）

标准架构：**nginx 负责静态文件与 TLS 终结，Swoole 服务器只处理动态请求**。
多实例：`swoole/start` 已启用 `SO_REUSEPORT`，直接启动 N 个实例绑同一端口，
由 supervisor/systemd 托管。

```nginx
server {
    listen 80;
    server_name example.com;

    root /var/www/app/public;
    index index.html;

    location ~* \.(css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|eot|map|json)$ {
        expires 30d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

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
  直连部署（无 nginx）建议显式 `secureProtocolHeaders => []` 关闭代理头信任；
- 静态文件由 nginx 直接返回，`documentRoot` 此时不需要；
- 队列消费独立运行：`php yii queue/serve`，同样用 supervisor 托管。

## 4.3 调试

```bash
# 连接池指标（默认关闭；RequestDispatcher 配置 enablePoolMetricsEndpoint => true 开启）
curl http://127.0.0.1:9501/__pool

# 内置压测器（协程，无需 wrk/ab）
swoole-cli scripts/bench.php --path=/demo/db --concurrency=100 --duration=5
```

## 4.4 压测基准

`scripts/bench.php` 是内置的协程压测器（无外部依赖）。环境：单进程 Swoole HTTP 服务器，
客户端与服务器同机（数字偏保守），keep-alive，预热 1s 不计入。

**Windows**（msys2 swoole-cli）：

| 端点 | 并发 | QPS | p50 | p99 | 说明 |
|---|---|---|---|---|---|
| `/`（纯框架） | 100 | 8132 | 9.7ms | 19.9ms | 路由+JSON 序列化 |
| `/demo/db`（2 条 SQL） | 100 | 2384 | 33.4ms | 43.7ms | 连接池借还 |
| `/demo/redis`（3 命令） | 100 | 2851 | 27.9ms | 33.5ms | 池化 Redis |

**Linux**（WSL2 Ubuntu 26.04 + 原生 swoole-cli，同一负载生成器）：

| 端点 | 并发 | QPS | p50 | p95 | p99 |
|---|---|---|---|---|---|
| `/`（纯框架） | 100 | **13706** | 7.2ms | 9.6ms | 11.2ms |
| `/demo/db`（2 条 SQL） | 100 | **2829** | 35.3ms | 39.0ms | 42.0ms |
| `/demo/redis`（3 命令） | 100 | **5823** | — | — | — |

Linux 纯框架吞吐高出 69%、Redis 高 104%（原生 epoll + 无 Windows 层中转），
**"生产环境用 Linux"得到数据支撑**。压测后 `/__pool` 显示 db 池
total_borrowed=14144、total_timeouts=0、active=0/idle=20——**零泄漏**。

**连接池是硬容量边界**：`/demo/concurrent`（10 并行 SQL）在并发 10 时部分查询
排队到 `waitTimeout`（demo 把超时吞成 error payload 返回 200）——业务峰值需要的
并发连接数不要超过 `poolMaxActive`，超容量即排队/超时。

压测还抓出两个真实 bug（均已修复 + 回归测试）：**子协程连接泄漏**（借出登记
`spl_object_id` 索引 + `sweep()` 回收）与 **msys2 WeakMap 跨协程不可见**（改普通数组）。

---

# 第五部分 · 测试与验证

## 5.1 测试体系

98 tests / 403 assertions，覆盖九大目录：连接池并发（借还配对/超时/sweep）、
请求适配（头/cookie 签名/上传三种形状）、服务器集成（**真实 socket 交互，非 mock**：
CSRF 全流程、表单三件套、静态文件、优雅停机、watch 热重载）、DB（事务含嵌套
savepoint、并发 AR + 并发事务、Query::batch、协程外 detached 直连）、Redis
（MULTI/PIPELINE）、Cache（七类型循环 + TTL）、Session（登录→regenerateID→
跨请求恢复→logout）、Queue（延迟/重试/死信/空队列）、Log（缓冲/轮转窗口）。

## 5.2 验证矩阵

| 运行时 | 测试 | 用途 |
|---|---|---|
| WSL Ubuntu + swoole-cli 6.2.2 | 98/403 ✅（连跑多次稳定） | 端到端（start/status/reload respawn/SIGTERM）+ 基准 |
| WSL Ubuntu + pecl PHP 8.5 / swoole 6.2.4 | 98/403 ✅ | **CI 同源标准构建**（`http_parse_files` 修复的实战检验） |
| GitHub Actions（待首跑） | pecl 矩阵 PHP 8.1–8.4 | 云端矩阵 |
| Windows 预构建机 | phpstan / cs-fixer（纯 PHP 工具链） | 静态分析 |

独立外部 AI 评审两轮（见附录 B）：第一轮 6 缺陷全部修复，第二轮增量 2 项
（e2e 脚本 pid 路径漂移、`.gitattributes` 根治 CRLF）已验证落地。

## 5.3 本地验证入口（Linux/WSL）

```bash
composer test    # PHPUnit（需要本地 MySQL/Redis；tests/console、tests/web 已含在套件）
composer static  # PHPStan level 5
composer cs      # php-cs-fixer（与官方 yii2 同规则）
bash scripts/wsl-e2e.sh     # 端到端：start/status/reload respawn/SIGTERM
bash scripts/wsl-bench.sh   # 基准：13706 QPS（纯框架）零错误
```

### example 自举说明（隐性依赖）

`example/` 的应用代码在 `app/controllers/`（命名空间 `app\`），它**不是独立可搬运
的**：`app\` 命名空间依赖**根 composer.json 的 `autoload-dev.psr-4`**
（`"app\\": "app/"`）。把 example 单独复制出去会因找不到控制器而 404。
发行包（dist）通过 `.gitattributes` 的 `export-ignore` 排除 example/tests/doc，
不受影响。

---

# 第六部分 · 扩展与路线图

## 6.1 生命周期插槽（定时任务等 Swoole 特性的挂载点）

`HttpServer` 暴露三个 Yii2 风格事件，是所有"长生命周期特性"（定时任务、预热、
指标上报、优雅收尾）的官方挂载点：

| 事件 | 触发时机 | 典型用途 |
|---|---|---|
| `EVENT_BEFORE_START` | 服务器启动前（协程已就绪） | 预加载缓存、启动定时任务协程 |
| `EVENT_AFTER_START` | 监听就绪、开始接受流量 | 健康上报到注册中心、打点 |
| `EVENT_AFTER_STOP` | 优雅停机完成 | 指标落盘、通知下游 |

### 示例：周期任务

```php
use Yii2S\Swoole\Server\HttpServer;
use Swoole\Coroutine;

'swooleHttpServer' => [
    'class' => HttpServer::class,
    'on beforeStart' => function (): void {
        // 每 60 秒执行一次的周期任务：协程循环 + sleep 即是 cron 语义
        Coroutine::create(function (): void {
            while (true) {
                doCleanupWork();
                Coroutine::sleep(60);
            }
        });
    },
    'on afterStop' => function (): void {
        flushMetrics();
    },
],
```

要点：回调运行在服务器协程空间内，可直接创建子协程、借用池连接；
周期任务用「协程 + sleep 循环」实现（不要用 `Timer`——timer 回调里做池借还
语义差且与优雅停机交互复杂）；优雅停机时协程随进程退出，周期任务内部
应保证单轮操作原子性。

> 后续可选增强（未实现，避免过度设计）：声明式 `PeriodicTask` 组件
> （`tasks => ['cleanup' => ['every' => 60, 'handler' => ...]]`）。等出现
> 3 个以上真实周期任务场景再做。

## 6.2 连接池扩展规划

现有 `Pools\ConnectionPool` 是**通用对象池**（factory / validator / destroyer
三个注入点），新增一种连接池 = 写一个组件类把特定客户端接进池，无需改池本体。

| 目标 | 驱动与协程化路径 | 可行性 | 优先级 | 备注 |
|---|---|---|---|---|
| **PostgreSQL** | pdo_pgsql → Swoole hook（`SWOOLE_HOOK_PDO_PGSQL` 显式启用） | 中 | P1 | hook 常量已存在（php_swoole_coroutine.h）；需要把 hook flags 组合进 HttpServer |
| **RabbitMQ (AMQP)** | php-amqplib（socket/stream）→ `HOOK_SOCKETS`/`HOOK_TCP` 覆盖 | **待实测** | P2 | 消费者模式（长连接 push）与池语义冲突：连接要独占消费——适合「每消费者一连接」而非池化；生产者连接可池化 |
| **Kafka** | rdkafka（librdkafka C 扩展）→ **无法 hook**（C 层 socket） | 低 | P3 | 除非 librdkafka 提供 non-blocking API；建议保持独立进程消费，不进协程 |
| **Memcached** | pecl memcached → 需验证 hook 覆盖 | 待实测 | P3 | Redis 已覆盖多数场景 |
| **Elasticsearch** | HTTP REST（Guzzle/cURL） | 中 | P3 | `HOOK_CURL`/`HOOK_NATIVE_CURL` 可协程化，但 6.x 有已知边界（HOOK_ALL 默认排除） |

### 通用接入模式

```php
$pool = new ConnectionPool(
    factory: fn () => new PDO($dsn, $user, $pass),   // hook 协程化
    validator: fn ($pdo) => $pdo->query('SELECT 1') !== false,
    destroyer: fn ($pdo) => $pdo = null,
    maxActive: 20,
);
```

### 排期建议

1. **P1 PostgreSQL**：加 `SWOOLE_HOOK_PDO_PGSQL` 的 hook 组合选项（文档 + 测试），
   实测 pdo_pgsql 在 hook 下的事务/预处理语句行为。
2. **P2 RabbitMQ**：分「生产者池化」与「消费者独立连接」两形态，先写探针验证
   php-amqplib 在 hook 下的心跳与断线行为。
3. **P3 Kafka**：不建议纳入协程进程，文档给出独立消费者部署方案即可。

## 6.3 未实现清单与已知边界

**主动取舍（非缺陷）**：

- 需要 `urlManager.enablePrettyUrl = true`（`?r=` 路由依赖 `$_GET`，协程不安全）
- 单进程模型适合 API 服务；CPU 密集任务应交给独立进程
- Queue 未兼容 `yii\queue\Queue` 接口（避免依赖；列为后续增强）
- MySQL 主从/读写分离未透传（池化模式下需真实主从环境验证，不假装支持）
- 协程 HttpClient 组件未内置（Swoole 原生 `Coroutine\Http\Client` 可直接用）

---

# 第七部分 · 踩坑实录（29 条）

> 每条都是可复现的实证记录，后续维护者遇到同类现象可直接对号入座。

1. **Yii2 默认 `Session` 完全不适用于协程环境** —— 原生 `session_start()` 输出
   `Set-Cookie` 头且依赖 `$_SESSION` 全进程共享。必须用 `CoroutineSession`（Redis 存储）。
2. **`Coroutine\Http\Server` 不支持 `daemonize`** —— 守护化用 pid 文件 + 停服标志文件
   + `swoole/stop` 实现跨进程停机。
3. **协程 `Http\Server` 完全忽略 `enable_static_handler`** —— 静态文件必须 PHP 层
   （`StaticFileServer`）或 nginx。
4. **子协程内禁止创建 `Swoole\Coroutine\Http\Server`** —— 端口绑定竞态；服务器
   必须独占协程（`HttpServer::start()` 内部处理）。
5. **Windows msys2 构建的限制** —— exec/popen 受限（respawn 降级提示）、Swoole
   `Timer` 不可用；**该平台已从目标平台移除**（生产 Linux / 开发 macOS / Windows 仅预构建）。
6. **`gracefulTimeout` 内在途请求排空是"有界等待"** —— 超时后强制 shutdown，
   慢请求会被断连；`stopwaitsecs` 要大于 `gracefulTimeout`。
7. **每请求状态重建的组件清单**（request/response/session/user）——漏一个就是
   跨请求污染；新增请求级组件时同步 `RequestStateManager::REQUEST_SCOPED_COMPONENTS`。
8. **池化组件的 `release()` 只能归还当前协程粘性连接** —— 子协程借用由 `sweep()` 兜底。
9. **`enableCoroutinePooling = false` 是排障利器** —— 池化异常时先关池化定位是池的问题还是业务问题。
10. **Yii2 `ErrorHandler` 会把 warning 升级成 ErrorException** —— 常驻进程里"无关紧要"
    的 notice/warning 都会在下个请求爆发；第三方库的噪音要显式 try/catch 或关闭该库的警告路径。
11. **日志缓冲必须加互斥锁** —— 每请求 flush 并发读改写 `messages`（Channel 互斥锁）；
    **锁的懒初始化必须留在协程内**（协程外 push Channel 令牌静默失败 → 死锁，实测）。
12. **`$_FILES` 要按请求填充并 reset `UploadedFile` 静态缓存** —— 官方 `UploadedFile::loadFiles()`
    直读 `$_FILES` 超全局；静态缓存跨请求泄漏会串文件。
13. **Cookie 必须走签名验证通道** —— "官方静态 API 依赖进程态"的模式；协程组件覆写时
    逐条对照官方实现（`loadCookies` 的 validateData 不可省）。
14. **Swoole `cookie()` 自动 urlencode，`rawcookie()` 不编** —— 与 PHP `setcookie()` 对齐；
    手动再编码 = 双重编码（签名串被破坏）。
15. **phpredis `SET` 选项必须关联键** —— `['NX', 'EX', 60]` 的 EX 被静默忽略；
    必须 `['NX', 'EX' => 60]`。
16. **phpredis `PING` 返回 `bool(true)`** 而非字符串——判断兼容两种风格。
17. **`Coroutine::sleep` 协程外抛 `Swoole\Error`** —— console 主线程用 `usleep` 双路 helper。
18. **msys2 构建 WeakMap 跨协程不可见** —— 跨协程共享状态用普通数组。
19. **`RecursiveDirectoryIterator` 并发扫描要全容错** —— 与 dev 工具写文件竞态，
    stat warning 会被 Yii error handler 升级为请求内 fatal（HeadersAlreadySent 连锁）。
20. **phpstan 对无副作用 getter 的轮询循环报恒真条件** —— `@phpstan-impure` 是官方解。
21. **phpunit.xml 的 testsuite 是手工目录列表** —— 新增测试目录要同步加进去，
    否则测试"存在但从不运行"（本次 ResponseAndUrlTest 漏网实例）。
22. **PowerShell 内联调用 WSL 命令是转义地狱** —— `$(...)`/`$var` 被展开、`curl` 是
    `Invoke-WebRequest` 别名；一律脚本文件 + 输出落盘再读。
23. **WSL mirrored 网络模式** —— Windows 侧服务可被 WSL 以 `127.0.0.1` 直连
    （`.wslconfig` 设 `networkingMode=mirrored`）。
24. **nohup 后台进程随 wsl.exe 会话退出被杀** —— 长任务前台执行或用宿主侧调度。
25. **pid 文件默认路径随配置变化** —— `sys_get_temp_dir()/yii2s-swoole-{host}-{port}.pid`；
    外部脚本不要硬编码 example 的旧路径（曾致 e2e SIGTERM 步骤必失败）。
26. **外部评审的修复要"逐项 git 考古"甄别** —— 第二轮评审把第一轮已修复项当新发现
    重报（工作线基于旧快照）；但增量部分仍抓到真问题（脚本与配置漂移、.gitattributes）。
27. **自研 error handler 会覆盖用户 handler，fatal 细节被生产渲染吞掉** —— 诊断时用
    `register_shutdown_function` + `error_get_last()` 拿真实错误（本包排查实例）。
28. **测试访问组件私有属性会以"协程内 uncaught 异常"形式挂死整个套件** ——
    `$server->isRunning` vs `isRunning()`——症状是 Coroutine\run 不退出，先查属性访问。
29. **Swoole 构建间的默认值差异是隐性炸弹**（`http_parse_files` 一例）——
    依赖外部默认值的行为一律显式声明。
30. **path repo 的 symlink 会被依赖操作悄悄弄丢** —— `composer update --with-all-dependencies`
    重建 vendor 后 path 包目录可能变空，且空目录让 composer 误判"已安装"而跳过重装；
    `rm -rf vendor/<vendor> && composer install` 重建。开发期用 `--watch` 每日确认包更新生效。
31. **`pkill -f` 会匹配自己的命令行** —— 模式里用 `[s]` 正则技巧（`pkill -f "[s]woole-cli"`）
    防自杀；验证脚本**必须**以停服结尾（或 setsid 脱离），否则常驻服务器让 WSL 会话永不返回。
32. **WSL 的 /tmp 随实例重启清空** —— 日志/pid 落 `$HOME`；PowerShell 写文件默认带 UTF-8 BOM
    且多轮写入会交错损坏配置——重要配置用单一来源重写并 `php -l` + 键断言验证。

---

# 附录 A · 独立评审指南（精简版）

写给未参与开发的 AI / 工程师（完整版见 git 历史，原 doc/REVIEW.md）。

### 声称事实清单（欢迎证伪）

| # | 声称 | 验证方法 |
|---|---|---|
| C1 | 请求状态严格隔离，并发请求互不污染 | `tests/runtime/`、`tests/server/HttpServerTest.php`；或压测中并发请求带不同参数验证响应不串 |
| C2 | 连接池并发安全，借还配对，子协程泄漏由 sweep() 兜底 | `tests/pools/ConnectionPoolTest.php`；压测后 `/__pool` 看 active 归 0 |
| C3 | 静态文件防路径穿越与敏感文件泄露 | `tests/server/HttpServerStaticTest.php`；`curl --path-as-is /../config/web.php`（应 404）、`/secret.php`（应 404） |
| C4 | CSRF 完整可用（含 SameSite 下发） | `tests/server/HttpServerCsrfTest.php`（签发→无 token 400→带 token 200） |
| C5 | Basic Auth 头解析正确（密码含冒号/空密码/scheme 大小写） | `tests/runtime/HttpBasicAuthTest.php` |
| C6 | Redis MULTI/DISCARD/PIPELINE 天然工作 | `tests/redis/RedisMultiTest.php` |
| C7 | 优雅停机等待在途请求排空（有界） | `tests/server/HttpServerGracefulStopTest.php` |
| C8 | reload 在 Linux 上真实 respawn + watch 热重载 | `scripts/wsl-e2e.sh`（pid 变化、端口重绑、服务恢复） |
| C9 | 数据库事务在协程下正常（含嵌套 savepoint） | `tests/db/`；`curl /demo/transaction` |
| C10 | 队列：延迟投递、失败重试、超限死信 | `tests/queue/CoroutineRedisQueueTest.php` |
| C11 | 队列空时 run(N) 立即返回；`queue/serve` 空队列不崩 | `tests/queue/`（含协程外场景） |
| C12 | Linux 基准 13706 QPS，池零泄漏 | `scripts/wsl-bench.sh` + `/__pool`（total_timeouts=0, active=0） |
| C13 | Cookie 签名验证（入站验证 + 出站签名） | `tests/runtime/CoroutineRequestTest.php`（篡改丢弃）+ `HttpServerCsrfTest`（往返） |
| C14 | 上传三种形状归一化 + `$_FILES` 官方 API 通路 | `tests/server/HttpServerFormTest.php`（sha1 字节级验证） |

### 建议切入顺序

**P0 正确性**：`ConnectionPool::borrow()` 并发语义 → `RequestStateManager::cleanup()`
异常路径 → `CoroutineDbConnection` 粘性与事务 → **cookie/上传的官方 API 对齐**。
**P1 安全**：`StaticFileServer` → `CoroutineRequest` 代理头/cookie 验证。
**P2 测试质量**：集成测试真实性、时序测试 flaky（已知偶发，重跑即绿）。
**P3 一致性**：README 配置说明 vs 实现、坑列表逐条。

---

# 附录 B · 安全评审记录（两轮）

### 第一轮——6 缺陷全部修复

| # | 缺陷 | 严重度 | 修复 |
|---|---|---|---|
| P0-1 | `queue/serve` 空队列崩溃（协程外调 `Coroutine::sleep` 抛 `Swoole\Error`） | P0 | `idle()` 双路 helper（协程内 sleep / 协程外 usleep） |
| P0-2 | `Cache::add()` TTL 静默丢失（phpredis SET 选项必须 `'EX' => 60` 关联键） | P0 | 关联键 + TTL 区间回归测试 |
| P1-1 | Cookie 丢失 `SameSite`（Swoole `cookie()` 第 8 参未转发） | P1 | 转发 + expire 归一化扩展 |
| P1-2 | `getIsSecureConnection` 硬编码信任 `X-Forwarded-Proto` | P1 | 对齐 `secureProtocolHeaders` 配置 |
| P1-3 | 静态文件可泄露 `.php/.env`（黑名单缺失） | P1 | 可执行/敏感扩展黑名单 + dotfile 拦截 |
| P2-1 | 日志缓冲并发竞态 | P2 | Channel 互斥锁（懒初始化必须留在协程内——挪到 `init()` 会在协程外 push 令牌静默失败导致死锁，实测后回滚并记录） |

### 第二轮——甄别结论 + 2 项真增量

- 6 个"源码缺陷"经 git 考古确认**全部为第一轮已修复项的复述**（无需动作）；
- **真增量（已落地）**：`wsl-e2e.sh` pid 路径漂移（example 配置演进后脚本未同步，
  SIGTERM 步骤必失败——含空值守卫修复）；`.gitattributes` 强制 `*.sh` LF
  （根治 CRLF 污染）。
- 第二轮在当前 HEAD 的独立验证与本方结果吻合：87→98 tests 全绿、
  e2e respawn（pid 变化）、基准 13887/2940/5871 QPS 零错误、池零泄漏。

### 评审过但判定无需修复的点

`StaticFileServer` 路径穿越（realpath 前缀已拦截）、连接池 `size` 计数与 `sweep()`
竞态、`resolve()` 参数合并顺序（与 Yii2 一致）、会话固定（`regenerateID()` 已覆盖）、
`flushValues()` SCAN 防御（executeCommand 已转异常）。
