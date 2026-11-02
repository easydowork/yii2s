<?php use yii\helpers\Html; ?>
<h1><?= Html::encode($post->title) ?></h1>
<p><?= nl2br(Html::encode($post->content)) ?></p>
<p><?= Html::a('edit', ['update', 'id' => $post->id]) ?> ? <?= Html::a('back', ['index']) ?></p>
