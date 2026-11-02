<?php use yii\helpers\Html; use yii\widgets\LinkPager; ?>
<?php $this->title = 'Posts'; ?>
<h1>Posts</h1>
<?php foreach ($posts as $post): ?>
    <div class="post">
        <h3><?= Html::a(Html::encode($post->title), ['/site/view', 'id' => $post->id]) ?></h3>
        <div class="meta">by <?= Html::encode($post->author->username ?? 'unknown') ?> ? <?= date('Y-m-d', (int) $post->created_at) ?></div>
        <p><?= Html::encode(mb_substr($post->content, 0, 140)) ?>?</p>
    </div>
<?php endforeach; ?>
<?= LinkPager::widget(['pagination' => $pages]) ?>
