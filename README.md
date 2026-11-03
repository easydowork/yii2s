# Yii2S Swoole — Yii2 协程加速器

让 Yii2 应用运行在 **Swoole 6 常驻内存协程 HTTP 服务器**上：数据库/Redis 连接池化复用、
每请求状态隔离、优雅停机、守护化命令、内置压测器。业务代码基本不用改——换掉配置里的
组件类即可。

```json
{"app":"yii2s-example","engine":"swoole-coroutine","php":"8.5.4","swoole":"6.2.4","pid":757,"cid":5}
```

基准数据（Linux，WSL2 Ubuntu + swoole-cli，单进程，keep-alive）：纯框架 **13706 QPS**（p50 7.2ms）/
带 DB 查询 **2829 QPS** / 带 Redis **5823 QPS**，连接池 14144 次借用零超时零泄漏，
详见 [`doc/DEVELOPMENT.md`](doc/DEVELOPMENT.md) 的压测章节。

---

## 环境要求

| 依赖 | 版本 |
|---|---|
| PHP | >= 8.2（实测 8.4 / 8.5；Swoole 6 的 PHP 下限即 8.2） |
| ext-swoole | ^6.0（协程 Http Server + 连接池） |
| yiisoft/yii2 | ^2.0 |
| ext-redis | 原生 phpredis，使用 `redis`/`cache`/`session`/`queue` 组件时必需（`yii2-redis` 是纯 PHP 客户端，不提供 `\Redis`） |
| MySQL / Redis | 可选，按使用的组件而定 |

