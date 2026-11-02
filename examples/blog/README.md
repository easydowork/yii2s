# Blog demo — real-world scenario on easydowork/yii2s

A complete blog application that exercises the package the way a real
project would: classic MVC pages, an authenticated admin panel with
CSRF-protected forms, a JSON REST API consumed by a Vue3 SPA, sessions on
Redis, migrations, and static assets - **all served by the coroutine
server with zero business-code adaptation**.

## Layout

```
config/            web / console / common (Bootstrap one-line integration)
controllers/       Site (front), admin/ (login + post CRUD), api/ (REST)
models/            Post, User (IdentityInterface), LoginForm
views/             PHP-rendered pages (classic MVC surface)
web/vue/           Vue3 SPA (CDN build, consumes the REST API)
migrations/        user + post tables with seed data
yii                console entry (migrations, swoole commands)
web/index.php      FPM-compatible entry - also works without this package
```

## Run it

```bash
cd examples/blog
composer install                       # path repo pulls the package from ../..

# initialize the database (adjust credentials in config/common.php)
php yii migrate/up --interactive=0

# serve with the coroutine server (development: hot reload)
php yii swoole/start --watch

# check the wiring first if something feels off
php yii swoole/check
```

| URL | What it exercises |
|---|---|
| `/` | Classic MVC rendering + pagination |
| `/admin/default/login` | Login form (CSRF + Redis-backed session) - admin / admin123 |
| `/admin/post/index` | Authenticated CRUD (`data-method` links need `YiiAsset` registered in the layout) |
| `/vue/index.html` | Vue3 SPA against the REST API (same session, writes allowed when logged in) |
| `/api/posts` | JSON REST surface (GET public, writes require the session) |

## Notes carried over from building this

- The layout must contain the standard beginPage()/head()/beginBody()/
  endBody()/endPage() calls and register YiiAsset - asset tags, the CSRF
  meta tags and the data-method link machinery all hang off those hooks.
- Asset URLs are rooted (/assets/...) - configure
  assetManager.baseUrl => /assets explicitly and serve web/ either via
  documentRoot (development) or nginx (production).
- @bower/@npm aliases must be registered when using
  yidas/yii2-bower-asset (its real assets live inside the package dir).
