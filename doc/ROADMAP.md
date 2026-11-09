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

> **阶段变更（2026-11-09）**：24h soak 实跑完成——9467 万请求 0 错误、RSS 预热后
> 平台 195-197MB 零漂移、db 池 2017 万次借还零超时零销毁；redis 池 2 次 discarded
> 为 Redis 服务端断开空闲连接后的自愈重建（8 worker 同步发生、请求零影响，已溯源），
> 详见 §5.2。**P0 三项全部完成：故障注入演练（MySQL/Redis/worker 三场景）与
> nginx upstream 滚动重启演练同日通过，详见 §5.2；第一阶段验收达成。**

---

## 1. 现状评分

| 维度 | 分数 | 一句话 |
|---|---|---|
| 设计 | 8/10 | 状态隔离做在调度层、Yii2 组件尽量不改，这条路线选对了 |
| 正确性（修复后） | 8.5/10 | 并发串号/登录态/上传/keeper 死锁等 P0 已根治，双平台真实 socket 用例钉死 |
| 测试 | 8.5/10 | 双平台 211/852 全绿 + blog smoke 52 项 Linux 全过 + 24h soak 9467 万请求 0 错误 |
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
| A6 | 测试盲区 | P1 | 24h soak 与压测基线已有（2026-11-09）；剩「重启后 p99」这类发布断言（归入 §5.2 P1 调优/演练） |
| A7 | 工程纪律小毛病 | P2 | 测试计数靠手工 sed、提交信息与文件曾不一致 |

---

## 3. 优化项清单（按性价比）

状态标记：✅ 已完成 ｜ 🚧 进行中 ｜ ⬜ 未开始 ｜ ⏸️ 待讨论

### P0 · 上生产前必须补

| 项 | 状态 | 目标 | 验收标准 |
|---|---|---|---|
| **可观测性：Prometheus 指标端点** | ✅ | RED 方法（请求量/状态码/耗时直方图）+ 池状态 + 进程内存/协程数 | `GET /metrics` 输出标准 text 格式；`RequestMetricsTest` 通过 |
| **长跑检漏脚本** | ✅ | 持续压测 + 采样 RSS/池漂移，自动标记泄漏/超时 | `scripts/soak.php` 可跑出 RSS/池曲线与结论 |
| **24h soak 实跑基线** | ✅ | 把「无泄漏」从猜测变成一条曲线 | **24h 基线完成（2026-11-09）**：9467 万请求 0 错误、RSS 预热后平台 195-197MB 零漂移、db 池 2017 万次借还 `discarded=0/timeouts=0/active=0`（redis 池 2 次 discarded 为服务端断连自愈，已溯源）；4h/25s 数据见历史 |
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
| **soak.php 多实例口径** | ⬜ | `pool snapshot` 的 first→last 差值在 upstream 多实例场景跨实例不可比（演练中出现负数差值） | 采样携带实例标识（host:port），差值按同一实例计算；多实例时输出逐实例快照 |

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
- [x] 24h soak 实跑，记录 RSS/池基线（2026-11-09 完成：云服务器 4 核跑满 86400s，9467 万请求 0 错误、RSS 平台零漂移、db 池 2017 万次借还仅建 2 连接，见 §5.2）
- [x] 提交本阶段成果（`cd2b0ff`：DB 自愈 + 观测 + 池健康 + 熔断 + soak + keeper 死锁修复）
- [x] 慢查询/慢请求日志

