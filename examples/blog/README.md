# Blog demo — real-world scenario on easydowork/yii2s

A complete blog application that exercises the package the way a real project
would: classic MVC pages, an authenticated admin panel with CSRF-protected
forms, cover uploads, moderated comments, a Redis view counter, a cached front
page, a queue worker, CSV downloads, and a JSON REST API consumed by a Vue3
SPA — **all served by the coroutine server with no changes to business code**.

## Layout

```
config/            web / console / common / params (Bootstrap one-line integration)
controllers/       Site (front + comments), admin/ (login, post CRUD, moderation), api/ (REST)
components/        BlogJobs (queue handler + list-cache version)
commands/          blog/audit, blog/cache-version (console)
models/            Post, Comment, User (IdentityInterface), LoginForm
views/             PHP-rendered pages (classic MVC surface)
migrations/        user + post + comment + audit_log tables with seed data
scripts/smoke.sh   end-to-end check of every feature below
web/               documentRoot: index.php (FPM entry), vue/ SPA, assets/, uploads/
yii                console entry (migrations, queue/serve, swoole commands)
```

## Run it

```bash
cd examples/blog
composer install                       # path repo pulls the package from ../..
php yii migrate/up --interactive=0     # creates the blog database tables
php yii swoole/start                   # coroutine server on 127.0.0.1:9501

# in another terminal: the queue worker (writes audit rows)
php yii queue/serve

# one-shot verification of every feature (boots its own server on :9602)
bash scripts/smoke.sh
```

`composer install` only fetches the demo's pure-PHP dependencies; if the
system PHP is older than 8.2 add `--ignore-platform-req=php
--ignore-platform-req=ext-swoole` and run everything through `swoole-cli`.

Demo credentials: **admin / admin123**; API key: `blog-demo-api-key`
(override with the `BLOG_API_KEY` environment variable).

## What each surface exercises

| URL / command | Package feature under test |
|---|---|
| `/` | MVC rendering, pagination, **CoroutineRedisCache** list cache (30s, version-busted) |
| `/site/view?id=1` | ActiveRecord with eager loading, **Redis INCR** view counter, comments |
| `POST /site/comment?id=1` | CSRF, form validation, insert, **flash**, redirect |
| `/site/nope` | error handler + `ErrorAction` + custom error view (404) |
| `/admin/default/login` | login form, **CoroutineSession**, session id regeneration, remember-me cookie |
| `/admin/post/index` | authenticated CRUD (`AccessControl`), `data-method` links |
| `POST /admin/post/create` (multipart) | **CoroutineUploadedFile** cover upload + static serving from `web/uploads/` |
| `/admin/post/export` | CSV download through `Response::sendFile()` (streamed body, `AFTER_SEND` cleanup) |
| `POST /admin/comment/approve` | moderation, `VerbFilter` (POST only), flash |
| `/api/posts` | JSON API, pagination + `q` search, no session/CSRF involved |
| `POST/PUT/DELETE /api/posts` | `X-Api-Key` auth (constant-time compare), ownership checks, 201/401/403/422 |
| `php yii queue/serve` | **CoroutineRedisQueue** worker; every publish pushes an audit job |
| `php yii blog/audit` | prints the rows the worker wrote (proves the round trip) |
| `php yii blog/queue-failure` + `blog/queue-stats` | failed job → `ttr` retry → dead-letter list |
| `/admin/post/tx-probe` (debug only) | post + audit insert rolled back by an FK violation |
| `/admin/default/whoami` | identity restoration from the session or the remember-me cookie |
| `/site/boom` (debug only) | uncaught exception → custom 500 page + log line |
| `/assets/...` | published AssetBundle files served with cache headers |
| `bash scripts/smoke.sh` | boots a server and runs all of the above, + 25 concurrent requests |

## 场景覆盖与缺口

`scripts/smoke.sh` 会回归下面这些场景：

| 域 | 已覆盖 |
|---|---|
| 路由/页面 | 规则匹配、分页、404 自定义错误页 |
| 会话/认证 | 登录、登出、CSRF、flash、后台 `AccessControl` |
| 内容 | 文章 CRUD、封面 `CoroutineUploadedFile` 上传、静态文件下发 |
| 互动 | 评论提交（待审）、后台审核/删除 |
| 数据 | AR + `with()` 关联、Redis 浏览量、Redis 列表缓存 + 版本失效 |
| 异步 | 发布入队、`queue/serve` 消费、审计落库；失败重试 3 次后进死信 |
| 事务 | 文章 + 审计同事务写入；FK 违例时两表一起回滚 |
| 认证 | 会话登录、remember-me 自动登录（仅 identity cookie）、篡改 cookie 被拒 |
| 表单 | 上传拒绝（错误扩展名 / 超 2MB）带可读错误提示 |
| 错误 | 404 与 500 都走自定义错误页，异常写入日志 |
| 资源 | `/assets/...` 已发布资源返回 200 + 缓存头 |
| 导出 | CSV `sendFile()` 全量下载 |
| API | API Key、分页/搜索、创建/更新/删除、越权保护 |
| 稳定性 | 25 并发全部 200、队列空跑、重复执行幂等 |

**尚未覆盖**（按建议优先级）：

1. 分页第 2 页与缓存命中/失效路径。
2. Redis 不可用时 session/cache/queue 的降级或明确报错。
3. 定时任务：`EVENT_BEFORE_START` + 协程循环的挂载示例。
4. RBAC：角色/权限（当前只有登录态校验）。

> 冒烟测试会真实制造一条死信（`blog/queue-stats` 的 `dead` 计数每跑一次 +1），
> 这是失败重试路径的演示产物，不影响功能。

不建议放进 demo 的：i18n、邮件/找回密码、`GridView` 等 UI 小部件、模块化、CORS、WebSocket（协程 HTTP 服务不支持）。

## Notes carried over from building this

- The layout must contain the standard beginPage()/head()/beginBody()/
  endBody()/endPage() calls and register YiiAsset — asset tags, the CSRF
  meta tags and the data-method link machinery all hang off those hooks.
- Asset URLs are rooted (`/assets/...`) - configure
  `assetManager.baseUrl => /assets` explicitly and serve `web/` either via
  `documentRoot` (development) or nginx (production).
- `@bower/@npm` aliases must be registered when using
  `yidas/yii2-bower-asset` (its real assets live inside the package dir).
- `yii\data\Pagination` reads the page number from the request; the
  controller passes it explicitly (`setPage($page - 1)`) so the cached list
  and the pager always agree.
- Uploads must go to `web/uploads/` (inside `documentRoot`) to be served by
  the PHP-level static handler; `CoroutineUploadedFile` is used because the
  stock `UploadedFile::saveAs()` relies on `move_uploaded_file()`, which
  rejects Swoole-written temp files.

> This is a demo: the seeded password, the demo API key and `root/root` DB
> credentials are for local testing only.
