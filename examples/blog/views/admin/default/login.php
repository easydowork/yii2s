<?php

use yii\helpers\Html;
use yii\widgets\ActiveForm;

/** @var app\models\LoginForm $model */
$this->title = '登录';
?>
<div class="form-panel" style="max-width:420px;margin:0 auto">
    <h1 style="margin:0 0 1.2rem;font-size:1.4rem">登录</h1>
    <?php $form = ActiveForm::begin(); ?>
        <?= $form->field($model, 'username')->textInput(['placeholder' => '用户名']) ?>
        <?= $form->field($model, 'password')->passwordInput(['placeholder' => '密码']) ?>
        <?= $form->field($model, 'rememberMe')->checkbox() ?>
        <div style="margin-top:.5rem">
            <?= Html::submitButton('登录', ['class' => 'btn']) ?>
            <span class="muted" style="margin-left:.8rem;font-size:.85rem">
                没有账号？<?= Html::a('注册', ['/site/signup']) ?> ·
                <a href="/admin">Vue3 后台</a>
            </span>
        </div>
    <?php ActiveForm::end(); ?>
    <p class="muted" style="font-size:.75rem;margin-top:1.2rem">demo 账号：admin/admin123 · editor/editor123 · author/author123</p>
</div>
