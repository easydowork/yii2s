<?php

namespace app\commands;

use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;

/**
 * Small console surface used by the smoke test: it verifies that queue jobs
 * actually reached the database through `queue/serve`.
 */
class BlogController extends Controller
{
    /** @var int how many audit rows to print (console options map to public properties) */
    public int $limit = 10;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['limit']);
    }

    /**
     * Prints the most recent audit rows written by the queue worker.
     */
    public function actionAudit(): int
    {
        $rows = (new Query())
            ->from('{{%audit_log}}')
            ->orderBy(['id' => SORT_DESC])
            ->limit(max(1, $this->limit))
            ->all();

        if ($rows === []) {
            $this->stdout("audit_log is empty\n");

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $this->stdout(sprintf(
                "#%d %s post=%s %s\n",
                $row['id'],
                $row['action'],
                (string) ($row['post_id'] ?? '-'),
                (string) $row['message']
            ));
        }

        return ExitCode::OK;
    }

    /**
     * Prints the current list-cache version (bumped on every content change).
     */
    public function actionCacheVersion(): int
    {
        $this->stdout('blog:list:version = ' . \app\components\BlogJobs::listVersion() . "\n");

        return ExitCode::OK;
    }

    /**
     * Queues a job whose handler always throws: after `maxAttempts` retries
     * the queue buries it in the dead-letter list.
     */
    public function actionQueueFailure(): int
    {
        $id = \Yii::$app->queue->push(['action' => 'demo.failure', 'force_fail' => true]);
        $this->stdout("queued a failing job ({$id})\n");

        return ExitCode::OK;
    }

    /**
     * Prints pending / dead-letter counters of the queue channel.
     */
    public function actionQueueStats(): int
    {
        /** @var \Yii2S\Swoole\Queue\CoroutineRedisQueue $queue */
        $queue = \Yii::$app->queue;
        $this->stdout('pending=' . $queue->countPending() . ' dead=' . $queue->countDead() . "\n");

        return ExitCode::OK;
    }
}
