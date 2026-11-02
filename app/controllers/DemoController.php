<?php

declare(strict_types=1);

namespace app\controllers;

use Swoole\Coroutine;
use Yii;
use yii\web\Controller;

/**
 * Demo endpoints exercising each coroutine-safe component.
 */
class DemoController extends Controller
{
    public function actionDb(): array
    {
        $row = Yii::$app->db->createCommand('SELECT COUNT(*) FROM user')->queryScalar();
        $users = Yii::$app->db->createCommand('SELECT id, name FROM user ORDER BY id LIMIT 5')->queryAll();

        return ['count' => (int) $row, 'users' => $users];
    }

    public function actionTransaction(): array
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            $db->createCommand()->insert('user', [
                'name' => 'tx-' . bin2hex(random_bytes(3)),
                'created_at' => time(),
            ])->execute();
            $id = $db->getLastInsertID();
            $transaction->commit();

            return ['committed' => true, 'id' => (int) $id];
        } catch (\Throwable $e) {
            $transaction->rollBack();
            return ['committed' => false, 'error' => $e->getMessage()];
        }
    }

    public function actionRedis(): array
    {
        Yii::$app->redis->set('demo:key', 'hello-from-swoole');
        $value = Yii::$app->redis->get('demo:key');
        Yii::$app->redis->incr('demo:counter');

        return [
            'value' => $value,
            'counter' => (int) Yii::$app->redis->get('demo:counter'),
        ];
    }

    public function actionCache(): array
    {
        $key = 'demo-cache-' . date('Ymd');
        Yii::$app->cache->set($key, ['time' => time(), 'rand' => random_int(1, 999)], 60);

        return ['key' => $key, 'value' => Yii::$app->cache->get($key)];
    }

    public function actionSession(): array
    {
        $session = Yii::$app->session;
        $session->open();
        $count = $session->get('visits', 0) + 1;
        $session->set('visits', $count);

        return ['visits' => $count, 'sessionId' => $session->getId()];
    }

    /**
     * Runs 10 parallel coroutine DB queries to demonstrate non-blocking I/O:
     * total wall time should be close to the slowest query, not the sum.
     */
    public function actionConcurrent(): array
    {
        $start = microtime(true);
        $results = [];
        $wg = new \Swoole\Coroutine\WaitGroup();

        for ($i = 1; $i <= 10; $i++) {
            $wg->add();
            Coroutine::create(function () use ($wg, $i, &$results): void {
                try {
                    $results[$i] = Yii::$app->db
                        ->createCommand('SELECT SLEEP(0.1), ' . $i . ' AS worker')->queryOne();
                } catch (\Throwable $e) {
                    $results[$i] = ['error' => $e->getMessage()];
                } finally {
                    $wg->done();
                }
            });
        }

        $wg->wait();
        ksort($results);

        return [
            'workers' => count($results),
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
            'pool_idle' => Yii::$app->db->getPool()->getIdleCount(),
            'pool_size' => Yii::$app->db->getPool()->getSize(),
            'sample' => $results[1] ?? null,
        ];
    }
}
