<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest\compat;

use Yii;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use yii\db\Query;
use yii\web\Controller;
use yii\web\Cookie;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * One action per Yii2 feature point. The compatibility suite drives these
 * through a real Swoole server so the whole request pipeline is exercised,
 * not just the component in isolation.
 */
class CompatController extends Controller
{
    public function actionIndex(): array
    {
        return ['app' => 'yii2stest-compat', 'route' => 'compat/index'];
    }

    // --- routing -------------------------------------------------------

    public function actionHello(string $name = 'world'): array
    {
        return ['name' => $name, 'url' => Yii::$app->request->getUrl()];
    }

    public function actionPost(int $id = 0): array
    {
        return ['id' => $id];
    }

    public function actionUrls(): array
    {
        return [
            'relative' => \yii\helpers\Url::to(['/compat/hello', 'name' => 'bob']),
            'absolute' => \yii\helpers\Url::to(['/compat/hello', 'name' => 'bob'], true),
            'baseUrl' => Yii::$app->request->getBaseUrl(),
            'hostInfo' => Yii::$app->request->getHostInfo(),
        ];
    }

    // --- request -------------------------------------------------------

    public function actionRequest(): array
    {
        $request = Yii::$app->request;

        return [
            'method' => $request->getMethod(),
            'query' => $request->getQueryParams(),
            'post' => $request->getBodyParams(),
            'header' => $request->getHeaders()->get('X-Compat'),
            'userAgent' => $request->getUserAgent(),
            // Proves the Bootstrap auto-replacement: the app-auto config omits
            // the 'class' key, so this asserts the coroutine request/response
            // classes were wired up without any explicit declaration.
            'requestClass' => get_class($request),
            'responseClass' => get_class(Yii::$app->response),            'contentType' => $request->getContentType(),
            'isAjax' => $request->getIsAjax(),
            'pathInfo' => $request->getPathInfo(),
            'url' => $request->getUrl(),
            'hostInfo' => $request->getHostInfo(),
            'userIP' => $request->getUserIP(),
            'cookies' => array_keys($request->getCookies()->toArray()),
            'rawBodySha1' => sha1($request->getRawBody()),
        ];
    }

    // --- response ------------------------------------------------------

    public function actionFormat(string $as = 'json'): mixed
    {
        $response = Yii::$app->response;
        switch ($as) {
            case 'xml':
                $response->format = Response::FORMAT_XML;

                return ['item' => ['name' => 'compat', 'id' => 7]];
            case 'html':
                $response->format = Response::FORMAT_HTML;

                return '<h1>compat-html</h1>';
            case 'raw':
                $response->format = Response::FORMAT_RAW;
                $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
                $response->content = 'compat-raw';

                return $response;
            default:
                $response->format = Response::FORMAT_JSON;

                return ['format' => 'json', 'nested' => ['a' => 1]];
        }
    }

    public function actionStatus(): array
    {
        Yii::$app->response->statusCode = 201;
        Yii::$app->response->headers->set('X-Compat', 'yes');

        return ['created' => true];
    }

    public function actionRedirect(): Response
    {
        return $this->redirect('/compat/target', 302);
    }

    public function actionTarget(): array
    {
        return ['at' => 'target'];
    }

    public function actionDownload(): Response
    {
        $path = sys_get_temp_dir() . '/yii2stest-compat-download.txt';
        file_put_contents($path, str_repeat('0123456789', 100)); // 1000 bytes

        return Yii::$app->response->sendFile($path, 'compat-download.txt', ['mimeType' => 'text/plain']);
    }

    // --- cookies / CSRF ------------------------------------------------

    public function actionCookie(string $set = ''): array
    {
        if ($set !== '') {
            Yii::$app->response->cookies->add(new Cookie(['name' => 'compat_cookie', 'value' => $set]));
        }

        return ['value' => Yii::$app->request->getCookies()->getValue('compat_cookie')];
    }

    public function actionCsrf(): array
    {
        return [
            'token' => Yii::$app->request->getCsrfToken(),
            'param' => Yii::$app->request->csrfParam,
        ];
    }

    public function actionSubmit(): array
    {
        return ['post' => Yii::$app->request->getBodyParams()];
    }

    // --- session / user ------------------------------------------------

    public function actionSession(): array
    {
        $session = Yii::$app->session;
        $hits = (int) $session->get('hits', 0) + 1;
        $session->set('hits', $hits);

        return ['hits' => $hits, 'id' => $session->getId(), 'active' => $session->getIsActive()];
    }

    public function actionFlash(string $do = 'set'): array
    {
        $session = Yii::$app->session;
        // Reading with $delete=true consumes it in this request.
        $before = $session->getFlash('notice', null, true);
        if ($do === 'set') {
            $session->setFlash('notice', 'saved');
        }

        return ['before' => $before, 'stored' => $session->get('notice')];
    }

