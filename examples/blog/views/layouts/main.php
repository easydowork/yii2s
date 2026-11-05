<?php use yii\helpers\Html; ?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= Html::csrfMetaTags() ?>
    <title><?= Html::encode($this->title ? ($this->title . ' · ') : '') ?>Yii2S Blog</title>
    <?php \yii\web\YiiAsset::register($this) ?>
    <?php $this->head() ?>
    <style>
        :root {
            --bg:#f8fafc; --panel:#ffffff; --text:#0f172a; --muted:#64748b;
            --accent:#6366f1; --accent2:#818cf8; --border:#e2e8f0; --ok:#16a34a; --err:#dc2626;
        }
        * { box-sizing:border-box; }
        body { margin:0; font-family:'Inter',system-ui,-apple-system,'Segoe UI',sans-serif; background:var(--bg); color:var(--text); line-height:1.65; }
        a { color:var(--accent); text-decoration:none; }
        a:hover { color:var(--accent2); }
        .topnav { position:sticky; top:0; z-index:50; background:rgba(255,255,255,.85); backdrop-filter:blur(10px); border-bottom:1px solid var(--border); }
        .topnav-inner { max-width:860px; margin:0 auto; padding:.9rem 1.2rem; display:flex; align-items:center; gap:1.4rem; }
        .brand { font-weight:800; font-size:1.15rem; color:var(--text); letter-spacing:-.02em; }
        .brand span { color:var(--accent); }
        .navlinks { display:flex; gap:1.2rem; align-items:center; flex:1; }
        .navlinks a { color:var(--muted); font-size:.92rem; font-weight:500; }
        .navlinks a:hover { color:var(--text); }
        .navlinks a.btn { color:#fff; }
        .who { color:var(--muted); font-size:.85rem; margin-left:auto; }
        .container { max-width:860px; margin:2rem auto; padding:0 1.2rem; }
        .btn { display:inline-block; background:var(--accent); color:#fff; padding:.5rem 1rem; border-radius:8px; font-size:.9rem; font-weight:600; border:none; cursor:pointer; }
        .btn:hover { background:var(--accent2); color:#fff; }
        .btn.ghost { background:transparent; border:1px solid var(--border); color:var(--text); }
        .flash { padding:.8rem 1rem; border-radius:10px; margin-bottom:1.2rem; font-size:.9rem; }
        .flash-success { background:#dcfce7; color:#166534; }
        .flash-error { background:#fee2e2; color:#991b1b; }
        .post-card { background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:1.4rem 1.6rem; margin-bottom:1.2rem; box-shadow:0 1px 3px rgba(0,0,0,.04); transition:box-shadow .15s; }
        .post-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.08); }
        .post-card h2 { margin:0 0 .5rem; font-size:1.3rem; }
        .post-card h2 a { color:var(--text); }
        .post-card h2 a:hover { color:var(--accent); }
        .post-cover { width:100%; border-radius:10px; margin:.8rem 0; max-height:320px; object-fit:cover; }
        .meta { color:var(--muted); font-size:.85rem; }
        .excerpt { color:#334155; margin:.6rem 0 0; }
        .pagination { display:flex; gap:.4rem; justify-content:center; margin-top:1.5rem; }
        .pagination a, .pagination span { padding:.4rem .8rem; border-radius:8px; border:1px solid var(--border); background:var(--panel); font-size:.9rem; }
        .pagination .active { background:var(--accent); color:#fff; border-color:var(--accent); }
        .article { background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:2rem; }
        .article h1 { margin:0 0 .6rem; font-size:1.9rem; line-height:1.3; }
        .article .body { margin:1.2rem 0; font-size:1.05rem; color:#1e293b; }
        .comment { border-top:1px solid var(--border); padding:1rem 0; }
        .form-panel { background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:1.6rem; }
        .field { margin-bottom:1rem; }
        .field label { display:block; font-size:.85rem; font-weight:600; color:var(--muted); margin-bottom:.35rem; }
        .field input, .field textarea { width:100%; padding:.55rem .7rem; border:1px solid var(--border); border-radius:8px; font-size:.95rem; font-family:inherit; }
        .field input:focus, .field textarea:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(99,102,241,.12); }
        .field .help-block { color:var(--err); font-size:.8rem; }
        .field.has-error input, .field.has-error textarea { border-color:var(--err); }
        .section-title { font-size:1.15rem; font-weight:700; margin:1.6rem 0 .8rem; }
        .muted { color:var(--muted); }
        footer { text-align:center; color:var(--muted); font-size:.8rem; padding:2rem 0 3rem; }
    </style>
</head>
<body>
<?php $this->beginBody() ?>
<?php
// The nav (identity) and flash messages both read the session, which is
// Redis-backed. When Redis is down the error page itself must still render,
// so resolve them defensively and fall back to a guest nav + no flash.
try {
    $guest = Yii::$app->user->getIsGuest();
    $identity = $guest ? null : Yii::$app->user->getIdentity();
    $flashes = Yii::$app->session->getAllFlashes();
} catch (\Throwable $e) {
    $guest = true;
    $identity = null;
    $flashes = [];
}
?>
<header class="topnav">
    <div class="topnav-inner">
        <a class="brand" href="/">Yii2S<span>·Blog</span></a>
        <nav class="navlinks">
            <a href="/">首页</a>
            <?php if ($guest): ?>
                <a href="/signup">注册</a>
                <a href="/admin/default/login">登录</a>
            <?php else: ?>
                <a href="/admin">后台</a>
                <a href="/admin/default/logout" data-method="post">退出</a>
            <?php endif; ?>
        </nav>
        <?php if (!$guest): ?>
            <span class="who"><?= Html::encode($identity->username) ?></span>
        <?php endif; ?>
    </div>
</header>

<main class="container">
    <?php foreach ($flashes as $type => $message): ?>
        <div class="flash flash-<?= Html::encode((string) $type) ?>"><?= Html::encode(is_array($message) ? implode(' ', $message) : (string) $message) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>

<footer>Yii2S Swoole demo · resident-memory coroutine blog · built with <?= Html::a('easydowork/yii2s', 'https://github.com/yii2s/yii2-swoole') ?></footer>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
