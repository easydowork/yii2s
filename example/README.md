# Yii2S Swoole 最小示例

这是插件的**最小接入示例**，用来演示「一行接入」的核心卖点：一个普通的 Yii2
应用配置，只要加一条 `Bootstrap`，stock 组件（`yii\db\Connection`、
`yii\redis\Connection`、`yii\web\Request` 等）就会被自动替换成协程版，其余配置
原样保留。

> 想要完整的真实业务场景（MVC、REST API、session/CSRF、上传、队列），请看
> [`../examples/blog`](../examples/blog)。

## 跑起来

```bash
# 1. 初始化演示数据库（需要本地 MySQL）
php init-db.php

# 2. 启动服务器
php yii swoole/start            # 默认 127.0.0.1:9501

# 3. 访问
curl http://127.0.0.1:9501/           # 纯框架 JSON（压测用）
curl http://127.0.0.1:9501/demo/db    # 带 DB 查询（2 条 SQL）
curl http://127.0.0.1:9501/demo/redis # 带 Redis（3 命令）
curl http://127.0.0.1:9501/ping       # raw 路由（绕开 Yii 生命周期）
```

## 端点

| 端点 | 说明 |
|---|---|
| `/` | 纯框架响应（路由 + JSON 序列化），压测基线 |
| `/demo/db` | 2 条 SQL 查询，走连接池 |
| `/demo/redis` | 3 条 Redis 命令，走连接池 |
| `/ping` | raw 路由，不创建 Yii request/response |
| `/metrics` | Prometheus 指标（跨 worker 聚合 + 系统负载） |
| `/__pool` | 连接池指标 JSON |

## 关键点：`config/web.php`

看配置里的 `db` / `redis` 组件——它们写的是 **stock 类**（`yii\db\Connection`、
`yii\redis\Connection`），而不是协程类：

```php
'db' => [
    'class' => \yii\db\Connection::class,   // ← stock 类，Bootstrap 自动换成协程版
    'dsn' => 'mysql:host=127.0.0.1;dbname=yii2s_demo',
    // ...
    'poolMaxActive' => 20,                  // ← 协程版才认识的池配置
],
```

这正是「对现有 Yii2 应用透明」的含义：**业务配置不用改成协程类**，加一条
`Bootstrap` 就完成了接入。控制器代码（`app/controllers/`）也是普通的 Yii2 写法。

## 与 `examples/blog` 的区别

| | 本目录 | `examples/blog` |
|---|---|---|
| 定位 | 最小接入示例 + 压测基准 | 完整业务场景演示 |
| 演示核心 | 一行接入、stock 类自动替换 | 协程组件的完整用法 |
| 控制器 | 复用根目录 `app/controllers/` | 自带 controllers/models/migrations |

## 压测

```bash
# 内置协程压测器（100 并发 5s）
swoole-cli scripts/bench.php --path=/ --concurrency=100 --duration=5

# 或一键跑三个端点的基准（起服务 + 压 / 、/demo/db、/demo/redis）
bash scripts/bench.sh
```

实测基线（macOS + swoole-cli，12 worker，100 并发 5s）：`/` 约 2.4 万 QPS、
`/demo/db` 约 1 万、`/demo/redis` 约 0.8 万，池零超时零泄漏。
