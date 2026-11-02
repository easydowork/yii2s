# 外部评审指南（Reviewer Guide）

> 本文档写给**未参与开发的 AI 或工程师**，目标：独立验证本项目声称的质量，并对代码给出评审结论。
> 项目：`yii2s/yii2-swoole` —— 让 Yii2 应用运行在 Swoole 6 常驻内存协程服务器上的加速扩展包。
> 仓库根：`d:\EServer-data\www\yii2s`（Windows 开发机）；WSL2 Ubuntu 26.04 内副本 `~/yii2s`。

---

## 1. 你要评审什么

核心价值主张：**业务代码不改，只换配置里的组件类**，Yii2 应用即获得常驻内存 + 协程 + 连接池。

评审应回答四个问题：

1. **正确性**：常驻进程下请求状态是否严格隔离？连接池并发是否安全、是否泄漏？
2. **安全**：路径穿越、CSRF、认证头解析是否可靠？
3. **工程质量**：测试是否真实有效（不是摆设）？静态分析是否干净？
4. **API 设计**：是否符合 Yii2 惯例？配置是否一致、文档是否与实现一致？

## 2. 十分钟复现路径（按序执行）

代码已就绪，以下命令全部可直接执行：

```bash
# === A. Windows 侧（PowerShell，swoole-cli 为独立二进制无需安装） ===
cd D:\EServer-data\www\yii2s
D:\swoole-cli-v6.2.2-msys2-x64\bin\swoole-cli.exe vendor/phpunit/phpunit/phpunit --no-coverage
# 期望: OK (68 tests, 237 assertions)

D:\EServer-data\childApp\php\php-8.4\php.exe vendor/phpstan/phpstan/phpstan analyse --no-progress
# 期望: [OK] No errors (level 5)

# === B. 端到端（Windows） ===
D:\swoole-cli-v6.2.2-msys2-x64\bin\swoole-cli.exe example/yii swoole/start
# 另开终端：
curl http://127.0.0.1:9501/                       # 200 JSON
curl http://127.0.0.1:9501/demo/db                # 200（MySQL 连接池）
curl http://127.0.0.1:9501/demo/redis             # 200
curl -I http://127.0.0.1:9501/css/style.css       # 200 text/css（静态文件）
curl http://127.0.0.1:9501/__pool                 # 池指标（已开启）
D:\swoole-cli-v6.2.2-msys2-x64\bin\swoole-cli.exe example/yii swoole/stop

# === C. Linux 侧（WSL2 Ubuntu 26.04，原生 swoole-cli） ===
wsl -- bash ~/yii2s/scripts/wsl-e2e.sh     # start/status/reload真实respawn/SIGTERM 全链路
wsl -- bash ~/yii2s/scripts/wsl-bench.sh   # 基准: 13706 QPS(纯框架) 零错误
```

