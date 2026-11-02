# 路线图与扩展点（Roadmap）

> 本文档记录：①服务器生命周期插槽（现有扩展机制，如何用它挂定时任务等 Swoole 特性）；
> ②后续连接池扩展的规划（RabbitMQ / Kafka / PostgreSQL 等）与可行性评估。

---

## 一、服务器生命周期插槽（已实现）

`HttpServer` 暴露三个 Yii2 风格事件，是所有"长生命周期特性"（定时任务、预热、
指标上报、优雅收尾）的官方挂载点：

| 事件 | 触发时机 | 典型用途 |
|---|---|---|
| `EVENT_BEFORE_START` | 服务器启动前（协程已就绪） | 预加载缓存、启动定时任务协程 |
| `EVENT_AFTER_START` | 监听就绪、开始接受流量 | 健康上报到注册中心、打点 |
| `EVENT_AFTER_STOP` | 优雅停机完成 | 指标落盘、通知下游 |

### 示例：在插槽上实现定时任务（无需新组件）

```php
// config/web.php
use Yii2S\Swoole\Server\HttpServer;
use Swoole\Coroutine;

'swooleHttpServer' => [
    'class' => HttpServer::class,
    'on beforeStart' => function (): void {
        // 每 60 秒执行一次的周期任务：协程循环 + sleep 即是 cron 语义
        Coroutine::create(function (): void {
            while (true) {
                doCleanupWork();          // 你的业务
                Coroutine::sleep(60);
            }
        });
    },
    'on afterStop' => function (): void {
        flushMetrics();
    },
],
```

要点：
- 回调运行在服务器主协程空间内，可直接创建子协程、借用池连接；
- 周期任务用「协程 + sleep 循环」实现（Swoole 协程下不要用 `Timer`——
  timer 回调里做阻塞/池借还的语义差，且与优雅停机交互复杂）；
- 优雅停机时协程会随进程退出——周期任务内部应保证单轮操作的原子性
  （数据库操作天然在事务/语句级别原子）。

> 后续可选增强（当前未实现，避免过度设计）：声明式 `PeriodicTask` 组件
> （`tasks => ['cleanup' => ['every' => 60, 'handler' => ...]]`）。等出现
> 3 个以上真实周期任务场景再做。

---

## 二、开发环境功能（已实现）

| 功能 | 命令 | 说明 |
|---|---|---|
| 热重载 | `php yii swoole/start --watch` | 轮询 @app 下 PHP 文件 mtime（0.5s，零依赖），
检测变更后优雅停机并以原命令行 respawn，新代码自动生效。
`--watchDirs` / `--watchInterval` / `--watchExcludes` 可调。
**开发专用**——reload 会重启进程，生产禁止。 |
| 配置体检 | `php yii swoole/check` | 静态读 web 配置（dispatcher 的 config 文件）检查
cookieValidationKey / prettyUrl / pid 目录可写，并用临时连接探活
db（SELECT 1）/ redis（PING）。问题在开发期暴露而非首个请求。 |

## 三、连接池扩展规划（待规划 → 排期评估）

现有 `Pools\ConnectionPool` 是**通用对象池**（factory / validator / destroyer
三个注入点），新增一种连接池 = 写一个组件类把特定客户端接进池，无需改池本体。

| 目标 | 驱动与协程化路径 | 可行性 | 优先级 | 备注 |
|---|---|---|---|---|
| **PostgreSQL** | pdo_pgsql → Swoole hook（`SWOOLE_HOOK_PDO_PGSQL` 显式启用） | 中 | P1 | hook 常量已存在（php_swoole_coroutine.h）；需要把 hook flags 组合进 HttpServer |
| **RabbitMQ (AMQP)** | php-amqplib（socket/stream）→ `HOOK_SOCKETS`/`HOOK_TCP` 覆盖 | **待实测** | P2 | 消费者模式（长连接 push）与池语义冲突：连接要独占消费——适合「每消费者一连接」而非池化；生产者连接可池化 |
| **Kafka** | rdkafka（librdkafka C 扩展）→ **无法 hook**（C 层 socket） | 低 | P3 | 除非 librdkafka 提供 non-blocking API + Swoole 原生客户端出现；建议保持独立进程消费，不进协程 |
| **Memcached** | pecl memcached → 需验证 hook 覆盖 | 待实测 | P3 | Redis 已覆盖多数场景 |
| **Elasticsearch** | HTTP REST（elasticsearch-php 用 Guzzle/cURL） | 中 | P3 | `HOOK_CURL`/`HOOK_NATIVE_CURL` 可协程化，但 curl hook 在 6.x 有已知边界（HOOK_ALL 默认排除） |

### 通用接入模式（以 PostgreSQL 为例）

```php
// 1. 连接工厂产出协程可用的客户端
$pool = new ConnectionPool(
    factory: fn () => new PDO($dsn, $user, $pass),   // hook 协程化
    validator: fn ($pdo) => $pdo->query('SELECT 1') !== false,
    destroyer: fn ($pdo) => $pdo = null,
    maxActive: 20,
);
```

### 排期建议

1. **P1 PostgreSQL**：加一个 `SWOOLE_HOOK_PDO_PGSQL` 的 hook 组合选项
   （`HttpServer::$hookFlags` 已是公开属性，文档 + 测试即可），实测确认 pdo_pgsql
   在 hook 下的事务/预处理语句行为。
2. **P2 RabbitMQ**：分「生产者池化」与「消费者独立连接」两个形态设计，
   先写独立探针验证 php-amqplib 在 hook 下的心跳与断线行为。
3. **P3 Kafka**：不建议纳入协程进程，文档给出独立消费者部署方案即可。
