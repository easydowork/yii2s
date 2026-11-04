# 组件清单与 API 参考

本文档按命名空间列出插件的所有组件：职责、关键配置项、用法。默认命名空间前缀是
`Yii2S\Swoole\`。

---

## 服务器 `Server\`

### `HttpServer`

多进程 `Swoole\Http\Server` 封装（`SWOOLE_PROCESS`，`worker_num = CPU 核数`）。
负责生命周期、优雅停机、watch 热重载、pid 文件、连接池 keeper、prewarm。

关键配置：`host`、`port`、`pidFile`、`documentRoot`、`prewarmConnections`、
`poolMaintenanceInterval`、`gracefulTimeout`、`watchDirs`、`watchInterval`、
`watchExcludes`、`dispatcher`。

### `RequestDispatcher`

默认调度器：Swoole 请求 → 中间件链 → 常驻 Yii Application → 响应。实现
`RequestDispatcherInterface`。

关键配置：`appConfig`、`staticRoot`、`enablePoolMetricsEndpoint` / `poolMetricsPath`、
`enableMetricsEndpoint` / `metricsPath`、`enableMetricsAggregation` /
`metricsAggregationRedis`。

方法：`addRawRoute(method, path, handler)`、`getRawRoutes()`、`getAppConfig()`、
`prewarm(n)`、`maintainPools()`。

### `RequestMetrics`

Prometheus 指标（RED：请求量/状态码/耗时直方图 + 池状态 + 进程内存/协程 + 系统负载）。
方法：`beginRequest()`、`record(duration, status, method)`、`render(pools)`、
`snapshot()`、`renderAggregate(snapshots, pools)`。

### `MetricsAggregator`

跨 worker 的 `/metrics` 聚合（Redis），把各 worker 的快照合并成整机指标。
构造参数：`(CoroutineRedisConnection $redis, string $keyPrefix = 'yii2s:metrics:')`。

### `StaticFileServer` / `Middleware\StaticFileMiddleware`

PHP 层静态文件服务（`documentRoot`）：HEAD、单段/suffix Range、路径穿越拦截、
dotfile/敏感扩展黑名单。生产建议交给 nginx。

### `PidFile`

pid 文件读写与进程存活判断。

### `Middleware\*`

| 中间件 | 作用 |
|---|---|
| `RawRouteMiddleware` | raw 路由（`/ping` 这类无 Yii 生命周期端点） |
| `StaticFileMiddleware` | 静态文件 |
| `PoolMetricsMiddleware` | `/__pool` JSON |
| `MetricsMiddleware` | `/metrics` Prometheus |
| `AbstractMetricsMiddleware` | 上面两个 metrics 中间件的公共基类 |

---

## 运行时 `Runtime\`

| 类 | 职责 |
|---|---|
| `CoroutineApplication` | 每协程隔离 request/response/session/user 的应用类 |
| `CoroutineRequest` | 协程安全请求（从 Swoole request 填充，不碰 `$_GET`/`$_POST`/`$_FILES`） |
| `CoroutineResponse` | 内存中完成格式化的响应 |
| `CoroutineUploadedFile` | 协程安全上传（`saveAs` 支持 Swoole 临时文件） |
| `CoroutineUrlManager` | 路由解析缓存（method+path 记忆化，规则多时省 CPU） |
| `RequestStateManager` | 每请求重建请求作用域组件 + 归还池连接 + sweep + 日志 flush |
| `SwooleResponseSender` | 把 Yii response 写到 Swoole response |

---

## 连接 `Db\` / `Redis\` / `Pools\`

### `CoroutineDbConnection`

池化 MySQL 连接（继承 `yii\db\Connection`）。关键配置（除 stock 的 dsn/username 外）：

| 配置 | 默认 | 说明 |
|---|---|---|
| `poolMaxActive` | 20 | 池容量（并发查询硬上限） |
| `poolWaitTimeout` | 3.0 | 借不到连接的等待秒数 |
| `poolMinIdle` | 0 | 最小空闲水位（配合 keeper） |
| `poolMaxLifetime` | 0 | 连接最长存活秒数（0 关闭） |
| `poolIdleTimeout` | 0 | 空闲超时重校验（需 `enableHealthCheck`） |
| `enableHealthCheck` | false | 借出前 `SELECT 1` |
| `slowQueryThreshold` | 0 | 慢查询日志阈值秒（0 关闭） |
| `enableCircuitBreaker` | false | 熔断（连接丢失错误才计数） |
| `circuitFailureThreshold` | 5 | 熔断打开前连续失败次数 |
| `circuitCooldown` | 30.0 | 熔断冷却秒数 |
| `enableCoroutinePooling` | true | 关闭则回退 stock 单连接 |

### `CoroutineRedisConnection`

池化 Redis 连接（继承 `yii\base\Component`，`__call` 转发到 phpredis）。配置项与
上面 db 基本对称（去 `slowQueryThreshold`，加 `hostname`/`port`/`password`/
`database`/`connectionTimeout`/`dataTimeout`）。

### `ConnectionPool` / `PooledConnectionTrait` / `HasConnectionPool` / `PoolMetricsCollector`

- `ConnectionPool`：协程 Channel 连接池（borrow/return/discard/sweep/maintain）。
- `PooledConnectionTrait`：db/redis 共享的池管理逻辑（配置字段、getPool、熔断、归还/丢弃、关闭）。
- `HasConnectionPool`：池组件契约（`getPool()` + `release()`），基础设施据此发现池组件。
- `PoolMetricsCollector`：发现并采集所有池组件的指标。

---

## 缓存 / 会话 / 队列

### `CoroutineRedisCache`

Redis 缓存，`getOrSet()` 自带单飞锁（冷缓存并发 miss 只跑一次回调）。

### `CoroutineSession`

Redis 会话（无 `$_SESSION`），完整 flash / 严格模式 / 会话固定防护。配置：
`redis`、`keyPrefix`、`timeout`、`useStrictMode`、`cookieParams`。

### `CoroutineRedisQueue`

Redis 队列（`push` / `reserve` / 失败重试 / 死信）。配置：`redis`、`channel`、
`ttr`、`maxAttempts`、`handler`。

---

## 韧性 `Resilience\`

| 类 | 作用 |
|---|---|
| `CircuitBreaker` | 熔断器（`call($op, $fallback?, $countsAsFailure?)`） |
| `CircuitOpenException` | 熔断打开时抛出的类型化异常 |
| `RateLimiter` | Redis 固定窗口限流（`allow($key, $limit, $window)`） |
| `Retry` | 指数退避重试（`call($op, $attempts, $retryable, $baseDelay, $maxDelay)`） |

---

## 调度 `Scheduling\`

| 类 | 作用 |
|---|---|
| `Crontab` | 定时任务（`@every Ns` 或 5 字段 cron） |
| `CrontabDispatcher` | 调度器（`add()` / `tick()` / `run()`） |

---

## 控制台 `Console\`

| 类 | 作用 |
|---|---|
| `SwooleController` | `swoole/start|stop|reload|status|check|routes` |
| `QueueController` | `queue/serve` |
| `ProcessInspector` | 进程树 RSS/worker 数（供 `status`） |
| `MetricsSnapshotRenderer` | `/metrics`、`/__pool` 快照抓取与格式化（供 `status`） |

---

## 其他

| 类 | 作用 |
|---|---|
| `Bootstrap` | 一行接入：自动替换 stock 组件类 + 注册默认服务器 |
| `Coroutine\CoroutineContext` | 协程上下文存储（get/set/has/remove） |
| `Coroutine\Sleep` | 协程内让出、协程外阻塞的统一 sleep |
| `Log\CoroutineFileTarget` | 缓冲批量写日志目标（并发安全） |
| `Db\CoroutineCommand` | 自愈命令（检测连接丢失并 discard）+ 慢查询计时 + 熔断接入 |

---

## 组件替换对照表（stock → 协程）

| stock 类 | 协程版 | 替换方式 |
|---|---|---|
| `yii\db\Connection` | `CoroutineDbConnection` | Bootstrap 自动 |
| `yii\redis\Connection` | `CoroutineRedisConnection` | Bootstrap 自动 |
| `yii\web\Request` | `CoroutineRequest` | Bootstrap 自动 |
| `yii\web\Response` | `CoroutineResponse` | Bootstrap 自动 |
| `yii\web\Session` | `CoroutineSession` | 显式配置 |
| `yii\redis\Cache` | `CoroutineRedisCache` | 显式配置 |
| `yii\web\UrlManager` | `CoroutineUrlManager` | 显式配置（可选） |
| `yii\web\UploadedFile` | `CoroutineUploadedFile` | 使用处 import 替换 |
