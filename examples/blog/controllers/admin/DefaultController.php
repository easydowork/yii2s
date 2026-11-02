<?php

namespace app\controllers\admin;

use app\models\LoginForm;
use Yii;
use yii\web\Controller;

class DefaultController extends Controller
{
    public function actionLogin()
    {
        if (!Yii::$app->user->getIsGuest()) {
            return $this->redirect(['/admin/post/index']);
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->redirect(['/admin/post/index']);
        }

        return $this->render('login', ['model' => $model]);
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();

        return $this->redirect(['/site/index']);
    }
}
