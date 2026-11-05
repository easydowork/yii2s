<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var app\models\Post $post */
/** @var int $views */
/** @var app\models\Comment[] $comments */
/** @var app\models\Comment $comment */

$this->title = $post->title;
?>
<article class="article">
    <h1><?= Html::encode($post->title) ?></h1>
    <div class="meta">
        <?= Html::encode($post->author->username ?? 'unknown') ?>
        &middot; <?= date('Y-m-d H:i', (int) $post->created_at) ?>
        &middot; <?= (int) $views ?> 次浏览
        &middot; <?= count($comments) ?> 条评论
    </div>
    <?php if ($post->getCoverUrl() !== null): ?>
        <img class="post-cover" src="<?= Html::encode($post->getCoverUrl()) ?>" alt="<?= Html::encode($post->title) ?>">
    <?php endif; ?>
    <div class="body"><?= nl2br(Html::encode($post->content)) ?></div>
</article>

<div class="section-title">评论（<?= count($comments) ?>）</div>
<?php if ($comments === []): ?>
    <p class="muted">还没有通过的评论。</p>
<?php endif; ?>
<?php foreach ($comments as $item): ?>
    <div class="comment">
        <div class="meta"><?= Html::encode($item->author_name) ?> &middot; <?= date('Y-m-d H:i', (int) $item->created_at) ?></div>
        <p style="margin:.4rem 0 0"><?= nl2br(Html::encode($item->content)) ?></p>
    </div>
<?php endforeach; ?>

<div class="section-title">发表评论</div>
<div class="form-panel">
    <?php $form = ActiveForm::begin(['action' => ['/site/comment', 'id' => $post->id]]); ?>
        <?= $form->field($comment, 'author_name')->textInput(['maxlength' => 64, 'placeholder' => '你的名字']) ?>
        <?= $form->field($comment, 'author_email')->input('email', ['maxlength' => 128, 'placeholder' => 'you@example.com']) ?>
        <?= $form->field($comment, 'content')->textarea(['rows' => 4, 'maxlength' => 1000, 'placeholder' => '说点什么…']) ?>
        <?= Html::submitButton('提交评论', ['class' => 'btn']) ?>
    <?php ActiveForm::end(); ?>
</div>

<p style="margin-top:1.5rem"><?= Html::a('&larr; 返回首页', ['/site/index']) ?></p>
