<?php

namespace app\controllers\admin;

use app\components\BlogJobs;
use app\models\Post;
use Yii;
use Yii2S\Swoole\Runtime\CoroutineUploadedFile;
use yii\db\Query;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\FileHelper;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PostController extends Controller
{
    private const COVER_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    private const COVER_MAX_BYTES = 2 * 1024 * 1024;

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
        $posts = Post::find()->with('author')->orderBy(['created_at' => SORT_DESC])->all();

        return $this->render('index', ['posts' => $posts]);
    }

    public function actionCreate()
    {
        $post = new Post(['author_id' => (int) Yii::$app->user->getId()]);
        $oldCover = $post->cover;

        if ($post->load(Yii::$app->request->post())
            && $post->validate()
            && $this->storeCover($post)
            && $this->persist($post)
        ) {
            $this->deleteCoverFileByPath($oldCover);
            $this->afterContentChange($post);
            Yii::$app->session->setFlash('success', 'Post created.');

            return $this->redirect(['view', 'id' => $post->id]);
        }

        return $this->render('create', ['post' => $post]);
    }

    public function actionUpdate(int $id)
    {
        $post = $this->findPost($id);
        $oldCover = $post->cover;

        if ($post->load(Yii::$app->request->post())
            && $post->validate()
            && $this->storeCover($post)
            && $this->persist($post)
        ) {
            $this->deleteCoverFileByPath($oldCover);
            $this->afterContentChange($post);
            Yii::$app->session->setFlash('success', 'Post updated.');

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
        $post = $this->findPost($id);
        $this->deleteCoverFileByPath($post->cover);
        $post->delete();

        $this->bumpListCache();
        Yii::$app->session->setFlash('success', 'Post deleted.');

        return $this->redirect(['index']);
    }

    /**
     * CSV export through Response::sendFile(): exercises the streamed-body
     * path (and the AFTER_SEND event used to clean the temp file).
     */
    public function actionExport(): Response
    {
        $handle = fopen('php://temp', 'r+');
        // PHP 8.4 deprecates relying on the default $escape; pass it explicitly.
        fputcsv($handle, ['id', 'title', 'status', 'views', 'comments', 'created_at'], ',', '"', '');
        foreach (Post::find()->orderBy(['id' => SORT_ASC])->each() as $post) {
            fputcsv($handle, [
                $post->id,
                $post->title,
                $post->status === Post::STATUS_PUBLISHED ? 'published' : 'draft',
                $post->views,
                $post->getCommentCount(),
                date('c', (int) $post->created_at),
            ], ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $path = sys_get_temp_dir() . '/blog-posts-' . date('Ymd-His') . '.csv';
        file_put_contents($path, $csv);

        $response = Yii::$app->response->sendFile($path, 'posts-' . date('Ymd-His') . '.csv', [
            'mimeType' => 'text/csv',
        ]);
        $response->on(Response::EVENT_AFTER_SEND, static function () use ($path): void {
            @unlink($path);
        });

        return $response;
    }

    /**
     * Writes the post and its synchronous audit row in one transaction.
     *
     * Both statements must run on the same pooled connection (coroutine
     * stickiness); a failure in either rolls both back.
     */
    private function persist(Post $post): bool
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            if (!$post->save(false)) {
                $transaction->rollBack();

                return false;
            }
            $db->createCommand()->insert('{{%audit_log}}', [
                'action' => 'post.saved',
                'post_id' => (int) $post->id,
                'message' => 'saved "' . $post->title . '" by admin',
                'created_at' => time(),
            ])->execute();
            $transaction->commit();

            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Dev-only transaction probe: inserts a post + audit row, then violates
     * the comment->post foreign key and reports whether everything rolled
     * back on the same connection.
     */
    public function actionTxProbe(): array
    {
        if (!YII_DEBUG) {
            throw new NotFoundHttpException('Not available.');
        }
        Yii::$app->response->format = Response::FORMAT_JSON;

        $db = Yii::$app->db;
        $postsBefore = (int) Post::find()->count();
        $auditBefore = (int) (new Query())->from('{{%audit_log}}')->count();

        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()->insert('{{%post}}', [
                'title' => 'tx-probe',
                'content' => 'must roll back',
                'status' => Post::STATUS_DRAFT,
                'author_id' => (int) (Yii::$app->user->getId() ?: 1),
                'created_at' => time(),
                'updated_at' => time(),
            ])->execute();
            $db->createCommand()->insert('{{%audit_log}}', [
                'action' => 'tx.probe',
                'post_id' => null,
                'message' => 'must roll back',
                'created_at' => time(),
            ])->execute();
            // Deliberate FK violation (fk-comment-post) to force a rollback.
            $db->createCommand()->insert('{{%comment}}', [
                'post_id' => 999999,
                'author_name' => 'tx',
                'author_email' => 'tx@example.com',
                'content' => 'fk violation',
                'status' => 0,
                'ip' => '',
                'created_at' => time(),
            ])->execute();
            $transaction->commit();

            return ['rolledBack' => false, 'error' => null];
        } catch (\Throwable $e) {
            $transaction->rollBack();

            return [
                'rolledBack' => true,
                'error' => mb_substr($e->getMessage(), 0, 100),
                'postsUnchanged' => (int) Post::find()->count() === $postsBefore,
                'auditUnchanged' => (int) (new Query())->from('{{%audit_log}}')->count() === $auditBefore,
            ];
        }
    }

    /**
     * Stores the uploaded cover (if any) through the coroutine-safe upload
     * class and points the post at the public path.
     */
    private function storeCover(Post $post): bool
    {
        $file = CoroutineUploadedFile::getInstance($post, 'cover');
        if ($file === null) {
            return true;
        }

        if ($file->getHasError()) {
            $post->addError('cover', 'Upload failed (code ' . $file->error . ').');

            return false;
        }
        if (!in_array($file->getExtension(), self::COVER_EXTENSIONS, true)) {
            $post->addError('cover', 'Only png/jpg/jpeg/webp/gif are allowed.');

            return false;
        }
        if ((int) $file->size > self::COVER_MAX_BYTES) {
            $post->addError('cover', 'The cover must be smaller than 2 MB.');

            return false;
        }

        $directory = Yii::getAlias('@app/web/uploads');
        FileHelper::createDirectory($directory);

        $name = 'cover-' . bin2hex(random_bytes(6)) . '.' . $file->getExtension();
        if (!$file->saveAs($directory . '/' . $name)) {
            $post->addError('cover', 'Could not store the uploaded file.');

            return false;
        }

        // The previous file is removed only after the post is persisted (see
        // the controllers), so a failed save cannot leave a broken cover URL.
        $post->cover = 'uploads/' . $name;

        return true;
    }

    private function deleteCoverFileByPath(?string $cover): void
    {
        if ($cover === null || $cover === '') {
            return;
        }

        $path = Yii::getAlias('@app/web') . '/' . ltrim($cover, '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Post-commit side effects: cache invalidation and the queue job.
     *
     * The database write already succeeded, so a Redis hiccup must be logged
     * instead of turning a successful save into a 500.
     */
    private function afterContentChange(Post $post): void
    {
        $this->bumpListCache();

        try {
            Yii::$app->queue->push([
                'action' => (int) $post->status === Post::STATUS_PUBLISHED ? 'post.published' : 'post.saved',
                'post_id' => (int) $post->id,
            ]);
        } catch (\Throwable $e) {
            Yii::error('Queue push failed after saving post ' . $post->id . ': ' . $e->getMessage(), __METHOD__);
        }
    }

    private function bumpListCache(): void
    {
        try {
            BlogJobs::invalidateListCache();
        } catch (\Throwable $e) {
            Yii::error('List cache invalidation failed: ' . $e->getMessage(), __METHOD__);
        }
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
