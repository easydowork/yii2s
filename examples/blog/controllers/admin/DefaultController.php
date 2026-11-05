<?php

namespace app\controllers\admin;

use app\models\LoginForm;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;

class DefaultController extends Controller
{
    public function behaviors(): array
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['logout' => ['POST']],
            ],
        ];
    }

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

    /**
     * The Vue3 admin SPA (single-page, rendered without the classic layout).
     */
    public function actionVue()
    {
        $this->layout = false;

        return $this->render('//admin/vue');
    }

    /**
     * JSON probe used by the smoke test to check identity restoration with
     * normal session cookies, remember-me cookies, and tampered cookies.
     */
    public function actionWhoami(): array
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        return [
            'guest' => Yii::$app->user->getIsGuest(),
            'id' => Yii::$app->user->getId(),
        ];
    }
}
