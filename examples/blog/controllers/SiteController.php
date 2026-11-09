<?php

namespace app\controllers;

use app\components\BlogJobs;
use app\models\Comment;
use app\models\Post;
use app\models\RegisterForm;
use Yii;
use yii\data\Pagination;
use yii\web\Controller;
use yii\web\ErrorAction;
use yii\web\NotFoundHttpException;

class SiteController extends Controller
{
    private const PAGE_SIZE = 5;

    public function actions(): array
    {
        return [
            'error' => ['class' => ErrorAction::class],
        ];
    }

    /**
     * Cached front page. The pagination is built explicitly: yii\data\Pagination
     * reads the page from $request->getQueryParams() (safe here), but the page
     * number is passed in so the cached payload and the pager always agree.
     */
    public function actionIndex(int $page = 1): string
    {
        $page = max(1, $page);
        $version = BlogJobs::listVersion();

        $data = Yii::$app->cache->getOrSet(
            'blog:index:' . $version . ':' . $page,
            function () use ($page): array {
                $query = Post::find()
                    ->with('author')
                    ->where(['status' => Post::STATUS_PUBLISHED])
                    ->orderBy(['created_at' => SORT_DESC]);

                $total = (int) $query->count();
                $pagination = new Pagination(['totalCount' => $total, 'pageSize' => self::PAGE_SIZE]);
                $pagination->setPage($page - 1);

                return [
                    'total' => $total,
                    'posts' => $query->offset($pagination->offset)->limit($pagination->limit)->all(),
                ];
            },
            30
        );

        $pages = new Pagination(['totalCount' => $data['total'], 'pageSize' => self::PAGE_SIZE]);
        $pages->setPage($page - 1);

        return $this->render('index', ['posts' => $data['posts'], 'pages' => $pages]);
    }

    public function actionView(int $id): string
    {
        $post = $this->findPublished($id);

        return $this->render('view', [
            'post' => $post,
            'views' => $this->countView($post),
            'comments' => Comment::approvedFor((int) $post->id),
            'comment' => new Comment(['post_id' => (int) $post->id]),
        ]);
    }

    /**
     * Public comment form: CSRF-protected POST -> validation -> pending row ->
     * flash -> redirect (the classic Yii2 flow, unchanged under coroutines).
     */
    public function actionComment(int $id)
    {
        $post = $this->findPublished($id);

        if (!Yii::$app->request->getIsPost()) {
            Yii::$app->session->setFlash('error', 'Comments must be submitted through the form.');

            return $this->redirect(['view', 'id' => $post->id]);
        }

        $comment = new Comment(['post_id' => (int) $post->id]);
        if ($comment->load(Yii::$app->request->post()) && $comment->validate()) {
            // Never trust the payload for these three fields.
            $comment->post_id = (int) $post->id;
            $comment->status = Comment::STATUS_PENDING;
            $comment->ip = (string) Yii::$app->request->getUserIP();
            $comment->save(false);

            Yii::$app->session->setFlash('success', 'Comment submitted - it will appear after moderation.');

            return $this->redirect(['view', 'id' => $post->id]);
        }

        return $this->render('view', [
            'post' => $post,
            'views' => $this->countView($post, false),
            'comments' => Comment::approvedFor((int) $post->id),
            'comment' => $comment,
        ]);
    }

    public function actionVue()
    {
        // Vue3 SPA served as a static file via documentRoot
        return $this->redirect('/vue/index.html');
    }

    /**
     * Security demo: echoes the client IP resolved by Yii. Behind nginx with
     * `trustedProxies` configured, this returns the REAL client IP from
     * `X-Forwarded-For`/`X-Real-IP` instead of the proxy's 127.0.0.1.
     */
    public function actionIp(): string
    {
        return json_encode(['ip' => Yii::$app->request->getUserIP()]);
    }

    /**
     * Security demo: RateLimiter in front of an API-ish endpoint.
     * 10 requests per 10s per client IP; over the limit answers 429.
     */
    public function actionApi(): void
    {
        // Built inline: component definitions resolve through the DI
        // container (see the note in config/web.php), and the redis
        // application component lives in the service locator, not there.
        $limiter = new \Yii2S\Swoole\Resilience\RateLimiter(
            Yii::$app->get('redis')
        );
        $key = 'api:' . Yii::$app->request->getUserIP();

        if (!$limiter->allow($key, 10, 10)) {
            Yii::$app->response->statusCode = 429;
            Yii::$app->response->content = 'rate limited';
            return;
        }

        Yii::$app->response->content = 'ok';
    }

    /**
     * Public signup: a new account starts as an `author` and is logged in.
     */
    public function actionSignup()
    {
        if (!Yii::$app->user->getIsGuest()) {
            return $this->redirect(['index']);
        }

        $model = new RegisterForm();
        if ($model->load(Yii::$app->request->post()) && ($user = $model->register()) !== null) {
            Yii::$app->user->login($user, 0);
            Yii::$app->session->setFlash('success', 'Welcome, ' . $user->username . '! Your account is ready.');

            return $this->redirect(['index']);
        }

        return $this->render('signup', ['model' => $model]);
    }

    /**
     * Dev-only 500 probe: proves uncaught exceptions reach the configured
     * error page (`errorHandler.errorAction`) and are written to the log.
     */
    public function actionBoom(): void
    {
        if (!YII_DEBUG) {
            throw new NotFoundHttpException('Not available.');
        }

        throw new \RuntimeException('blog-boom-for-error-page');
    }

    private function findPublished(int $id): Post
    {
        $post = Post::find()
            ->with('author')
            ->where(['id' => $id, 'status' => Post::STATUS_PUBLISHED])
            ->one();
        if ($post === null) {
            throw new NotFoundHttpException('Post not found.');
        }

        return $post;
    }

    /**
     * Redis-backed counter on top of the database baseline. Two round trips
     * per page view - a small but realistic hot-path load for the pool.
     */
    private function countView(Post $post, bool $increment = true): int
    {
        $key = 'blog:views:' . $post->id;
        if ($increment) {
            Yii::$app->redis->incr($key);
        }

        $delta = Yii::$app->redis->get($key);

        return (int) $post->views + (is_numeric($delta) ? (int) $delta : 0);
    }
}