**验收**：**已达成（2026-11-09）**——24h soak 出具「无泄漏」曲线 ✅；断连自愈有测试覆盖 + 真实宕机演练 ✅；故障注入下行为可预期 ✅。

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
| **24h 长跑 soak** | 常驻进程的内存/连接泄漏是慢变量，25s/4h 证明不了 24h | Ubuntu 4 核云服务器，2026-11-08 16:34 → 11-09 16:34 跑满 86400s（50 并发 `/demo/db`，60s 采样 ×1441） | **✅ 通过：9467 万请求 0 错误（1096 req/s）；RSS 预热后平台 195-197MB、24h 零漂移；db 池 2017 万次借还 `total_created=2`、0 超时 0 销毁；redis 池 2 次 discarded = Redis 服务端断开空闲连接后的自愈重建（8 worker 同步发生、请求零影响，已溯源）** | ✅ |
| **故障注入演练** | 自愈逻辑有测试用例钉死，但没在真实宕机下演练过 | 压测流量下依次执行（2026-11-09，mac phpenv/EServer 环境，30 并发）：①MySQL `kill -TERM` 宕机 45s ②Redis 宕机 ③`kill -9` 单 worker | **✅ 通过：①故障期 500 快速失败 1.5ms（不挂死），死连接自动清理，恢复后首个请求即 200（池自动重建），85 万请求传输层 0 失败、无 deadlock；②500 毫秒级快速失败，死连接清理后 keeper 自动补池（created 2→4）首请求 200；③master 秒级拉起新 worker，仅损失 1 个在途请求（物理极限），服务零中断** | ✅ |
| **完整部署演练** | 生产不是裸跑 swoole-cli | mac 本机演练（2026-11-09）：独立 nginx 实例跑 upstream（127.0.0.1:9701+9702 双 example 实例），压测中滚动重启（停 A → nginx 自动切 B → 重启 A → 回归双节点） | **✅ 通过：停 A 期间 0 失败（88328 请求仅 1 个传输层 error = A 停止瞬间的在途断连，失败率 0.001%）；A 重启窗口出现过 1 次瞬时 502（nginx fail_timeout 窗口 + 盲重启时序）——生产 runbook 应「摘除→停→起→探活→加回」；发现 soak.php 的 pool snapshot 差值在 upstream 多实例场景无意义（跨实例差值出现负数，列为工具改进项 §3 P2）** | ✅ |

#### P1 · 上线首周补齐

| 项 | 做法 | 验收标准 | 状态 |
|---|---|---|---|
| **可观测接入** | `/metrics` 接 Prometheus + Grafana | 至少 3 个告警：RSS 连续上涨、池 `waiting` 持续 >0、5xx 率超阈值；告警链路可触达 | ⬜ |
| **生产参数调优** | 用真实流量画像压测调 `worker_num` / `poolMaxActive` / `max_wait_time` | 有调优前后对比数据；最终参数写入部署文档 | ⬜ |
| **升级回滚演练** | 当前 tag → 下个版本 → 回滚 | 全程可服务；回滚 ≤1 条命令 | ⬜ |

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

## 6. Server 配置默认值规划（2026-11-09 通读官方手册后定稿，✅ 当日实施完成）

> 背景：`log_file/log_rotation` 默认值落地后，通读官方手册全量 102 项 server
> settings（`doc/references/swoole-docs/public/zh-cn/server/setting.md`）及
> runtime/http_server/version 章节，并以 4 组实测钉死关键结论。**重大发现**：
> ①异步风格 `Swoole\Http\Server` 的 `hook_flags` 默认**不 hook**（手册
> setting.md「hook_flags 默认：不 hook」+ 实测 PDO `SLEEP(1)` 阻塞 worker 事件
> 循环、`/ping` 被堵 0.6s），而 `Coroutine\run()` 路径默认 ALL（实测
> `getHookFlags()=2143287295`）——**本项目全部 211 个单测都在 Coroutine\run 里
> 跑，生产形态 Http\Server 的 hook 缺失从未被单测覆盖**；手册 setting.md:1520
> 明确要求「事件含阻塞函数时**必须提前配置 hook_flags**」；且旧版
> `Coroutine\Http\Server` 时代的 `hook_flags` 配置在多进程迁移（890e2a9）时
> 被弄丢——与 SO_REUSEPORT 同批丢失项。②`package_max_length` 默认 2MB 已实测
> 证实（3MB POST → 413）。③`dispatch_mode` 默认 2 是**有状态模式**（fd 固定
> worker），官方明确「无状态 Server 使用 1 或 3」。
> **暂缓执行（等 24h soak 验收，现已完成）**——配置改动只在下次服务重启生效。

### 6.1 配置体系规范（新增配置的决策树）

三条路径（现状已隐式存在，本次显式化为规范）：

1. **`settings` 数组**：Swoole 原生键 100% 透传，用户任意键；`applySettings()`
   用 `+=` 只补默认，**用户键永远胜出**（优先级契约，不变）：
   `用户 settings 键 > applySettings() 默认 > Swoole 自身默认`。
2. **`applySettings()` 默认**：只放「有证据的有主见默认」，每个默认块注释
   三要素——**为什么（含实测/手册证据）→ 行为说明 → 如何覆盖**；按主题分区：
   协议解析 → 协程 hook → 请求体/连接上限 → 优雅生命周期 → 日志。
