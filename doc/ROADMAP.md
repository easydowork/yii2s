# 质量硬化路线图

> 本文档是「从『能用』到『敢上生产』」的工程硬化计划。它把上一轮评审里的
> 不足与优化点落成**可执行、可验收**的清单，并随进度持续更新。
>
> 定位：不是堆功能，而是补「长期运行可信度」与「部署可控性」两块短板。
> 诚实前提：这套计划能保证的是**风险的可控性与可见性**，不是「零 bug」——
> 常驻进程 + 连接池的坑是慢变量，真实业务的灰度才是最终验收。

> **阶段变更（2026-11-04）**：服务器已从 `Swoole\Coroutine\Http\Server`（单进程）
> 升级为 `Swoole\Http\Server`（多进程，`worker_num = CPU 核数`）；观测指标端点
> （`/metrics`）与长跑检漏脚本已落地；`prewarm` 已接上 `workerStart`。以下评分与
> 多实例相关条目已据此更新。

> **阶段变更（2026-11-08）**：keeper 停机死锁修复、SO_REUSEPORT 移除、reload 语义
> 对齐（`cd2b0ff`）已提交，并在 macOS 与 Ubuntu 26.04（生产目标画像，swoole-cli
> 6.2.4 / PHP 8.4.27）双平台实测通过，详见 §5。

---

## 1. 现状评分

| 维度 | 分数 | 一句话 |
|---|---|---|
| 设计 | 8/10 | 状态隔离做在调度层、Yii2 组件尽量不改，这条路线选对了 |
| 正确性（修复后） | 8.5/10 | 并发串号/登录态/上传/keeper 死锁等 P0 已根治，双平台真实 socket 用例钉死 |
| 测试 | 8/10 | 双平台 211/852 全绿 + blog smoke 52 项 Linux 全过；仍缺 24h 实跑基线 |
| 性能 | 8.5/10 | Linux 12 核实测：纯框架 48551 QPS、`/demo/redis` 29126 QPS、0 错误 |
| 可运维性 | 7/10 | 已有 `/__pool` + `/metrics` + `swoole/status`；停机死锁噪音已消除；缺 Prometheus 告警接入 |
| 发布成熟度 | 4/10 | `v1.0.0` 已发布（2026-11-08 重建至 `cd2b0ff`，含 keeper 死锁修复）；缺 Packagist / 升级指南 |

---

## 2. 已确认的不足

| # | 不足 | 严重度 | 影响 |
|---|---|---|---|
| A1 | 可观测性缺失 | **P0** | 内存是否缓慢增长、池是否被打满，是上生产底线，现在无法证明 |
| A2 | 多实例（多 master）运维心智 | ~~P1~~ 已定案 | 2026-11-08 定案：移除 SO_REUSEPORT 同端口形态，唯一形态为**多端口 + nginx upstream**（每实例独立端口与 pid 文件，supervisor 托管）；部署演练见 §5.2 P0 |
| A3 | 错误/降级策略缺位 | **P0** | Redis/MySQL 宕机直接 500 连击，无降级/熔断 |
| A4 | 连接池缺少长期健康机制 | **P0** | 只有 borrow 时可选校验，无 min-idle 水位、max-lifetime、空闲保活 |
| A5 | ~~部分功能「写了没接上」~~ | ~~过程性~~ | 已解决：`prewarm` 已接上 `workerStart`，`forgetComponent` 有回归护栏 |
| A6 | 测试盲区 | P1 | 无 24h soak、无压测基线、无「重启后 p99」这类发布断言 |
| A7 | 工程纪律小毛病 | P2 | 测试计数靠手工 sed、提交信息与文件曾不一致 |

---

## 3. 优化项清单（按性价比）

状态标记：✅ 已完成 ｜ 🚧 进行中 ｜ ⬜ 未开始 ｜ ⏸️ 待讨论

### P0 · 上生产前必须补

