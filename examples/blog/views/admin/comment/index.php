<?php use app\models\Comment; use yii\helpers\Html; ?>
<?php $this->title = 'Comments admin'; ?>
<h1>Comments admin</h1>
<p><?= Html::a('&larr; back to posts', ['/admin/post/index']) ?></p>
<table border="1" cellpadding="6" cellspacing="0">
    <tr><th>ID</th><th>Post</th><th>Author</th><th>Comment</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($comments as $c): ?>
    <tr>
        <td><?= (int) $c->id ?></td>
        <td><?= Html::encode($c->post->title ?? '-') ?></td>
        <td><?= Html::encode($c->author_name) ?><br><span class="meta"><?= Html::encode($c->author_email) ?></span></td>
        <td><?= nl2br(Html::encode($c->content)) ?><br><span class="meta"><?= Html::encode($c->ip) ?></span></td>
        <td><?= (int) $c->status === Comment::STATUS_APPROVED ? 'approved' : 'pending' ?></td>
        <td>
            <?php if ((int) $c->status !== Comment::STATUS_APPROVED): ?>
                <?= Html::a('approve', ['approve', 'id' => $c->id], ['data-method' => 'post']) ?> &middot;
            <?php endif; ?>
            <?= Html::a('delete', ['delete', 'id' => $c->id], ['data-method' => 'post', 'data-confirm' => 'Delete?']) ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