依赖说明：测试需要本机 MySQL（库名 yii2s_demo）与 Redis，配置在 `phpunit.xml.dist` 的
PHP 常量。Windows 侧服务需先启动（`D:\EServer-data\childApp\server\` 下）。

## 3. 声称事实清单（欢迎证伪）

以下每条声称都给出验证方法。评审者应优先攻击这些声称：

| # | 声称 | 验证方法 |
|---|---|---|
| C1 | 请求状态严格隔离，并发请求互不污染（GET/POST/cookie/session） | 跑 `tests/runtime/`、`tests/server/HttpServerTest.php`；或压测中并发请求带不同参数验证响应不串 |
| C2 | 连接池并发安全，借还配对，子协程泄漏由 sweep() 兜底 | `tests/pools/ConnectionPoolTest.php::testSweepReclaimsConnectionsOfDeadCoroutines`；或压测后 `curl /__pool` 看 active 必须归 0 |
| C3 | 静态文件服务防路径穿越 | 读 `src/Server/StaticFileServer.php`（realpath 前缀比较），尝试 `curl --path-as-is http://127.0.0.1:9501/../config/web.php`（应 404） |
| C4 | CSRF 完整可用 | `tests/server/HttpServerCsrfTest.php`（签发->无 token 400->带 token 200） |
| C5 | Basic Auth 头解析正确（密码含冒号、空密码、scheme 大小写） | `tests/runtime/HttpBasicAuthTest.php` |
| C6 | Redis MULTI/DISCARD/PIPELINE 天然工作（协程粘性借用保证同连接） | `tests/redis/RedisMultiTest.php` |
| C7 | 优雅停机等待在途请求排空（有界） | `tests/server/HttpServerGracefulStopTest.php`；或 Linux 上 `kill -TERM` 期间打慢请求观察响应仍送达 |
| C8 | reload 在 Linux 上真实 respawn | `wsl -- bash ~/yii2s/scripts/wsl-e2e.sh` 第 3 步（pid 变化、端口重绑、服务恢复） |
| C9 | 数据库事务在协程下正常（连接粘性） | `curl http://127.0.0.1:9501/demo/transaction`；`tests/db/` |
| C10 | 队列：延迟投递、失败重试（attempts+1）、超限进死信 | `tests/queue/CoroutineRedisQueueTest.php` |
| C11 | 队列空时 `run(N)` 立即返回，不挂死 | `tests/queue/`（曾有挂死 bug，已修并有回归） |
| C12 | Linux 基准：13706 QPS（纯框架），池零泄漏 | `scripts/wsl-bench.sh` 输出 + `/__pool`（total_timeouts=0, active=0） |

## 4. 架构与代码地图

数据流（一次 HTTP 请求的生命周期）：

```
Swoole Coroutine\Http\Server (单进程多协程, SO_REUSEPORT 多实例)
└─ HttpServer::runServer() → RequestDispatcher::dispatch()
   ├─ tryServeStaticFile()      PHP 层静态文件（documentRoot 可选，生产用 nginx）
   ├─ tryServePoolMetrics()     /__pool 调试端点（默认关闭）
   ├─ getApplication()          常驻 Yii2 Application（进程内创建一次）
   ├─ stateManager->beginRequest()            在途请求计数
   ├─ request->setSwooleRequest()             Swoole→Yii2 转换（超全局隔离核心）
   ├─ $app->handleRequest()                   标准 Yii2 路由/控制器
   ├─ response->finalize() + SwooleResponseSender
   └─ finally: RequestStateManager::cleanup() ← 每请求收尾（关键！）
        ├─ 重建 request/response/session/user（状态隔离）
        ├─ release() 各池化组件的连接
        ├─ pool->sweep()（回收死亡协程的连接）
        └─ flush 日志缓冲
```

| 目录 | 职责 | 评审重点文件 |
|---|---|---|
| `src/Runtime/` | Swoole↔Yii2 桥接 | `RequestStateManager.php`（每请求重建+回收）、`CoroutineRequest.php`（超全局隔离） |
| `src/Pools/` | 连接池 | `ConnectionPool.php`（并发正确性核心）、`PoolMetricsCollector.php` |
| `src/Server/` | 服务器生命周期 | `HttpServer.php`、`RequestDispatcher.php`、`StaticFileServer.php`、`PidFile.php` |
| `src/Db/` `src/Redis/` | 池化组件 | 协程粘性借用、事务/MULTI 边界 |
| `src/Queue/` `src/Session/` `src/Cache/` `src/Log/` | 上层组件 | ZSET 队列原子性、会话存取 |
| `src/Console/` | 命令 | `SwooleController.php`（守护化/停机/respawn） |
| `tests/` | 9 个目录 68 个测试 | 单测 + 真实服务器集成测试 |

## 5. 核心设计决策（欢迎质疑）

