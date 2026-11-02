<?php use yii\helpers\Html; ?>
<?php $this->title = $post->title; ?>
<h1><?= Html::encode($post->title) ?></h1>
<div class="meta">by <?= Html::encode($post->author->username ?? 'unknown') ?> ? <?= date('Y-m-d H:i', (int) $post->created_at) ?></div>
<p><?= nl2br(Html::encode($post->content)) ?></p>
<p><?= Html::a('? back', ['/site/index']) ?></p>
