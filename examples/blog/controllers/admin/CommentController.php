<?php

namespace app\controllers\admin;

use app\models\Comment;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Comment moderation. Every action is a POST (data-method links) so the
 * CSRF token issued by the layout is actually exercised.
 */
class CommentController extends Controller
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
                'actions' => [
                    'approve' => ['POST'],
                    'delete' => ['POST'],
                ],
            ],
        ];
    }

    public function actionIndex(): string
    {
        $comments = Comment::find()
            ->with('post')
            ->orderBy(['id' => SORT_DESC])
            ->all();

        return $this->render('index', ['comments' => $comments]);
    }

    public function actionApprove(int $id)
    {
        $comment = $this->findComment($id);
        $comment->status = Comment::STATUS_APPROVED;
        $comment->save(false);
        Yii::$app->session->setFlash('success', 'Comment approved.');

        return $this->redirect(['index']);
    }

    public function actionDelete(int $id)
    {
        $this->findComment($id)->delete();
        Yii::$app->session->setFlash('success', 'Comment deleted.');

        return $this->redirect(['index']);
    }

    private function findComment(int $id): Comment
    {
        $comment = Comment::findOne($id);
        if ($comment === null) {
            throw new NotFoundHttpException('Comment not found.');
        }

        return $comment;
    }
}