> **目标平台是 Linux**（生产环境）；macOS 可开发（pecl 编译安装即可）。
> 两种安装方式：标准 `pecl install swoole`，或
> [swoole-cli](https://www.swoole.com/page/download)（自带全部扩展的独立二进制，
> 免配置，Linux 上开箱即用）。不支持 Windows 原生运行。

## 安装

```bash
composer require easydowork/yii2s
```

发布 Packagist 前可通过 path 仓库引入：

```json
"repositories": [
    {"type": "path", "url": "../yii2s", "options": {"symlink": true}}
]
```

## 5 分钟跑起来（example 目录）

```bash
# 1. 初始化演示数据库（建库建表插数据，需要本地 MySQL）
php example/init-db.php

# 2. 启动服务器
php example/yii swoole/start

# 3. 访问
curl http://127.0.0.1:9501/
curl http://127.0.0.1:9501/demo/db
```

## 接入现有 Yii2 应用

### 1. HTTP 服务器组件 + Bootstrap

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

适合需要逐组件把关或覆写的场景——直接写协程类即可：

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
    'handler' => function (array $payload): void {
        // 处理任务
    },
],
```

`request/response/session/user` 由框架每请求自动重建，原版 `yii\web\User`、
`yii\filters\auth\HttpBasicAuth` 等直接可用。Redis 的 MULTI/PIPELINE、
DB 的事务也都原生支持（连接在协程内粘性借用）。

## 极简接口快速通道（raw 路由）

健康检查、指标、极简只读接口这类请求，可以注册 **raw 路由**：命中后**不创建 Yii 的
request/response**，直接写回 Swoole 响应。同一台机器、同一个 `ab` 客户端实测：

| 路径 | QPS（单进程） |
|---|---|
| raw 路由 `/ping` | **55,000 – 62,000** |
| 完整 Yii 路由 `/` | 14,200 – 15,000 |
| 裸 Swoole（无框架） | 58,000 – 63,000 |

```php
// config/web.php（需要显式配置 swooleHttpServer.dispatcher）
'swooleHttpServer' => [
    'class' => \Yii2S\Swoole\Server\HttpServer::class,
    'documentRoot' => '@app/web',
    'dispatcher' => (new \Yii2S\Swoole\Server\RequestDispatcher(__DIR__ . '/web.php', [
        'enablePoolMetricsEndpoint' => true,
    ]))
        ->addRawRoute('GET', '/ping', static fn (): string => 'pong')
        ->addRawRoute('GET', '/health', static fn (): array => ['ok' => true]),
],
```

- 返回值：`string` → `text/plain`；`array` → `application/json`；`null` → handler 自己
  操作 Swoole response（签名 `(Request $request, Response $response)`）。
- 精确匹配 `METHOD + path`，query 不参与匹配；方法不匹配会**回落**到 Yii 路由。
- **绕过 Yii**：不经过路由、session、CSRF、用户认证。只放公开/无状态端点；
  需要鉴权、限流或审计的接口不要用 raw 路由。
- raw 路由、普通 Yii 路由、静态文件可以共存于同一个 dispatcher。

## 控制台命令

```bash
php yii swoole/start [--host=] [--port=] [--watch]  # 启动（阻塞），写 pid 文件
php yii swoole/status                               # 查看运行状态
php yii swoole/stop                                 # 优雅停机（在途请求排空后退出）
php yii swoole/reload                               # 停机 + 尽力重新拉起（生产建议交给 supervisor）
php yii swoole/check                                # 配置体检（Application 隔离、cookie key、prettyUrl、db/redis 探活）
php yii queue/serve [--limit=N]                     # 队列消费 worker（无限轮询）
```

### 开发热重载

```bash
php yii swoole/start --watch
```

修改 `@app` 下的 PHP 文件后，服务器**自动优雅重启**并加载新代码（约 1 秒内完成，
在途请求不中断）。轮询 mtime 实现、零外部依赖，Linux/macOS 行为一致。
可调参数：`watchDirs`（默认 `@app`）、`watchInterval`（默认 0.5s）、
`watchExcludes`（默认 runtime/vendor 等）。**开发专用**——重启进程，生产禁止。

定时任务等长生命周期特性通过服务器生命周期事件插槽挂载（`on beforeStart` +
协程循环），示例见 [`doc/DEVELOPMENT.md`](doc/DEVELOPMENT.md) 第四部分 4.1。

## 生产部署（Linux）

标准架构：**nginx 直接服务静态文件 + TLS，Swoole 只处理动态请求**。

多核部署用 **多端口 + nginx upstream**（每个实例一个端口，各自独立的 pid 文件）：

```ini
; supervisor: 4 个实例分别监听 9501-9504
[program:yii2s]
command=swoole-cli /app/yii swoole/start --host=127.0.0.1 --port=950%(process_num)02d
process_name=%(program_name)s_%(process_num)02d
numprocs=4
autorestart=true
stopsignal=TERM
stopwaitsecs=25                ; 必须大于 gracefulTimeout(10s)，留出排空时间
```

> 同端口多实例（`SO_REUSEPORT`，已在 server factory 中启用）**可用**，但每个实例
> 必须有独立的 pid 文件，否则第二个进程会被 `swoole/start` 的防重复启动守卫拒绝。
> 示例配置已支持 `SWOOLE_PID_FILE`：
>
> ```ini
> command=swoole-cli /app/yii swoole/start --host=127.0.0.1 --port=9501
> environment=SWOOLE_PID_FILE="/app/runtime/swoole-%(process_num)02d.pid"
> numprocs=4
> ```
>
> `swoole/stop`/`status`/`reload` 是按单个 pid 文件操作的，这种模式由 supervisor
> 负责生命周期；想直接用控制台命令管多个实例，用上面的多端口 + upstream。

nginx upstream、真实客户端 IP（`trustedProxies`）、超时、上传大小等生产清单见
[`doc/DEVELOPMENT.md`](doc/DEVELOPMENT.md) 部署章节。

## 调试

```bash
# 连接池指标（默认关闭；RequestDispatcher 配置 enablePoolMetricsEndpoint => true 开启）
curl http://127.0.0.1:9501/__pool

# 内置压测器（协程，无需 wrk/ab）
swoole-cli scripts/bench.php --path=/demo/db --concurrency=100 --duration=5
```

## 架构一览

```
请求 → Swoole 协程 Http\Server（单进程多协程；多实例 = 多端口 + nginx upstream）
     → RequestDispatcher（静态文件 / /__pool / Yii2）
     → 常驻 Yii2 Application（进程内只创建一次，CoroutineApplication）
        ├─ request/response/session/user：每协程独立实例（并发请求互不可见）
        ├─ db/redis：连接池 borrow/return（协程粘性，协程结束自动 sweep 回收）
        └─ 结束时：RequestStateManager 统一 release + 池 sweep + 日志 flush
