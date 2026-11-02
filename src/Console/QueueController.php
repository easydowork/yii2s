<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Console;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\di\Instance;
use Yii2S\Swoole\Queue\CoroutineRedisQueue;

/**
 * Runs the coroutine queue worker.
 *
 * ```bash
 * php yii queue/serve
 * php yii queue/serve --limit=100 --channel=emails
 * ```
 */
class QueueController extends Controller
{
    /** @var string application component id of the queue */
    public string $queueComponent = 'queue';

    /** @var int stop after this many jobs (0 = run forever) */
    public int $limit = 0;

    /** @var string|null override the configured queue channel */
    public ?string $channel = null;

    public function actionServe(): int
    {
        $queue = Instance::ensure(Yii::$app->get($this->queueComponent), CoroutineRedisQueue::class);
        if ($this->channel !== null) {
            $queue->channel = $this->channel;
        }

        $this->stdout(sprintf(
            "Queue worker started on channel '%s' (press Ctrl+C to stop)\n",
            $queue->channel
        ));

        $processed = $queue->run($this->limit);

        $this->stdout(sprintf("Queue worker finished after %d job(s).\n", $processed));

        return ExitCode::OK;
    }

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['queueComponent', 'limit', 'channel']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'queueComponent',
            'l' => 'limit',
            'n' => 'channel',
        ]);
    }
}
