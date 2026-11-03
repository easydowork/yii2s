<?php

namespace app\controllers\api;

use app\components\BlogJobs;
use app\models\Post;
use app\models\User;
use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

/**
 * JSON API.
 *
 * Reads are public; writes require `X-Api-Key` (see config/params.php). The
 * key is compared with hash_equals against the configured value and the actor
 * is resolved without touching the session - that is why CSRF validation is
 * disabled here: no cookie is used for authorization.
 */
class PostController extends Controller
{
    public $enableCsrfValidation = false;

    private ?User $actor = null;

    public function behaviors(): array
    {
        return [
            'verb' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['GET'],
                    'view' => ['GET'],
                    'create' => ['POST'],
                    'update' => ['PUT', 'PATCH'],
                    'delete' => ['DELETE'],
                ],
            ],
        ];
    }

    public function beforeAction($action)
    {
        // The application default is HTML; every response here is JSON.
        Yii::$app->response->format = Response::FORMAT_JSON;

        if (in_array($action->id, ['create', 'update', 'delete'], true)) {
            $provided = (string) Yii::$app->request->getHeaders()->get('X-Api-Key', '');
            $expected = (string) (Yii::$app->params['apiKey'] ?? '');

            if ($expected === '' || !hash_equals($expected, $provided)) {
                $this->fail(401, 'A valid X-Api-Key header is required.');

                return false; // the response is already prepared
            }

            $this->actor = User::findOne(['username' => 'admin']);
            if ($this->actor === null) {
                $this->fail(500, 'Seed user missing - run yii migrate/up.');

                return false;
            }
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(int $page = 1, int $perPage = 20, string $q = ''): array
    {
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));

        $query = Post::find()
            ->with('author')
            ->where(['status' => Post::STATUS_PUBLISHED])
            ->orderBy(['created_at' => SORT_DESC]);
        if ($q !== '') {
            $query->andWhere(['like', 'title', $q]);
        }

        $total = (int) $query->count();
        $posts = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return [
            'data' => array_map([$this, 'serialize'], $posts),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'query' => $q],
        ];
    }

    public function actionView(int $id): array
    {
        $post = Post::find()
            ->with('author')
            ->where(['id' => $id, 'status' => Post::STATUS_PUBLISHED])
            ->one();

        return $post === null ? $this->fail(404, 'Post not found.') : $this->serialize($post);
    }

    public function actionCreate(): array
    {
        $post = new Post();
        // Only these two are taken from the payload; author and status are set
        // by the server (mass assignment and author spoofing are impossible).
        $post->title = trim((string) Yii::$app->request->getBodyParam('title', ''));
        $post->content = (string) Yii::$app->request->getBodyParam('content', '');
        $post->status = (int) Yii::$app->request->getBodyParam('status', Post::STATUS_PUBLISHED) === 0
            ? Post::STATUS_DRAFT
            : Post::STATUS_PUBLISHED;
        $post->author_id = (int) $this->actor()->getId();

        if (!$post->validate()) {
            return $this->fail(422, 'Validation failed.', ['errors' => $post->getErrors()]);
        }
        $post->save(false);

        $this->afterWrite($post);
        Yii::$app->response->statusCode = 201;

        return $this->serialize($post);
    }

    public function actionUpdate(int $id): array
    {
        $post = Post::findOne($id);
        if ($post === null) {
            return $this->fail(404, 'Post not found.');
        }
        if ((int) $post->author_id !== (int) $this->actor()->getId()) {
            return $this->fail(403, 'You can only edit your own posts.');
        }

        $title = Yii::$app->request->getBodyParam('title');
        if ($title !== null) {
            $post->title = trim((string) $title);
        }
        $content = Yii::$app->request->getBodyParam('content');
        if ($content !== null) {
            $post->content = (string) $content;
        }
        $status = Yii::$app->request->getBodyParam('status');
        if ($status !== null) {
            $post->status = (int) $status === 0 ? Post::STATUS_DRAFT : Post::STATUS_PUBLISHED;
        }

        if (!$post->validate()) {
            return $this->fail(422, 'Validation failed.', ['errors' => $post->getErrors()]);
        }
        $post->save(false);

        $this->afterWrite($post);

        return $this->serialize($post);
    }

    public function actionDelete(int $id): array
    {
        $post = Post::findOne($id);
        if ($post === null) {
            return $this->fail(404, 'Post not found.');
        }
        if ((int) $post->author_id !== (int) $this->actor()->getId()) {
            return $this->fail(403, 'You can only delete your own posts.');
        }

        $post->delete();
        $this->bumpCache();

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * Post-commit side effects: the write is already durable, so Redis
     * failures are logged instead of failing the API call.
     */
    private function afterWrite(Post $post): void
    {
        $this->bumpCache();

        try {
            Yii::$app->queue->push(['action' => 'post.published', 'post_id' => (int) $post->id]);
        } catch (\Throwable $e) {
            Yii::error('Queue push failed after saving post ' . $post->id . ': ' . $e->getMessage(), __METHOD__);
        }
    }

    private function bumpCache(): void
    {
        try {
            BlogJobs::invalidateListCache();
        } catch (\Throwable $e) {
            Yii::error('List cache invalidation failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Post $post): array
    {
        return [
            'id' => (int) $post->id,
            'title' => (string) $post->title,
            'excerpt' => mb_substr((string) $post->content, 0, 160),
            'status' => (int) $post->status,
            'views' => (int) $post->views,
            'comments' => $post->getCommentCount(),
            'author' => $post->author !== null ? (string) $post->author->username : null,
            'cover' => $post->getCoverUrl(),
            'created_at' => (int) $post->created_at,
            'updated_at' => (int) $post->updated_at,
        ];
    }

    /**
     * Sets the JSON error response and returns the payload for the action.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function fail(int $status, string $message, array $extra = []): array
    {
        $payload = array_merge(['error' => ['status' => $status, 'message' => $message]], $extra);
        $response = Yii::$app->response;
        $response->statusCode = $status;
        $response->format = Response::FORMAT_JSON;
        // Needed when beforeAction() short-circuits: no action result will be
        // assigned to the response in that case.
        $response->data = $payload;

        return $payload;
    }

    private function actor(): User
    {
        if ($this->actor === null) {
            // Only reachable if beforeAction() was bypassed; fail closed.
            throw new \yii\web\UnauthorizedHttpException('API key required.');
        }

        return $this->actor;
    }
}