| 项 | 状态 | 目标 | 验收标准 |
|---|---|---|---|
| **可观测性：Prometheus 指标端点** | ✅ | RED 方法（请求量/状态码/耗时直方图）+ 池状态 + 进程内存/协程数 | `GET /metrics` 输出标准 text 格式；`RequestMetricsTest` 通过 |
| **长跑检漏脚本** | ✅ | 持续压测 + 采样 RSS/池漂移，自动标记泄漏/超时 | `scripts/soak.php` 可跑出 RSS/池曲线与结论 |
| **24h soak 实跑基线** | 🚧 | 把「无泄漏」从猜测变成一条曲线 | 4h 基线已完成：2.02 亿请求 0 错误、RSS 平台 260MB、池 `discarded=0/timeouts=0/active=0`；24h 待续 |
| **连接池健康机制** | ✅ | 空闲保活 + min-idle 水位 + max-lifetime 回收 | `ConnectionPoolTest` 新增 4 用例：max-lifetime 回收（borrow/return）、min-idle 水位、空闲保活 |
| **降级/熔断** | ✅ | Redis/MySQL 故障时的明确错误或缓存降级，而非 500 连击 | `CircuitBreakerTest` 5 用例 + DB 故障注入集成测试（坏 DSN 熔断快速失败） |

### P1 · 收益明确

| 项 | 状态 | 目标 | 验收标准 |
|---|---|---|---|
| **多实例进程模型定案** | ✅ | 已定案（2026-11-08）：移除 SO_REUSEPORT 同端口形态（macOS 上 Swoole 忽略该选项，属未测试路径），唯一支持形态为**多端口 + nginx upstream**（每实例独立端口与 pid 文件，supervisor 托管） | 部署文档只描述该形态；e2e reload 断言通过 |
| **路由缓存 `CoroutineUrlManager`** | ✅ | per-process 数组缓存路由解析，规则多时省 30%+ | `CoroutineUrlManagerTest` 3 用例：命中缓存、独立缓存、有界缓存 |
| **慢查询/慢请求日志** | ✅ | DB 查询耗时超阈值记日志 | `CoroutineDbConnection::$slowQueryThreshold` + `CoroutineCommand` 计时，`SELECT SLEEP` 注入测试命中 |

### P2 · 打磨

| 项 | 状态 | 目标 | 验收标准 |
|---|---|---|---|
| **dispatcher 中间件化** | ✅ | static/raw/metrics 抽成中间件链，职责清晰、可单测 | 现有 server 套件 + blog smoke 不回归（194 测试全绿） |
| **`renderExceptionResponse` 加固** | ✅ | 错误动作再抛异常时路径更稳 | 兜底 `end()` 包 try/catch，响应已部分写入也不会再抛 |
| **发布** | 🚧 | `v1.0.0` 已打 tag（2026-11-08 重建至 `cd2b0ff`）；剩 Packagist + 版本冻结 + 升级指南 | 可 `composer require` 到固定版本 |

### 过程中新发现并已修复