| 决策 | 理由（均为实测，详见 DEVELOPMENT.md 坑列表） |
|---|---|
| 每请求重建 request/response/session/user；db/redis 走池 | 状态隔离做在调度层，上层 Yii2 组件尽量不动——这是 CSRF/BasicAuth/事务/MULTI 零适配可用的原因 |
| 池耗尽阻塞等待 + 超时抛异常 | 明确错误路径，不无限等待；poolMaxActive 是并发硬边界 |
| 泄漏回收用 sweep()（每请求结束，由活跃协程同步执行）而非 Coroutine::defer | defer 阶段做 channel IO/新建协程在 Windows 构建实测崩溃；sweep 确定性执行 |
| 借出登记用普通数组（spl_object_id 索引）不用 WeakMap | msys2 构建 WeakMap 跨协程写入不可见（独立脚本实锤），且会崩进程 |
| 多进程 = 多实例 + SO_REUSEPORT（不做进程内 fork） | Linux 上 supervisor numprocs=N 内核负载均衡；fork 路径 Windows 无法测试 |
| 静态文件 PHP 层实现且默认关闭 | 协程 Http\Server 无视 enable_static_handler（探测实证）；生产由 nginx 直出 |
| respawn 前置 PHP_BINARY | pid 文件存无解释器脚本调用，入口无 shebang；不前置则 reload 永远失败（实测） |
| Queue 独立实现，未兼容 yii\queue\Queue 接口 | 避免额外依赖；接口兼容列为后续增强（主动取舍，可评估） |

## 6. 建议的评审切入顺序

**P0 正确性（最值得攻击）**

- `ConnectionPool::borrow()` 的并发语义：三路借出路径、waiting 计数 try/finally、sweep 与 borrow/return 的竞态
- `RequestStateManager::cleanup()` 与异常路径：请求中途抛异常时状态是否完整回收
- `CoroutineDbConnection::borrow()/release()` 的协程粘性：事务跨界回滚、detach 场景

**P1 安全**

- `StaticFileServer::serve()`：URL 解码、realpath 前缀、符号链接、sendfile 边界
- `CoroutineRequest` 对头/cookie 的处理是否有注入面

**P2 测试质量**

- 集成测试是否真实起服务器（`tests/server/` 全部是真实 socket 交互，非 mock）
- 时序测试（graceful stop）是否 flaky（已知：随机端口偶发冲突，重跑即绿）

**P3 一致性**

- README.md 的每条配置说明 vs 实现
- `doc/DEVELOPMENT.md` 的 24 条坑列表：每条都是可复现的实证记录

## 7. 主动交代的已知取舍与限制（非缺陷）

1. `urlManager.enablePrettyUrl` 必须开启（`?r=` 路由依赖 `$_GET`，协程不安全）——文档已注明
2. Queue 未兼容 `yii\queue\Queue` 接口（避免 yiisoft/yii2-queue 依赖）
3. MySQL 主从分离未实现（需真实主从环境验证，本机做不了真测试）
4. Windows reload 的 respawn 受限（系统策略禁 exec），Linux 完整可用
5. 集成测试偶发时序失败（随机端口冲突），重跑即绿——测试基建问题非产品问题
6. CI（GitHub Actions）配置已就绪但**尚未真实运行**（无远程仓库）——唯一未验证环节

## 8. 环境备注

- Windows 侧 MySQL/Redis 需手动启动（`D:\EServer-data\childApp\server\` 下）
- WSL 与 Windows 共享 localhost 依赖 mirrored 网络模式（`.wslconfig`，已配置）
- WSL 内项目在 `~/yii2s`（ext4 副本）；改动 src/ 后需 `cp -f` 同步过去再测
- PowerShell 内联调 WSL 是转义地狱——WSL 一律用脚本文件（`scripts/wsl-*.sh`）

## 9. 期望的评审产出

- 按上述 P0-P3 给出问题清单（严重度 / 文件行号 / 复现方式）
- 对 C1-C12 声称的证伪结果（成功证伪任何一条都极有价值）
- 对设计决策表（第 5 节）的反驳或确认
