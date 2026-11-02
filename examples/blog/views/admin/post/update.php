<?php $this->title = 'Edit: ' . $post->title; ?>
<h1>Edit post</h1>
<?= $this->render('_form', ['post' => $post]) ?>
