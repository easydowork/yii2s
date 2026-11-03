<?php use yii\helpers\Html; use yii\widgets\ActiveForm; ?>
<?php $form = ActiveForm::begin(['options' => ['enctype' => 'multipart/form-data']]); ?>
    <?= $form->field($post, 'title')->textInput(['maxlength' => 200]) ?>
    <?= $form->field($post, 'content')->textarea(['rows' => 10]) ?>
    <?= $form->field($post, 'status')->dropDownList([0 => 'draft', 1 => 'published']) ?>
    <?= $form->field($post, 'cover')->fileInput() ?>
    <?php if ($post->getCoverUrl() !== null): ?>
        <div class="meta">Current cover:</div>
        <div><img src="<?= Html::encode($post->getCoverUrl()) ?>" alt="" style="max-height:120px"></div>
    <?php endif; ?>
    <div><?= Html::submitButton('Save', ['class' => 'btn']) ?></div>
<?php ActiveForm::end(); ?>
