<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Session;

/**
 * Iterator over coroutine-local session data.
 *
 * The parent yii\web\SessionIterator reads the process-global $_SESSION array,
 * which is not safe in a resident coroutine server; this variant iterates over
 * the data array given by [[CoroutineSession]] instead.
 */
final class SessionDataIterator extends \yii\web\SessionIterator
{
    private array $data;

    private int $position = 0;

    public function __construct(array $data)
    {
        $this->data = $data;
        parent::__construct();
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function current(): mixed
    {
        return $this->data[$this->key()] ?? null;
    }

    public function key(): mixed
    {
        return array_keys($this->data)[$this->position] ?? null;
    }

    public function next(): void
    {
        $this->position++;
    }

    public function valid(): bool
    {
        return $this->position < count($this->data);
    }
}