    public function actionLogin(int $id = 1): array
    {
        $identity = CompatIdentity::findIdentity($id);
        if ($identity === null) {
            throw new NotFoundHttpException('No such user.');
        }
        $before = Yii::$app->session->get('hits');
        Yii::$app->user->login($identity);

        return [
            'id' => Yii::$app->user->getId(),
            'guest' => Yii::$app->user->getIsGuest(),
            'session' => Yii::$app->session->getId(),
            'sessionDataKept' => Yii::$app->session->get('hits') === $before,
        ];
    }

    public function actionWhoami(): array
    {
        $user = Yii::$app->user;
        $identity = $user->getIdentity();

        return [
            'guest' => $user->getIsGuest(),
            'id' => $user->getId(),
            'authKey' => $identity?->getAuthKey(),
        ];
    }

    public function actionLogout(): array
    {
        Yii::$app->user->logout();

        return ['guest' => Yii::$app->user->getIsGuest()];
    }

    // --- uploads -------------------------------------------------------

    public function actionUpload(): array
    {
        $report = static function (string $field): array {
            $files = [];
            foreach (UploadedFile::getInstancesByName($field) as $file) {
                $files[] = [
                    'name' => $file->name,
                    'size' => $file->size,
                    'error' => $file->error,
                    'sha1' => $file->error === UPLOAD_ERR_OK ? sha1((string) file_get_contents((string) $file->tempName)) : null,
                ];
            }

            return $files;
        };

        return ['upload' => $report('upload'), 'files' => $report('files')];
    }

    /**
     * Stores an upload through [[\Yii2S\Swoole\Runtime\CoroutineUploadedFile]]
     * and reports the resulting payload hash.
     */
    public function actionUploadSave(): array
    {
        $file = \Yii2S\Swoole\Runtime\CoroutineUploadedFile::getInstanceByName('upload');
        if ($file === null) {
            return ['saved' => false, 'reason' => 'no-file'];
        }

        $target = sys_get_temp_dir() . '/compat-saved-' . bin2hex(random_bytes(6)) . '.bin';
        $saved = $file->saveAs($target);
        $sha1 = is_file($target) ? sha1((string) file_get_contents($target)) : null;
        @unlink($target);

        return [
            'saved' => $saved,
            'name' => $file->name,
            'error' => $file->error,
            'sha1' => $sha1,
            'tempStillThere' => is_file((string) $file->tempName),
            'extension' => $file->getExtension(),
        ];
    }

    // --- errors --------------------------------------------------------

    public function actionError404(): void
    {
        throw new NotFoundHttpException('compat-nope');
    }

    public function actionError500(): void
    {
        throw new \RuntimeException('compat-boom');
    }

    // --- resident services ---------------------------------------------

    public function actionDb(): array
    {
        $db = Yii::$app->db;
        $before = (int) (new Query())->from('{{%customer}}')->count('*', $db);

        $tx = $db->beginTransaction();
        try {
            $db->createCommand()->insert('{{%customer}}', [
                'name' => 'compat-tx',
                'email' => 'compat@example.com',
                'created_at' => time(),
            ])->execute();
            $insertedId = (int) $db->getLastInsertID();
            $tx->rollBack();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }

        $after = (int) (new Query())->from('{{%customer}}')->count('*', $db);

        return ['before' => $before, 'after' => $after, 'rolledBack' => $before === $after, 'insertedId' => $insertedId];
    }

    public function actionCache(): array
    {
        $value = ['rand' => random_int(1, 100000), 'at' => time()];
        Yii::$app->cache->set('compat:cache', $value, 30);

        return ['hit' => Yii::$app->cache->get('compat:cache') === $value, 'value' => $value];
    }

    public function actionRedis(): array
    {
        /** @var \Yii2S\Swoole\Redis\CoroutineRedisConnection $redis */
        $redis = Yii::$app->get('redis');
        $redis->set('compat:redis', 'ok');
        $redis->incr('compat:redis:counter');

        return [
            'value' => $redis->get('compat:redis'),
            'counter' => (int) $redis->get('compat:redis:counter'),
        ];
    }

    /**
     * Fans out into sub-coroutines that each borrow a pooled DB connection:
     * proves per-coroutine pool stickiness and request-state isolation.
     */
    public function actionConcurrent(): array
    {
        $waitGroup = new WaitGroup();
        $values = [];
        for ($i = 0; $i < 5; $i++) {
            $waitGroup->add();
            Coroutine::create(function () use ($i, &$values, $waitGroup): void {
                try {
                    $values[$i] = (int) Yii::$app->db
                        ->createCommand('SELECT :i AS i', [':i' => $i])
                        ->queryScalar();
                } finally {
                    $waitGroup->done();
                }
            });
        }
        $waitGroup->wait();
        ksort($values);

        return ['values' => array_values($values)];
    }

    public function actionLog(): array
    {
        Yii::info('compat-log-entry', 'compat');

        return ['logged' => true];
    }

    public function actionSlow(int $ms = 300): array
    {
        Coroutine::sleep($ms / 1000);

        return ['slept' => $ms, 'query' => Yii::$app->request->get('tag')];
    }
}
