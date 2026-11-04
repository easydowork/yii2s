<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Scheduling;

/**
 * A scheduled task. The rule is either an interval (`@every 30s`, `@every 5m`)
 * or a standard five-field cron expression (`* * * * *`).
 *
 * Second-level scheduling is the point over classic crontab: a resident
 * process can tick every second without spawning a new process per run.
 */
class Crontab
{
    /**
     * @var callable
     */
    private $callback;

    public function __construct(
        private string $name,
        private string $rule,
        callable $callback,
    ) {
        $this->callback = $callback;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRule(): string
    {
        return $this->rule;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }
}
