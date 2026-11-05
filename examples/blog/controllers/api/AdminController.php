<?php

namespace app\controllers\api;

use app\components\BlogJobs;
use app\models\Comment;
use app\models\LoginForm;
use app\models\Post;
use app\models\User;
use Yii;
use yii\db\Query;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * JSON API powering the Vue3 admin SPA. Authenticated with the normal session
 * (cookie) and CSRF-protected via the X-CSRF-Token header, exactly like the
 * classic admin panel - only the presentation moved to Vue.
 */
class AdminController extends Controller
{
    public function behaviors(): array
    {
        return [
            'verb' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'login' => ['POST'],
                    'logout' => ['POST'],
                    'me' => ['GET'],
                    'dashboard' => ['GET'],
                    'posts' => ['GET'],
                    'post-view' => ['GET'],
                    'post-create' => ['POST'],
                    'post-update' => ['PUT', 'PATCH'],
                    'post-delete' => ['DELETE'],
                    'comments' => ['GET'],
                    'comment-approve' => ['POST'],
                    'comment-delete' => ['DELETE'],
                    'users' => ['GET'],
                    'user-create' => ['POST'],
                    'user-update' => ['PUT', 'PATCH'],
                    'user-delete' => ['DELETE'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        // Login is public; everything else requires an authenticated user.
        if ($action->id !== 'login' && Yii::$app->user->getIsGuest()) {
            $this->fail(401, 'Authentication required.');

            return false;
        }

        return parent::beforeAction($action);
    }

    // --- auth ---

    public function actionLogin(): array
    {
        if (!Yii::$app->user->getIsGuest()) {
            return $this->userPayload();
        }

        $model = new LoginForm();
        $model->username = trim((string) Yii::$app->request->getBodyParam('username', ''));
        $model->password = (string) Yii::$app->request->getBodyParam('password', '');
        $model->rememberMe = (bool) Yii::$app->request->getBodyParam('rememberMe', true);

        if (!$model->login()) {
            return $this->fail(422, 'Login failed.', ['errors' => $model->getErrors()]);
        }

        // The session id is regenerated on login (session fixation protection),
        // so the CSRF token the SPA holds becomes stale. Re-issue it.
        return array_merge($this->userPayload(), [
            'csrf' => Yii::$app->request->getCsrfToken(),
        ]);
    }

    public function actionLogout(): array
    {
        Yii::$app->user->logout();

        return ['ok' => true];
    }

    public function actionMe(): array
    {
        return $this->userPayload();
    }

    // --- dashboard ---

    public function actionDashboard(): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }

        return [
            'posts' => (int) Post::find()->count(),
            'published' => (int) Post::find()->where(['status' => Post::STATUS_PUBLISHED])->count(),
            'drafts' => (int) Post::find()->where(['status' => Post::STATUS_DRAFT])->count(),
            'pendingComments' => (int) Comment::find()->where(['status' => Comment::STATUS_PENDING])->count(),
            'users' => (int) User::find()->count(),
            'totalViews' => (int) Post::find()->sum('views'),
        ];
    }

    // --- posts ---

