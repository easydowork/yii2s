<?php use yii\helpers\Html; use yii\widgets\ActiveForm; ?>
<?php $this->title = 'Login'; ?>
<h1>Login</h1>
<?php $form = ActiveForm::begin(); ?>
    <?= $form->field($model, 'username')->textInput() ?>
    <?= $form->field($model, 'password')->passwordInput() ?>
    <?= $form->field($model, 'rememberMe')->checkbox() ?>
    <div><?= Html::submitButton('Login', ['class' => 'btn']) ?></div>
<?php ActiveForm::end(); ?>
