# Yii2S Swoole — Yii2 协程加速器

让 Yii2 应用运行在 **Swoole 6 常驻内存协程 HTTP 服务器**上：数据库/Redis 连接池化复用、
每请求状态隔离、优雅停机、守护化命令、内置压测器。业务代码基本不用改——换掉配置里的
组件类即可。

```json
{"app":"yii2s-example","engine":"swoole-coroutine","php":"8.4.14","swoole":"6.2.2","pid":707,"cid":4}
```

基准数据（单进程，本机，keep-alive）：纯框架 **8132 QPS** / 带 DB 查询 **2384 QPS** /
带 Redis **2851 QPS**，详见 [`doc/DEVELOPMENT.md`](doc/DEVELOPMENT.md) 的压测章节。

---

## 环境要求

| 依赖 | 版本 |
|---|---|
| PHP | >= 8.1（实测 8.4） |
| ext-swoole | ^6.0（协程 Http Server + 连接池） |
| yiisoft/yii2 | ^2.0 |
| MySQL / Redis | 可选，按使用的组件而定 |

> 推荐 [swoole-cli](https://www.swoole.com/page/download)（自带全部扩展的独立二进制），
> Windows 开发用 msys2 版。生产环境推荐 Linux。

## 安装

```bash
composer require yii2s/yii2-swoole
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

## 控制台命令

```bash
php yii swoole/start [--host=] [--port=]   # 启动（阻塞），写 pid 文件
php yii swoole/status                      # 查看运行状态
php yii swoole/stop                        # 优雅停机（Windows 走停服标志文件）
php yii swoole/reload                      # 停机 + 尽力重新拉起（Linux 建议交给 supervisor）
php yii queue/serve [--limit=N]            # 队列消费 worker（无限轮询）
```

## 生产部署（Linux）

标准架构：**nginx 直接服务静态文件 + TLS，Swoole 只处理动态请求**；
多实例利用多核（`SO_REUSEPORT` 已启用，内核做负载均衡）：

```ini
# supervisor: numprocs=4, 4 实例共享 9501 端口
command=swoole-cli /app/yii swoole/start --host=127.0.0.1 --port=9501
numprocs=4
autorestart=true
stopsignal=TERM
```

nginx 配置、`X-Forwarded-*` 说明见 [`doc/DEVELOPMENT.md`](doc/DEVELOPMENT.md) 部署章节。

## 调试

```bash
# 连接池指标（默认关闭；RequestDispatcher 配置 enablePoolMetricsEndpoint => true 开启）
curl http://127.0.0.1:9501/__pool

# 内置压测器（协程，无需 wrk/ab）
swoole-cli scripts/bench.php --path=/demo/db --concurrency=100 --duration=5
```

## 架构一览

```
请求 → Swoole 协程 Http\Server（单进程多协程，SO_REUSEPORT 多实例）
     → RequestDispatcher（静态文件 / /__pool / Yii2）
     → 常驻 Yii2 Application（进程内只创建一次）
        ├─ request/response/session/user：每请求重建（无跨请求状态）
        ├─ db/redis：连接池 borrow/return（协程粘性，协程结束自动 sweep 回收）
        └─ 结束时：RequestStateManager 统一 release + 池 sweep + 日志 flush
```

设计原则：**状态隔离做在调度层，上层 Yii2 组件尽量不改**——这也是 CSRF、Basic Auth、
事务、MULTI 无需适配就能用的原因。完整设计思路与踩坑记录见
[`doc/DEVELOPMENT.md`](doc/DEVELOPMENT.md)。

## 已知限制

- 需要 `urlManager.enablePrettyUrl = true`（`?r=` 路由依赖 `$_GET`，协程不安全）
- 协程版 `Http\Server` 不支持 Swoole 原生 `enable_static_handler`，静态文件走 PHP 层
  （`documentRoot`，开发用）或交给 nginx（生产推荐）
- Windows 的 msys2/cygwin 构建下 exec/popen 受限（`reload` 自动拉起会降级为提示）；
  Windows 仅供开发，生产用 Linux
- 不要在常驻进程中使用 `$_SESSION`/`header()`/`setcookie()` 等超全局/过程式 API，
  一律走 `Yii::$app->request/response/session`

## 开发与测试

```bash
composer test    # PHPUnit（需要本地 MySQL/Redis）
composer static  # PHPStan level 5
composer cs      # php-cs-fixer（与官方 yii2 同规则）
swoole-cli scripts/ci-local.ps1   # 本地一键 CI（Windows）
```

## License

MIT