    public function actionPosts(int $page = 1, int $perPage = 20, string $q = '', string $status = ''): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));

        $query = Post::find()->with('author')->orderBy(['created_at' => SORT_DESC]);
        if ($q !== '') {
            $query->andWhere(['like', 'title', $q]);
        }
        if ($status !== '' && in_array($status, ['published', 'draft'], true)) {
            $query->andWhere(['status' => $status === 'published' ? Post::STATUS_PUBLISHED : Post::STATUS_DRAFT]);
        }

        $total = (int) $query->count();

        return [
            'data' => array_map([$this, 'serializePost'], $query->offset(($page - 1) * $perPage)->limit($perPage)->all()),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'query' => $q, 'status' => $status],
        ];
    }

    public function actionPostView(int $id): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }
        $post = Post::find()->with('author')->where(['id' => $id])->one();

        return $post === null ? $this->fail(404, 'Post not found.') : $this->serializePost($post);
    }

    public function actionPostCreate(): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }

        $post = new Post();
        $post->title = trim((string) Yii::$app->request->getBodyParam('title', ''));
        $post->content = (string) Yii::$app->request->getBodyParam('content', '');
        $post->status = (int) Yii::$app->request->getBodyParam('status', Post::STATUS_PUBLISHED) === 0
            ? Post::STATUS_DRAFT
            : Post::STATUS_PUBLISHED;
        $post->author_id = (int) Yii::$app->user->getId();

        if (!$post->validate()) {
            return $this->fail(422, 'Validation failed.', ['errors' => $post->getErrors()]);
        }
        $post->save(false);
        $this->afterWrite($post);
        Yii::$app->response->statusCode = 201;

        return $this->serializePost($post);
    }

    public function actionPostUpdate(int $id): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }

        $post = Post::findOne($id);
        if ($post === null) {
            return $this->fail(404, 'Post not found.');
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

        return $this->serializePost($post);
    }

    public function actionPostDelete(int $id): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }

        $post = Post::findOne($id);
        if ($post === null) {
            return $this->fail(404, 'Post not found.');
        }
        $post->delete();
        $this->bumpCache();

        return ['deleted' => true, 'id' => $id];
    }

    // --- comments ---

    public function actionComments(int $page = 1, int $perPage = 20, string $status = ''): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));

        $query = Comment::find()->with('post')->orderBy(['id' => SORT_DESC]);
        if ($status !== '' && in_array($status, ['pending', 'approved'], true)) {
            $query->andWhere(['status' => $status === 'pending' ? Comment::STATUS_PENDING : Comment::STATUS_APPROVED]);
        }

        $total = (int) $query->count();

        return [
            'data' => array_map([$this, 'serializeComment'], $query->offset(($page - 1) * $perPage)->limit($perPage)->all()),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'status' => $status],
        ];
    }

    public function actionCommentApprove(int $id): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }
        $comment = Comment::findOne($id);
        if ($comment === null) {
            return $this->fail(404, 'Comment not found.');
        }
        $comment->status = Comment::STATUS_APPROVED;
        $comment->save(false);

        return $this->serializeComment($comment);
    }

    public function actionCommentDelete(int $id): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN, User::ROLE_EDITOR]);
        if ($denied !== null) {
            return $denied;
        }
        $comment = Comment::findOne($id);
        if ($comment === null) {
            return $this->fail(404, 'Comment not found.');
        }
        $comment->delete();

        return ['deleted' => true, 'id' => $id];
    }

    // --- users ---

    public function actionUsers(int $page = 1, int $perPage = 20, string $q = ''): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN]);
        if ($denied !== null) {
            return $denied;
        }
        $page = max(1, $page);
        $perPage = min(50, max(1, $perPage));

        $query = User::find()->orderBy(['id' => SORT_ASC]);
        if ($q !== '') {
            $query->andWhere(['like', 'username', $q]);
        }

        $total = (int) $query->count();

        return [
            'data' => array_map([$this, 'serializeUser'], $query->offset(($page - 1) * $perPage)->limit($perPage)->all()),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'query' => $q],
        ];
    }

    public function actionUserCreate(): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN]);
        if ($denied !== null) {
            return $denied;
        }

        $password = (string) Yii::$app->request->getBodyParam('password', '');
        if ($password === '') {
            return $this->fail(422, 'Password is required.');
        }

        $user = new User();
        $user->username = trim((string) Yii::$app->request->getBodyParam('username', ''));
        $user->email = (string) Yii::$app->request->getBodyParam('email', '');
        $user->role = (string) Yii::$app->request->getBodyParam('role', User::ROLE_AUTHOR);
        $user->status = (int) Yii::$app->request->getBodyParam('status', User::STATUS_ACTIVE) === 0
            ? User::STATUS_DISABLED
            : User::STATUS_ACTIVE;
        $user->auth_key = Yii::$app->security->generateRandomString();
        $user->setPassword($password);

        if (!$user->validate()) {
            return $this->fail(422, 'Validation failed.', ['errors' => $user->getErrors()]);
        }
        $user->save(false);
        Yii::$app->response->statusCode = 201;

        return $this->serializeUser($user);
    }

    public function actionUserUpdate(int $id): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN]);
        if ($denied !== null) {
            return $denied;
        }

        $user = User::findOne($id);
        if ($user === null) {
            return $this->fail(404, 'User not found.');
        }

        // Never allow an admin to demote/disable themselves out of the panel.
        if ($user->id === (int) Yii::$app->user->getId()) {
            return $this->fail(403, 'You cannot modify your own account here.');
        }

        $role = Yii::$app->request->getBodyParam('role');
        if ($role !== null) {
            $user->role = (string) $role;
        }
        $status = Yii::$app->request->getBodyParam('status');
        if ($status !== null) {
            $user->status = (int) $status === 0 ? User::STATUS_DISABLED : User::STATUS_ACTIVE;
        }
        $email = Yii::$app->request->getBodyParam('email');
        if ($email !== null) {
            $user->email = (string) $email;
        }

        if (!$user->validate()) {
            return $this->fail(422, 'Validation failed.', ['errors' => $user->getErrors()]);
        }
        $user->save(false);

        return $this->serializeUser($user);
    }

    public function actionUserDelete(int $id): array
    {
        $denied = $this->requireRole([User::ROLE_ADMIN]);
        if ($denied !== null) {
            return $denied;
        }

        $user = User::findOne($id);
        if ($user === null) {
            return $this->fail(404, 'User not found.');
        }
        if ($user->id === (int) Yii::$app->user->getId()) {
            return $this->fail(403, 'You cannot delete your own account.');
        }

        $user->delete();

        return ['deleted' => true, 'id' => $id];
    }

    // --- helpers ---

    /**
     * @param string[] $roles
     * @return array<string, mixed>|null error payload when denied, null when allowed
     */
    private function requireRole(array $roles): ?array
    {
        $user = Yii::$app->user->getIdentity();
        if ($user instanceof User && in_array($user->role, $roles, true)) {
            return null;
        }

        return $this->fail(403, 'You do not have permission to perform this action.');
    }

    private function afterWrite(Post $post): void
    {
        $this->bumpCache();
        try {
            Yii::$app->queue->push(['action' => 'post.saved', 'post_id' => (int) $post->id]);
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

    private function userPayload(): array
    {
        $user = Yii::$app->user->getIdentity();

        return [
            'guest' => $user === null,
            'user' => $user instanceof User ? $this->serializeUser($user) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePost(Post $post): array
    {
        return [
            'id' => (int) $post->id,
            'title' => (string) $post->title,
            'content' => (string) $post->content,
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
     * @return array<string, mixed>
     */
    private function serializeComment(Comment $comment): array
    {
        return [
            'id' => (int) $comment->id,
            'post_id' => (int) $comment->post_id,
            'post_title' => $comment->post !== null ? (string) $comment->post->title : null,
            'author_name' => (string) $comment->author_name,
            'author_email' => (string) $comment->author_email,
            'content' => (string) $comment->content,
            'status' => (int) $comment->status,
            'ip' => (string) $comment->ip,
            'created_at' => (int) $comment->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => (string) $user->username,
            'email' => $user->email !== null ? (string) $user->email : null,
            'role' => (string) $user->role,
            'role_label' => $user->getRoleLabel(),
            'status' => (int) $user->status,
            'posts' => (int) $user->getPosts()->count(),
            'created_at' => (int) $user->created_at,
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function fail(int $status, string $message, array $extra = []): array
    {
        $payload = array_merge(['error' => ['status' => $status, 'message' => $message]], $extra);
        $response = Yii::$app->response;
        $response->statusCode = $status;
        $response->format = Response::FORMAT_JSON;
        $response->data = $payload;

        return $payload;
    }
}
