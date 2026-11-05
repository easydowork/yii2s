<?php use yii\helpers\Html; use yii\widgets\LinkPager; ?>
<?php $this->title = '首页'; ?>
<h1 style="font-size:1.6rem;margin:.4rem 0 1.4rem">最新文章</h1>

<?php if ($posts === []): ?>
    <div class="post-card muted">还没有文章。</div>
<?php endif; ?>

<?php foreach ($posts as $post): ?>
    <article class="post-card">
        <h2><?= Html::a(Html::encode($post->title), ['/site/view', 'id' => $post->id]) ?></h2>
        <div class="meta">
            <?= Html::encode($post->author->username ?? 'unknown') ?>
            &middot; <?= date('Y-m-d', (int) $post->created_at) ?>
            &middot; <?= (int) $post->getCommentCount() ?> 条评论
        </div>
        <?php if ($post->getCoverUrl() !== null): ?>
            <img class="post-cover" src="<?= Html::encode($post->getCoverUrl()) ?>" alt="<?= Html::encode($post->title) ?>">
        <?php endif; ?>
        <p class="excerpt"><?= Html::encode(mb_substr((string) $post->content, 0, 160)) ?>&hellip;</p>
    </article>
<?php endforeach; ?>

<?= LinkPager::widget(['pagination' => $pages, 'options' => ['class' => 'pagination']]) ?>
