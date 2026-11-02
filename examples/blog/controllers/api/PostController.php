<?php

namespace app\controllers\api;

use app\models\Post;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class PostController extends Controller
{
    public $enableCsrfValidation = false; // API surface; authenticated by session cookie on writes

    public function actionIndex(): array
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        return Post::find()->where(['status' => Post::STATUS_PUBLISHED])->orderBy(['created_at' => SORT_DESC])->all();
    }

    public function actionCreate(): array
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->user->getIsGuest()) {
            Yii::$app->response->statusCode = 401;

            return ['error' => 'login required'];
        }

        $post = new Post();
        $post->author_id = (int) Yii::$app->user->getId();
        $post->load(Yii::$app->request->post(), '');

        return $post->save()
            ? ['id' => $post->id, 'title' => $post->title]
            : ['error' => $post->errors];
    }

    public function actionView(int $id): array
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $post = Post::findOne(['id' => $id, 'status' => Post::STATUS_PUBLISHED]);
        if ($post === null) {
            throw new NotFoundHttpException('Post not found.');
        }

        return $post->toArray();
    }

    public function actionDelete(int $id): array
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (Yii::$app->user->getIsGuest()) {
            Yii::$app->response->statusCode = 401;

            return ['error' => 'login required'];
        }

        $post = Post::findOne($id);
        if ($post === null) {
            throw new NotFoundHttpException('Post not found.');
        }
        $post->delete();

        return ['deleted' => true];
    }
}
