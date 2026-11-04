<?php use yii\helpers\Html; ?>
<!-- LAYOUT-V2-CHECK -->
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= Html::csrfMetaTags() ?>
    <title><?= Html::encode($this->title ?? 'Blog') ?></title>
    <?php \yii\web\YiiAsset::register($this) ?>
    <?php $this->head() ?>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 760px; margin: 2rem auto; padding: 0 1rem; color: #222; }
        nav a { margin-right: 1rem; }
        .post { border-bottom: 1px solid #ddd; padding: .5rem 0; }
        .meta { color: #777; font-size: .85rem; }
        label { display: block; margin-top: .8rem; }
        input[type=text], textarea { width: 100%; padding: .4rem; }
        .btn { padding: .4rem .9rem; margin-top: .8rem; }
        .flash { background: #e6ffe6; padding: .5rem; margin: .5rem 0; }
        .flash-error { background: #ffecec; }
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
<nav>
    <?= Html::a('Home', ['/site/index']) ?>
    <?= Html::a('Vue SPA', ['/site/vue']) ?>
    <?php if ($guest): ?>
        <?= Html::a('Login', ['/admin/default/login']) ?>
    <?php else: ?>
        <?= Html::a('Admin', ['/admin/post/index']) ?>
        <?= Html::a('Comments', ['/admin/comment/index']) ?>
        <?= Html::a('Logout (' . Html::encode($identity->username) . ')', ['/admin/default/logout'], ['data-method' => 'post']) ?>
    <?php endif; ?>
</nav>
<hr>
<?php foreach ($flashes as $type => $message): ?>
    <div class="flash flash-<?= Html::encode((string) $type) ?>"><?= Html::encode(is_array($message) ? implode(' ', $message) : (string) $message) ?></div>
<?php endforeach; ?>
<?= $content ?>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
