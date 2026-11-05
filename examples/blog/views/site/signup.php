<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var app\models\RegisterForm $model */
$this->title = '注册';
?>
<div class="form-panel" style="max-width:460px;margin:0 auto">
    <h1 style="margin:0 0 1.2rem;font-size:1.4rem">创建账号</h1>
    <p class="muted" style="margin:0 0 1.4rem">注册后默认是「作者」角色，可登录后台由管理员提升权限。</p>
    <?php $form = ActiveForm::begin(); ?>
        <?= $form->field($model, 'username')->textInput(['placeholder' => '3-64 个字符']) ?>
        <?= $form->field($model, 'email')->input('email', ['placeholder' => 'you@example.com']) ?>
        <?= $form->field($model, 'password')->passwordInput(['placeholder' => '至少 6 位']) ?>
        <?= $form->field($model, 'password_repeat')->passwordInput(['placeholder' => '再输入一次']) ?>
        <div style="margin-top:.5rem">
            <?= Html::submitButton('注册', ['class' => 'btn']) ?>
            <span class="muted" style="margin-left:.8rem;font-size:.85rem">已有账号？<?= Html::a('登录', ['/admin/default/login']) ?></span>
        </div>
    <?php ActiveForm::end(); ?>
</div>
