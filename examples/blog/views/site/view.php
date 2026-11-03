<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var app\models\Post $post */
/** @var int $views */
/** @var app\models\Comment[] $comments */
/** @var app\models\Comment $comment */

$this->title = $post->title;
?>
<h1><?= Html::encode($post->title) ?></h1>
<div class="meta">
    by <?= Html::encode($post->author->username ?? 'unknown') ?>
    &middot; <?= date('Y-m-d H:i', (int) $post->created_at) ?>
    &middot; <?= (int) $views ?> views
    &middot; <?= count($comments) ?> comments
</div>

<?php if ($post->getCoverUrl() !== null): ?>
    <p><img src="<?= Html::encode($post->getCoverUrl()) ?>" alt="" style="max-width:100%"></p>
<?php endif; ?>

<p><?= nl2br(Html::encode($post->content)) ?></p>

<h2>Comments (<?= count($comments) ?>)</h2>
<?php if ($comments === []): ?>
    <p class="meta">No approved comments yet.</p>
<?php endif; ?>
<?php foreach ($comments as $item): ?>
    <div class="post">
        <div class="meta">
            <?= Html::encode($item->author_name) ?>
            &middot; <?= date('Y-m-d H:i', (int) $item->created_at) ?>
        </div>
        <p><?= nl2br(Html::encode($item->content)) ?></p>
    </div>
<?php endforeach; ?>

<h2>Leave a comment</h2>
<?php $form = ActiveForm::begin(['action' => ['/site/comment', 'id' => $post->id]]); ?>
    <?= $form->field($comment, 'author_name')->textInput(['maxlength' => 64]) ?>
    <?= $form->field($comment, 'author_email')->input('email', ['maxlength' => 128]) ?>
    <?= $form->field($comment, 'content')->textarea(['rows' => 4, 'maxlength' => 1000]) ?>
    <?= Html::submitButton('Submit comment', ['class' => 'btn']) ?>
<?php ActiveForm::end(); ?>

<p><?= Html::a('&larr; back', ['/site/index']) ?></p>
