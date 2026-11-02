<?php

namespace app\controllers;

use app\models\Post;
use yii\data\Pagination;
use yii\web\Controller;

class SiteController extends Controller
{
    public function actionIndex(): string
    {
        $query = Post::find()->where(['status' => Post::STATUS_PUBLISHED])->orderBy(['created_at' => SORT_DESC]);

        $pages = new Pagination(['totalCount' => (int) $query->count(), 'pageSize' => 5]);
        $posts = $query->offset($pages->offset)->limit($pages->limit)->all();

        return $this->render('index', ['posts' => $posts, 'pages' => $pages]);
    }

    public function actionVue()
    {
        // Vue3 SPA served as a static file via documentRoot
        return $this->redirect('/vue/index.html');
    }

    public function actionView(int $id): string
    {
        $post = Post::findOne(['id' => $id, 'status' => Post::STATUS_PUBLISHED]);
        if ($post === null) {
            throw new \yii\web\NotFoundHttpException('Post not found.');
        }

        return $this->render('view', ['post' => $post]);
    }
}
