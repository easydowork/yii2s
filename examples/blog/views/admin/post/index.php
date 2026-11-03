<?php use app\models\Post; use yii\helpers\Html; ?>
<?php $this->title = 'Posts admin'; ?>
<h1>Posts admin</h1>
<p>
    <?= Html::a('New post', ['create'], ['class' => 'btn']) ?>
    <?= Html::a('Comments', ['/admin/comment/index'], ['class' => 'btn']) ?>
    <?= Html::a('Export CSV', ['export']) ?>
</p>
<table border="1" cellpadding="6" cellspacing="0">
    <tr><th>ID</th><th>Title</th><th>Status</th><th>Views</th><th>Comments</th><th>Actions</th></tr>
    <?php foreach ($posts as $p): ?>
    <tr>
        <td><?= (int) $p->id ?></td>
        <td><?= Html::a(Html::encode($p->title), ['view', 'id' => $p->id]) ?></td>
        <td><?= $p->status === Post::STATUS_PUBLISHED ? 'published' : 'draft' ?></td>
        <td><?= (int) $p->views ?></td>
        <td><?= $p->getCommentCount() ?></td>
        <td>
            <?= Html::a('edit', ['update', 'id' => $p->id]) ?>
            &middot; <?= Html::a('delete', ['delete', 'id' => $p->id], ['data-method' => 'post', 'data-confirm' => 'Delete?']) ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