| 项 | 状态 | 说明 |
|---|---|---|
| **DB 连接断线毒化池** | ✅ | MySQL 重启后死连接被反复复用；`CoroutineCommand` 检测 08SQLSTATE/2006/2013 并 discard，`KILL CONNECTION` 集成测试钉死 |
| **冷启动连接风暴致并发失败** | ✅ | macOS 下冷池 + 50 并发建立连接触发 Swoole `send()` EMSGSIZE（"Message too long"），约 60% 请求超时；`prewarmConnections` 预热池后 50 并发全 200 |
| **冷缓存 thundering herd** | ✅ | 冷缓存 + 并发 miss 时 N 个请求同时跑回调（重复查 DB）；`CoroutineRedisCache::getOrSet` 加单飞锁（`SET NX`），并发下回调只执行一次（测试钉死） |
| **池 keeper 协程停机死锁** | ✅ | `poolMaintenanceInterval > 0` 时，keeper 的 `while(true){sleep()}` 与排空 `Channel::pop()` 均依赖 reactor 定时器，worker 退出后永久挂起 → Swoole 判死锁强杀 worker（破坏优雅排空）。改为 reactor 定时器（worker 退出自动清理）+ `isEmpty()` 守护空排空；mac/Ubuntu 各 6 轮不同时序压测 `deadlock=0`（`cd2b0ff`） |
| **SO_REUSEPORT「声称可用但已丢失」** | ✅（移除） | 多进程迁移时 `Coroutine\Http\Server` 第 4 参的 `reuse_port` 被丢掉，文档仍声称可用——而 macOS 上 Swoole 直接忽略该选项，属未测试路径。已删除同端口多实例形态与 `SWOOLE_PID_FILE`，多实例只保留「多端口 + nginx upstream」 |
| **reload 文档/脚本与代码脱节** | ✅ | `swoole/reload` 早已改为 SIGUSR1（worker 重载、master pid 不变），README/DEVELOPMENT/e2e.sh 仍写「停机 + 重新拉起」。已全部对齐，e2e 断言改为「master pid 不变」，并修正停机后 pid 文件检查时序 |

### 已知边界（设计取舍，暂不修复）

| 项 | 说明 |
|---|---|
| **会话 last-write-wins** | Redis 会话无锁，同 session 并发写会互相覆盖（flash/身份变更可能丢）；需要更强隔离时用「有锁会话」或业务侧串行化 |

### 运维工具增强（对标 Hyperf，2026-11-04 立项）

> 目标：补强「看运行状态、多少连接、占多少内存」这类日常运维能力。
> 对照 Hyperf 的 `metric`/`process`/`crontab`/`describe:routes` 梳理差距。
> 现状：已有 `swoole/start|stop|reload|check|status`、`queue/serve`、`/metrics`、`/__pool`。

| 项 | 状态 | 目标 | 验收标准 |
|---|---|---|---|
| **`swoole/status` 运行时面板** | ✅ **P0** | 终端直接看 worker 数 / 内存 / 协程数 / DB、Redis 连接数 / 累计请求与错误 | `swoole/status` 输出面板：进程树 RSS + worker 数（`ps`/`pgrep`）+ `/metrics` 请求/协程 + `/__pool` 连接数 |
| **`/metrics` 默认指标补全** | ✅ P1 | 每 worker 内存、系统负载、连接数汇总、每路径 P50/P90/P99 直方图 | 新增 `MetricsAggregator`（Redis 跨 worker 聚合，`enableMetricsAggregation`）+ `sys_getloadavg`；每路径直方图与 grafana.json 待续 |
| **`describe:routes` 命令** | ✅ P1 | 列出 urlManager 规则 + raw 路由 | `swoole/routes` 输出 raw 路由 + urlManager 规则表 |
| **定时任务 `crontab`** | ✅ P2 | 秒级定时任务（对标 Hyperf/crontab） | `Crontab` + `CrontabDispatcher`（`@every Ns` + 5 字段 cron）；`CrontabDispatcherTest` 4 用例 |
| **限流 / 重试组件** | ✅ P2 | 对标 Hyperf/rate-limit、hyperf/retry | `RateLimiter`（Redis 固定窗口）+ `Retry`（指数退避）；`RateLimiterTest` + `RetryTest` |

---

## 4. 执行计划（三阶段）

### 第一阶段：长期可信度（本阶段）
**目标**：把「内存/连接是否泄漏、故障是否能自愈」变成可证明的事。