3. **语义属性**：仅限「有派生逻辑/联动/防御性修正」（如 `workerNum` 0→核数、
   `gracefulTimeout` 联动 `reload_async`、`logFile` 默认路径/false 哨兵）或
   非 settings 域的项目行为（`watch`、`prewarm`、`documentRoot`）。**属性是
   特例不是常态**——纯直通的 Swoole 键不加属性，类体积停止增长。

### 6.2 A 类：进默认值（6 项，逐项带注释）

| 配置 | Swoole 默认 | 拟定默认 | 理由（含证据） |
|---|---|---|---|
| `hook_flags` | **不 hook**（Server 模式） | **显式 `SWOOLE_HOOK_ALL`** | 🔴 **保命配置，非可选**：手册 setting.md:1520 要求「事件含阻塞函数时必须提前配置 hook_flags」；实测 Server 模式默认 0 → PDO **阻塞 worker 事件循环**（协程池退化为串行）；旧版配置在多进程迁移丢失需回补；`SWOOLE_HOOK_ALL` 经 mysqlnd hook 覆盖 pdo_mysql/mysqli（runtime.md:657），与 Coroutine\run 路径行为对齐。**实施后基线（PDO SLEEP(0.2)、20 并发、4 worker）：hook ALL 92 qps vs hook 0 19 qps（4.8×）；注意 `settings['hook_flags']` 键在 6.2.x 不生效，须用 `Coroutine::set()`（start 前）——实现走 `$hookFlags` 属性** |
| `package_max_length` | 2MB（实测 3MB→413） | 显式 `2 * 1024 * 1024` | 不改行为；手册确认 HTTP GET 上限 8K 不可配、POST 超限直接断连；把「与 nginx `client_max_body_size` 对齐」从口头变成配置项（关联 §5.2 P2 安全清单） |
| `backlog` | 128 | `2048` | 突发连接时 SYN 队列溢出丢连接（压测 50+ 并发新建场景）；内核 `somaxconn` 取 min，放大无害 |
| `log_date_with_microseconds` | false | `true` | 多 worker 日志交错时排查时序必需（事件归因到毫秒）。⚠️ 键名以手册为准（`microseconds`，此前误写 `microtime`） |
| `display_errors` | 开 | `false` | PHP notice/warning 不泄露到响应/stdout；错误仍进 `log_file`（SWOOLE_LOG_ERROR）+ Yii 日志，消除双写 |

### 6.3 B 类：不加属性，文档「生产推荐 settings 清单」（8 项）

| 配置 | 默认 | 生产建议 | 为什么不默认 |
|---|---|---|---|
| `max_request` | 0（不重启） | 灰度泄漏兜底，如 `10000` | 手册定位「编码不规范泄漏的**临时**方案，应修复根因」；worker 重启重建应用+连接池；是否开启结合 24h soak / 灰度 RSS 曲线决定，配合 `reload_async` 在途不丢（PROCESS 模式不断连） |
| `max_concurrency` | 100000 | 与 `poolMaxActive × worker` 对齐 | v4.8.2+，HTTP1/2 **并发请求**上限，超限 503；默认改了会让突增流量被拒，应由生产显式设 |
| `max_coroutine` | 100000 | 与并发模型对齐 | 每 worker **协程**上限（两者是并列的不同键），超限同样 503；与 `max_concurrency` 一起构成过载保护 |
| `max_conn` / `max_connection` | **`ulimit -n`** | 生产调大 `nofile` 并显式声明 | 默认跟随系统 ulimit——生产文档要点：`nofile` 不调则连接上限受限 |
| `open_tcp_nodelay` | false | API 场景 `true` | nginx 前置内网收益有限，改 TCP 行为应显式 |
| `heartbeat_check_time` | 0 | `300` 左右 | 清理半开连接；会掐掉长 idle 的 keep-alive 连接（重连成本），默认关是对的 |
| `open_cpu_affinity` + `cpu_affinity_ignore` | false | 独立多核机可开（IO 密集可留 CPU0 给网络中断） | 容器/k8s cpuset 场景绑核反而出问题 |
| `socket_buffer_size` / buffer 系列 | 2M / UINT_MAX(v4.6.7+) | 慢客户端+海量 keep-alive 场景注意 | 最差 `max_conn × 2M` 内存（手册：发送缓冲塞满只影响当前客户端）；大响应场景知晓即可 |

### 6.4 C 类：不默认（职责在 nginx / 不适用）

