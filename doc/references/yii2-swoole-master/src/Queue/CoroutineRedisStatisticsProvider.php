<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Queue;

use yii\base\BaseObject;
use yii\queue\interfaces\DelayedCountInterface;
use yii\queue\interfaces\DoneCountInterface;
use yii\queue\interfaces\ReservedCountInterface;
use yii\queue\interfaces\WaitingCountInterface;

/**
 * Statistics Provider for Coroutine Redis Queue.
 * 
 * Provides queue statistics like waiting, delayed, reserved, and done job counts.
 */
class CoroutineRedisStatisticsProvider extends BaseObject implements DoneCountInterface, WaitingCountInterface, DelayedCountInterface, ReservedCountInterface
{
    /**
     * @var CoroutineRedisQueue
     */
    protected $queue;

    /**
     * @param CoroutineRedisQueue $queue
     * @param array $config
     */
    public function __construct(CoroutineRedisQueue $queue, $config = [])
    {
        $this->queue = $queue;
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function getWaitingCount()
    {
        $this->queue->redis->open();
        try {
            return $this->queue->redis->llen("{$this->queue->channel}.waiting");
        } finally {
            $this->queue->redis->close();
        }
    }

    /**
     * @inheritdoc
     */
    public function getDelayedCount()
    {
        $this->queue->redis->open();
        try {
            return $this->queue->redis->zcount("{$this->queue->channel}.delayed", '-inf', '+inf');
        } finally {
            $this->queue->redis->close();
        }
    }

    /**
     * @inheritdoc
     */
    public function getReservedCount()
    {
        $this->queue->redis->open();
        try {
            return $this->queue->redis->zcount("{$this->queue->channel}.reserved", '-inf', '+inf');
        } finally {
            $this->queue->redis->close();
        }
    }

    /**
     * @inheritdoc
     * 
     * Note: This implementation calculates done count as:
     * total_jobs - (waiting + delayed + reserved)
     * 
     * This approach has limitations:
     * - It includes both successfully completed and failed jobs
     * - It assumes jobs are either waiting, delayed, reserved, or done
     * - The Redis queue driver doesn't maintain a separate "done" set for performance reasons
     * 
     * For accurate tracking of successful vs failed jobs, consider implementing
     * custom tracking in your job's execute() method.
     */
    public function getDoneCount()
    {
        $this->queue->redis->open();
        try {
            $prefix = $this->queue->channel;
            $waiting = $this->queue->redis->llen("$prefix.waiting");
            $delayed = $this->queue->redis->zcount("$prefix.delayed", '-inf', '+inf');
            $reserved = $this->queue->redis->zcount("$prefix.reserved", '-inf', '+inf');
            $total = $this->queue->redis->get("$prefix.message_id");
            
            return max(0, $total - $waiting - $delayed - $reserved);
        } finally {
            $this->queue->redis->close();
        }
    }
}