- [x] 观测指标端点（已完成，未提交）
- [x] 长跑检漏脚本（已完成，未提交）
- [x] 连接池 min-idle / max-lifetime / 空闲保活
- [x] 降级/熔断策略 + 故障注入测试
- [x] 4h soak 实跑（2.02 亿请求 0 错误、RSS 平台、池零泄漏，日志 `/tmp/yii2s-soak-4h.log`）
- [ ] 24h soak 实跑，记录 RSS/池基线（Ubuntu 测试机 192.168.0.86 已就绪，可直接挂起跑，见 §5.2）
- [x] 提交本阶段成果（`cd2b0ff`：DB 自愈 + 观测 + 池健康 + 熔断 + soak + keeper 死锁修复）
- [x] 慢查询/慢请求日志

**验收**：24h soak 出具「无泄漏」曲线；重启/断连后自动恢复有测试覆盖；故障注入下行为可预期。

### 第二阶段：性能与部署心智
**目标**：把性能和部署的操作成本降下来。

- [x] 多实例进程模型定案：移除 SO_REUSEPORT，唯一形态为多端口 + nginx upstream
- [x] 路由缓存 `CoroutineUrlManager`
- [x] dispatcher 中间件化重构

**验收**：路由密集场景压测有据可查；多实例启动/停止/重载一键化。

### 第三阶段：发布与灰度
**目标**：版本可控、可升级、有真实业务背书。

- [x] 打 tag（`v1.0.0`，2026-11-08 重建至 `cd2b0ff`，含 keeper 死锁修复 + SO_REUSEPORT 移除 + reload 对齐）
- [ ] 上 Packagist、版本冻结
- [ ] 写升级指南（从 FPM 切到 Swoole 的 checklist）
- [ ] 一个真实业务灰度 ≥1 周，用观测数据决策是否全量

**验收**：生产能以固定版本引入；灰度期有观测数据 + 可回滚开关。

---

## 5. 双平台实测与生产就绪路线（2026-11-08）

### 5.1 测试环境与结果

| 环境 | 配置 | 结果 |
|---|---|---|
| macOS 开发机 | i7-4770HQ / Monterey 12.7.6 / swoole-cli 6.2.0 / PHP 8.4.14 | 211/852 全绿；e2e 9 checks；keeper 6 轮 `deadlock=0`；soak 25s 12.4 万请求 0 错误；blog smoke 36/52（见下「平台差异」） |
| **Ubuntu 26.04.1（生产目标画像）** | x86_64 12 核 61G / swoole-cli 6.2.4 / PHP 8.4.27 / MySQL 8 + Redis | **211/852 全绿；e2e 9 checks；keeper 6 轮 `deadlock=0`；soak 25s 17.6 万请求 0 错误；blog smoke 52/52 全过；bench `/` 48551 QPS、`/demo/redis` 29126 QPS、0 错误、RSS 平台、池零泄漏** |

平台差异结论：

- macOS 上 blog smoke 第 9 步并发 burst 触发 Swoole `send()` EMSGSIZE
  （"Message too long"）导致 worker 卡死——**Linux 无此问题（52 项全过）**，
  确认为 macOS 特有限制，不阻塞生产（目标平台是 Linux）。
- keeper 死锁修复在两个平台均验证有效（各 6 轮不同时序压测零复现）。
- Linux 12 核实测吞吐显著高于 README 基准（48551 vs 24832 QPS），README 数字偏保守。

### 5.2 生产就绪清单

功能正确性已由双平台全绿证明，「敢上生产」还差四类**运营级验证**：

#### P0 · 上生产前必须完成