`enable_static_handler`（实测被 Swoole 忽略，项目 PHP 层静态服务）、
`ssl_cert_file`/`ssl_key_file`/`open_http2_protocol`（TLS/h2 由 nginx 终结）、
`http_compression`（nginx gzip/brotli，swoole 层开是重复压缩）、
`daemonize`（**手册：systemd/supervisord 管理时勿设**；项目自管 `PidFile`）、
`pid_file`（项目自管，避免双 pid 语义）、`task_worker_num`（无 task 模型，
`queue/serve` 独立进程）、`tcp_fastopen`（内网无意义）、`request_slowlog_file`
（**v4.4.8 已移除，协程环境不可用**——慢请求检测由本项目 `slowQueryThreshold`
+ `/metrics` 直方图承担）、`stats_file`/`admin_server`（与 `/metrics` 重叠或商业功能）、
ZTS 多线程模式相关（`single_thread`/`bootstrap`/`init_arguments`，我们用多进程模式）。

### 6.5 实施清单（✅ 2026-11-09 全部完成）

- [x] 基线数据留档：PDO `SLEEP(0.2)`×20 并发×4 worker——hook ALL **92 qps**
      （avg 207ms）vs hook 0 **19 qps**（avg 973ms，串行排队），**4.8×**；
      `Coroutine\run` 内实测 `getHookFlags()=2143287295`，Server 模式实测 0 + 阻塞
- [x] A 类进 `applySettings()`：`package_max_length`(2M)/`backlog`(2048)/
      `log_date_with_microseconds`/`display_errors=false`；`hook_flags` 走
      `$hookFlags` 属性 + `start()` 内 `Coroutine::set()`（**实测
      `settings['hook_flags']` 键在 6.2.x 不生效**，且 6.2 忽略该键——两通道
      均验证）
- [x] `dispatch_mode` 2 vs 3 实测：QPS/延迟完全一致（92/92）→ 不默认改，
      移入 B 类文档（负载不均时改 3）
- [x] `HttpServerLogSettingsTest` 更名 `HttpServerDefaultsTest`，8 个用例
      （log/limits/microseconds+display_errors/hook 属性/用户键胜出）
- [x] **hook ALL 回归全绿**：phpunit 219/872 ✅、e2e 9/9 ✅、blog smoke 51/52
      （唯一失败=第 9 步 25 并发 burst，mac 平台限制——hook 0 对照下更差
      9/25 通过，Linux 52/52 已证；非 hook 回归）
- [x] B 类「生产推荐 settings 清单」写入 README + CHANGELOG Added
      （hook_flags 条目显著标注行为变更）
- [ ] soak.php 多实例口径（§3 P2，未做）

### 6.1 配置体系规范（新增配置的决策树）

三条路径（现状已隐式存在，本次显式化为规范）：

1. **`settings` 数组**：Swoole 原生键 100% 透传，用户任意键；`applySettings()`
   用 `+=` 只补默认，**用户键永远胜出**（优先级契约，不变）：
   `用户 settings 键 > applySettings() 默认 > Swoole 自身默认`。
2. **`applySettings()` 默认**：只放「有证据的有主见默认」，每个默认块注释
   三要素——**为什么（含实测证据）→ 行为说明 → 如何覆盖**；按主题分区：
   协议解析 → 协程 hook → 请求体/连接上限 → 优雅生命周期 → 日志。
3. **语义属性**：仅限「有派生逻辑/联动/防御性修正」（如 `workerNum` 0→核数、
   `gracefulTimeout` 联动 `reload_async`、`logFile` 默认路径/false 哨兵）或
   非 settings 域的项目行为（`watch`、`prewarm`、`documentRoot`）。**属性是
   特例不是常态**——纯直通的 Swoole 键不加属性，类体积停止增长。

### 6.2 A 类：进默认值（5 项，逐项带注释）

| 配置 | Swoole 默认 | 拟定默认 | 理由（含证据） |
|---|---|---|---|
| `hook_flags` | 隐式 ALL | 显式 `SWOOLE_HOOK_ALL` | 实测 PDO 协程化已生效；显式声明防 Swoole 未来改默认，自文档化、零行为变化 |
| `package_max_length` | 2MB（实测 3MB→413） | 显式 `2 * 1024 * 1024` | 不改行为；把「与 nginx `client_max_body_size` 对齐」从口头变成配置项（关联 §5.2 P2 安全清单） |
| `backlog` | 128 | `2048` | 突发连接时 SYN 队列溢出丢连接（压测 50+ 并发新建场景）；内核 `somaxconn` 取 min，放大无害 |
| `log_date_with_microtime` | false | `true` | 多 worker 日志交错时排查时序必需（事件归因到毫秒） |
| `display_errors` | 开 | `false` | PHP notice/warning 不泄露到响应/stdout；错误仍进 `log_file`（SWOOLE_LOG_ERROR）+ Yii 日志，消除双写 |

