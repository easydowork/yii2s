<?php use app\models\Post; use yii\helpers\Html; ?>
<h1><?= Html::encode($post->title) ?></h1>
<div class="meta">
    status: <?= (int) $post->status === Post::STATUS_PUBLISHED ? 'published' : 'draft' ?>
    &middot; views: <?= (int) $post->views ?>
    &middot; comments: <?= $post->getCommentCount() ?>
</div>
<?php if ($post->getCoverUrl() !== null): ?>
    <p><img src="<?= Html::encode($post->getCoverUrl()) ?>" alt="" style="max-height:200px"></p>
<?php endif; ?>
<p><?= nl2br(Html::encode($post->content)) ?></p>
<p>
    <?= Html::a('edit', ['update', 'id' => $post->id]) ?>
    &middot; <?= Html::a('comments', ['/admin/comment/index']) ?>
    &middot; <?= Html::a('back', ['index']) ?>
</p>
