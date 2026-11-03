<?php use yii\helpers\Html; use yii\widgets\LinkPager; ?>
<?php $this->title = 'Posts'; ?>
<h1>Posts</h1>
<?php foreach ($posts as $post): ?>
    <div class="post">
        <?php if ($post->getCoverUrl() !== null): ?>
            <img src="<?= Html::encode($post->getCoverUrl()) ?>" alt="" style="max-height:80px;float:right;margin-left:1rem">
        <?php endif; ?>
        <h3><?= Html::a(Html::encode($post->title), ['/site/view', 'id' => $post->id]) ?></h3>
        <div class="meta">
            by <?= Html::encode($post->author->username ?? 'unknown') ?>
            &middot; <?= date('Y-m-d', (int) $post->created_at) ?>
            &middot; <?= (int) $post->getCommentCount() ?> comments
        </div>
        <p><?= Html::encode(mb_substr((string) $post->content, 0, 140)) ?>&hellip;</p>
    </div>
<?php endforeach; ?>
<?= LinkPager::widget(['pagination' => $pages]) ?>