### 6.3 B 类：不加属性，文档「生产推荐 settings 清单」（6 项）

| 配置 | 默认 | 生产建议 | 为什么不默认 |
|---|---|---|---|
| `max_request` | 0（不重启） | 灰度泄漏兜底，如 `10000` | worker 重启重建应用+连接池；是否开启应结合 24h soak / 灰度 RSS 曲线决定，配合 `reload_async` 在途不丢 |
| `max_concurrency` | 100000 | 与 `poolMaxActive × worker` 对齐 | 超限 503；默认改了会让突增流量被拒，应由生产显式设 |
| `open_tcp_nodelay` | false | API 场景 `true` | nginx 前置内网收益有限，改 TCP 行为应显式 |
| `heartbeat_check_time` | 0 | `300` 左右 | 清理半开连接；会掐掉长 idle 的 keep-alive 连接（重连成本），默认关是对的 |
| `open_cpu_affinity` | false | 独立多核机可开 | 容器/k8s cpuset 场景绑核反而出问题 |
| `user`/`group` | — | root 启动时降权 | supervisor 以非 root 启动则不需要；部署职责 |
| `dispatch_mode` | 2（fd 固定，有状态模式） | 保持默认；负载不均时改 `3`（空闲优先，官方建议无状态 Server 用 1/3） | **实测（hook ALL 下 20 并发 PDO）：2 与 3 的 QPS/延迟完全一致（92/92 qps）**——无收益不默认改；生产若 nginx 长连接数少于 worker 数导致负载/池压力不均，再改 3 |

### 6.4 C 类：不默认（职责在 nginx / 不适用）

`enable_static_handler`（实测被 Swoole 忽略，项目 PHP 层静态服务）、
`ssl_cert_file`/`ssl_key_file`/`open_http2_protocol`（TLS/h2 由 nginx 终结）、
`http_compression`（nginx gzip/brotli，swoole 层开是重复压缩）、
`daemonize`/`pid_file`（项目自管 `PidFile`，避免双 pid 语义）、
`task_worker_num`（无 task 模型，`queue/serve` 独立进程）、`dispatch_mode`（默认 2 即可）。

### 6.5 实施清单（等 24h soak 验收后执行）

- [ ] A 类 5 项进 `applySettings()`，按 §6.1 分区注释规范逐块写注释
- [ ] `HttpServerLogSettingsTest` 更名 `HttpServerDefaultsTest`，作为所有默认
      settings 断言的家，用例从 4 个扩到 ~8 个
- [ ] B 类「生产推荐 settings 清单」写入 README 显式配置示例 + DEVELOPMENT 部署章节
- [ ] `display_errors=false` 行为变化复核：全量 e2e + blog smoke 无断言依赖
      （错误路径均走 Yii errorHandler，预期无影响）
- [ ] CHANGELOG Added 记录

---

## 7. 执行约定

1. **一任务一提交**：每个 ☑ 项独立提交，提交信息与改动一致（避免 `e74bbb3` 那类「信息与文件脱节」）。
2. **验证门槛**：全量 `phpunit` + `phpstan` + `php-cs-fixer` 通过，涉及池/运行时改动追加 soak 基线对比。
3. **文档同步**：完成任务后回填本文档状态（✅ + 日期），测试计数用脚本化方式更新而非手工 sed。
4. **多实例**：已定案（2026-11-08）——唯一形态为多端口 + nginx upstream；SO_REUSEPORT 同端口形态已移除（见 §3、§5.2 部署演练）。

---

## 8. 诚实边界

- 本计划**压低**「低级泄漏、上线即翻车」的风险，**不保证**零 bug。
- 常驻进程的坑是慢变量，`time_in_production` 无法被任何方案压缩。
- 外部变量（Swoole/PHP/MySQL/Redis 版本演进）不受本计划控制。
- 最终「敢上核心业务」的依据是**灰度期的观测数据 + 可回滚开关**，而非本文档本身。
