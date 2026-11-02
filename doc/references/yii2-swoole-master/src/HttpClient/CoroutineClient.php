<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\HttpClient;

use Swoole\Coroutine;
use yii\httpclient\Client;

class CoroutineClient extends Client
{
    public bool $enableCoroutine = true;

    public function init(): void
    {
        parent::init();

        if ($this->shouldUseCoroutineTransport()) {
            $this->setTransport(CoroutineTransport::class);
        }
    }

    private function shouldUseCoroutineTransport(): bool
    {
        if (!$this->enableCoroutine) {
            return false;
        }

        if (!extension_loaded('swoole')) {
            return false;
        }

        $transport = $this->getTransport();
        
        return $transport instanceof \yii\httpclient\StreamTransport || 
               $transport instanceof \yii\httpclient\CurlTransport;
    }
}

