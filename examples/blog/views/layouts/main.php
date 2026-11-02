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
        .flash { background: #e6ffe6; padding: .5rem; }
    </style>
</head>
<body>
<?php $this->beginBody() ?>
<nav>
    <?= Html::a('Home', ['/site/index']) ?>
    <?= Html::a('Vue SPA', ['/site/vue']) ?>
    <?php if (Yii::$app->user->getIsGuest()): ?>
        <?= Html::a('Login', ['/admin/default/login']) ?>
    <?php else: ?>
        <?= Html::a('Admin', ['/admin/post/index']) ?>
        <?= Html::a('Logout (' . Html::encode(Yii::$app->user->getIdentity()->username) . ')', ['/admin/default/logout'], ['data-method' => 'post']) ?>
    <?php endif; ?>
</nav>
<hr>
<?php if (Yii::$app->session->hasFlash('success')): ?>
    <div class="flash"><?= Html::encode(Yii::$app->session->getFlash('success')) ?></div>
<?php endif; ?>
<?= $content ?>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