| 项 | 为什么必须 | 做法 | 验收标准 | 状态 |
|---|---|---|---|---|
| **24h 长跑 soak** | 常驻进程的内存/连接泄漏是慢变量，25s/4h 证明不了 24h | Ubuntu 测试机挂起跑：`nohup swoole-cli scripts/soak.php --path=/demo/db --concurrency=50 --duration=86400 --interval=60 --pid=<pid> > soak24h.log 2>&1 &` | 24h 曲线：RSS 平台不单调上涨、池 `discarded/timeouts` 恒为 0、全程 0 错误 | ⬜ |
| **故障注入演练** | 自愈逻辑有测试用例钉死，但没在真实宕机下演练过 | 压测流量下依次执行：`systemctl stop mysql` → 观察 500 快速失败而非挂死 → 恢复 MySQL → 确认池自愈无需重启进程；同法演练 Redis 宕机、`kill -9` 单个 worker（master 自动拉起） | 每个场景：故障期请求快速失败或降级、恢复后不重启进程即自动恢复、全程无 `deadlock` 日志 | ⬜ |
| **完整部署演练** | 生产不是裸跑 swoole-cli | nginx（静态 + TLS + upstream 多端口 9501-950N）+ supervisor 托管（`stopwaitsecs > gracefulTimeout`）+ 逐台滚动重启 | 压测中 reload/滚动重启：0 请求失败（在途排空生效）、无 pid 文件/端口残留、nginx 摘除节点不抖动 | ⬜ |

#### P1 · 上线首周补齐

| 项 | 做法 | 验收标准 | 状态 |
|---|---|---|---|
| **可观测接入** | `/metrics` 接 Prometheus + Grafana | 至少 3 个告警：RSS 连续上涨、池 `waiting` 持续 >0、5xx 率超阈值；告警链路可触达 | ⬜ |
| **生产参数调优** | 用真实流量画像压测调 `worker_num` / `poolMaxActive` / `max_wait_time` | 有调优前后对比数据；最终参数写入部署文档 | ⬜ |
| **升级回滚演练** | `v1.0.0` → `v1.0.1` → 回滚 | 全程可服务；回滚 ≤1 条命令 | ⬜ |

#### P2 · 灰度阶段验证

| 项 | 说明 | 状态 |
|---|---|---|
| 安全清单 | `trustedProxies`（nginx 后真实客户端 IP）、`package_max_length`/`client_max_body_size` 对齐、`RateLimiter` 挂到真实路由 | ⬜ |
| **真实业务灰度 ≥1 周** | 最终验收：灰度期观测数据 + 可回滚开关决策是否全量（见 §7 诚实边界） | ⬜ |

### 5.3 发布收尾

- [x] **`v1.0.0` 已重建至 `cd2b0ff`**（2026-11-08）：keeper 死锁修复 + SO_REUSEPORT 移除 +
  reload 对齐已包含在 tag 内，本地与远端（GitHub）一致——不再需要 `v1.0.1` 补丁版本
- [ ] 上 Packagist（`composer require easydowork/yii2s` 可用、版本冻结）
- [ ] 升级指南：FPM → Swoole checklist（`enablePrettyUrl` 必开、禁用 `$_SESSION`/
  `header()` 等超全局、`CoroutineUploadedFile` 替换上传、子协程不继承请求上下文、
  部署拓扑与参数清单）

---

## 6. 执行约定

1. **一任务一提交**：每个 ☑ 项独立提交，提交信息与改动一致（避免 `e74bbb3` 那类「信息与文件脱节」）。
2. **验证门槛**：全量 `phpunit` + `phpstan` + `php-cs-fixer` 通过，涉及池/运行时改动追加 soak 基线对比。
3. **文档同步**：完成任务后回填本文档状态（✅ + 日期），测试计数用脚本化方式更新而非手工 sed。
4. **多实例**：已定案（2026-11-08）——唯一形态为多端口 + nginx upstream；SO_REUSEPORT 同端口形态已移除（见 §3、§5.2 部署演练）。

---

## 7. 诚实边界

- 本计划**压低**「低级泄漏、上线即翻车」的风险，**不保证**零 bug。
- 常驻进程的坑是慢变量，`time_in_production` 无法被任何方案压缩。
- 外部变量（Swoole/PHP/MySQL/Redis 版本演进）不受本计划控制。
- 最终「敢上核心业务」的依据是**灰度期的观测数据 + 可回滚开关**，而非本文档本身。
