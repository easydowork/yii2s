<?php use yii\helpers\Html; use yii\widgets\ActiveForm; ?>
<?php $form = ActiveForm::begin(); ?>
    <?= $form->field($post, 'title')->textInput() ?>
    <?= $form->field($post, 'content')->textarea(['rows' => 10]) ?>
    <?= $form->field($post, 'status')->dropDownList([0 => 'draft', 1 => 'published']) ?>
    <div><?= Html::submitButton('Save', ['class' => 'btn']) ?></div>
<?php ActiveForm::end(); ?>