```

设计原则：**状态隔离做在调度层，上层 Yii2 组件尽量不改**——这也是 CSRF、Basic Auth、
事务、MULTI 无需适配就能用的原因。

完整的**接入 → 启动 → 单请求 → 停机**流程图、组件替换对照表和生产拓扑见
[`doc/DEVELOPMENT.md`](doc/DEVELOPMENT.md) 第 3.1.1 节；设计思路与踩坑记录在同文档其余章节。

## 已知限制

- 需要 `urlManager.enablePrettyUrl = true`（`?r=` 路由依赖 `$_GET`，协程不安全）
- 协程版 `Http\Server` 不支持 Swoole 原生 `enable_static_handler`，静态文件走 PHP 层
  （`documentRoot`，开发用）或交给 nginx（生产推荐）
- 不要在常驻进程中使用 `$_SESSION`/`header()`/`setcookie()` 等超全局/过程式 API，
  一律走 `Yii::$app->request/response/session`
- 自定义 `Application` 类必须继承 `Yii2S\Swoole\Runtime\CoroutineApplication`，否则
  request/response/session/user 会在并发请求间共享（默认 `yii\web\Application` 由
  调度器自动换成该子类，无需改动）
- 子协程（`Coroutine::create()`）**不继承**协程上下文：在子协程里访问
  `Yii::$app->request/session/user` 会得到该请求之外的空实例。并发子任务请先在
  父协程取出所需数据再传入（DB/Redis 连接池本身不受影响）
- 上传有两种写法，按是否需要落盘选择：
  - `yii\web\UploadedFile`（官方 API）能读，但它的 `saveAs()` 用 `move_uploaded_file()`，
    Swoole 自己写的临时文件不在 PHP 的上传登记表里，**默认参数下必然返回 false**；
    而且它读进程级 `$_FILES`，并发请求下可能串文件。
  - 要保存文件请用 `Yii2S\Swoole\Runtime\CoroutineUploadedFile`（API 完全一致：
    `getInstance()/getInstances()/getInstanceByName()/saveAs()`），它从当前协程的
    请求取文件，不碰 `$_FILES`：

    ```php
    use Yii2S\Swoole\Runtime\CoroutineUploadedFile as UploadedFile;

    $file = UploadedFile::getInstance($model, 'avatar');
    if ($file !== null && $file->saveAs('@app/web/uploads/' . $file->name)) {
        // 已落盘，临时文件同时被移走
    }
    ```

    仍然建议在动作入口、任何协程让出之前取走 `UploadedFile`（协程版本已无全局状态，
    这条只是让数据更早绑定到当前请求）。

## 开发与测试

目标平台 Linux；测试可在 Linux/WSL2 与 macOS（swoole-cli）上完整运行，
全量 167 tests / 741 assertions（2 skipped）全绿（macOS + swoole-cli 6.2.0 /
PHP 8.4 实测；CI 同源的 pecl 构建跑同一套用例）。
其中 `tests/compat/` 是按功能点组织的迁移兼容性套件（路由、请求、响应格式、
Cookie、CSRF、会话、登录、Flash、上传、下载、错误处理、常驻服务、并发隔离），
可按域单独运行：`swoole-cli vendor/bin/phpunit --no-coverage tests/compat`。

```bash
composer test    # PHPUnit（需要本地 MySQL/Redis）
composer static  # PHPStan level 5
composer cs      # php-cs-fixer（与官方 yii2 同规则）

# 进程级端到端（start/status/reload 真实 respawn/SIGTERM）与基准：
# 两个脚本都支持 macOS/Linux，可用 CLI/PORT/APP_DIR/CONCURRENCY/DURATION 覆盖
bash scripts/wsl-e2e.sh
bash scripts/wsl-bench.sh   # 本机基线见下方说明

# macOS + swoole-cli 6.2.0 / PHP 8.4，50 并发 3s，0 错误、池零超时：
#   /            8148 QPS (p50 4.2ms)
#   /demo/db     3330 QPS (p50 10.0ms)
#   /demo/redis  3616 QPS (p50 9.0ms)
```

标准 pecl 构建的安装与复现步骤见 [`doc/DEVELOPMENT.md`](doc/DEVELOPMENT.md) 验证状态一节。

## License

MIT
