<?php

namespace app\controllers\admin;

use app\models\Post;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class PostController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [['allow' => true, 'roles' => ['@']]],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => ['delete' => ['POST']],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $posts = Post::find()->orderBy(['created_at' => SORT_DESC])->all();

        return $this->render('index', ['posts' => $posts]);
    }

    public function actionCreate()
    {
        $post = new Post();
        $post->author_id = (int) Yii::$app->user->getId();

        if ($post->load(Yii::$app->request->post()) && $post->save()) {
            return $this->redirect(['view', 'id' => $post->id]);
        }

        return $this->render('create', ['post' => $post]);
    }

    public function actionUpdate(int $id)
    {
        $post = $this->findPost($id);

        if ($post->load(Yii::$app->request->post()) && $post->save()) {
            return $this->redirect(['view', 'id' => $post->id]);
        }

        return $this->render('update', ['post' => $post]);
    }

    public function actionView(int $id): string
    {
        return $this->render('view', ['post' => $this->findPost($id)]);
    }

    public function actionDelete(int $id)
    {
        $this->findPost($id)->delete();

        return $this->redirect(['index']);
    }

    private function findPost(int $id): Post
    {
        $post = Post::findOne($id);
        if ($post === null) {
            throw new NotFoundHttpException('Post not found.');
        }

        return $post;
    }
}
