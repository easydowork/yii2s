<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace yii2stest;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

/**
 * Boots the HTTP server in a separate process and speaks real HTTP to it.
 *
 * [[Swoole\Http\Server]] cannot be started inside a coroutine, so the server
 * runs as a child process (`tests/data/server.php`) and the test talks to it
 * through the coroutine HTTP client. All helpers must be called inside a
 * coroutine (`TestCase::runInCoroutine()`).
 */
trait ServerProcessTrait
{
    protected int $port = 0;

    /** @var resource|null */
    private $proc = null;

    private string $optionsFile = '';

    private string $logFile = '';

    /**
     * Starts the server subprocess and waits until it accepts requests.
     * Spawning a fresh process is intermittent under load (the child can exit
     * before running the script), so a failed boot is retried a few times.
     *
     * @param array<string, mixed> $options "dispatcher" / "rawRoutes" / "server" keys
     */
    protected function startServerProcess(string $configFile, array $options = []): void
    {
        $optionsJson = json_encode($options, JSON_UNESCAPED_SLASHES);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->spawnServer($configFile, $optionsJson);
            if ($this->waitForServer()) {
                return;
            }

            $this->stopServerProcess();
        }

        $log = $this->logFile !== '' && is_file($this->logFile)
            ? (string) file_get_contents($this->logFile)
            : '(no log)';
        $this->fail("Test server did not come up on port {$this->port} after 3 attempts.\nServer log:\n{$log}");
    }

    /**
     * Sends SIGTERM without waiting for the process to exit.
     */
    protected function terminateServer(): void
    {
        if ($this->proc !== null) {
            proc_terminate($this->proc, SIGTERM);
        }
    }

    /**
     * Sends SIGUSR1 (Swoole's graceful worker reload) to the master.
     */
    protected function reloadServer(): void
    {
        if ($this->proc !== null) {
            proc_terminate($this->proc, SIGUSR1);
        }
    }

    protected function stopServerProcess(): void
    {
        if ($this->proc === null) {
            return;
        }

        $proc = $this->proc;
        $this->proc = null;

        proc_terminate($proc, SIGTERM);
        proc_close($proc);

        $this->removeTempFiles();
    }

    /**
     * Forks the server subprocess on a fresh random port.
     */
    private function spawnServer(string $configFile, string $optionsJson): void
    {
        $this->port = random_int(31000, 39999);
        $this->optionsFile = (string) tempnam(sys_get_temp_dir(), 'yii2s-opt-');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'yii2s-srv-');
        file_put_contents($this->optionsFile, $optionsJson);

        // Array form: executed directly (no shell wrapper), so the process
        // handle is the server master itself and proc_terminate signals it.
        $command = [
            PHP_BINARY,
            __DIR__ . '/data/server.php',
            (string) $this->port,
            $configFile,
            $this->optionsFile,
        ];

        $this->proc = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->logFile, 'w'],
            2 => ['file', $this->logFile, 'w'],
        ], $pipes);

        if (!is_resource($this->proc)) {
            $this->fail('Failed to spawn the test server process.');
        }
    }

    /**
     * Polls the server port until it accepts a request.
     */
    private function waitForServer(int $timeoutSeconds = 25): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $client = new Client('127.0.0.1', $this->port);
            $up = $client->get('/');
            $client->close();
            if ($up) {
                return true;
            }
            Coroutine::sleep(0.05);
        }

        return false;
    }

    private function removeTempFiles(): void
    {
        if ($this->optionsFile !== '') {
            @unlink($this->optionsFile);
            $this->optionsFile = '';
        }
        if ($this->logFile !== '') {
            @unlink($this->logFile);
            $this->logFile = '';
        }
    }
}
