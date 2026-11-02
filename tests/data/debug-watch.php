<?php

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Yii2S\Swoole\Server\HttpServer;
use Yii2S\Swoole\Server\RequestDispatcher;

$watchDir = sys_get_temp_dir() . '/dbg-watch-' . bin2hex(random_bytes(3));
mkdir($watchDir . '/runtime', 0777, true);
file_put_contents($watchDir . '/seed.php', '<?php return 1;');

register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null) {
        echo "\nLAST_ERROR: " . json_encode($e, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    }
});

// Surface the real warning instead of letting Yii escalate it later.
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    echo sprintf("PHP[%d] %s @ %s:%d\n", $no, $str, $file, $line);

    return true;
});

\Swoole\Coroutine\run(function () use ($watchDir): void {
    echo "A: creating server\n";
    $server = new HttpServer([
        'host' => '127.0.0.1',
        'port' => 29777,
        'enableSignalHandling' => false,
        'watch' => true,
        'watchInterval' => 0.2,
        'watchDirs' => [$watchDir],
        'dispatcher' => new RequestDispatcher(__DIR__ . '/app-form.php'),
    ]);

    Coroutine::create(function () use ($server): void {
        try {
            $server->start();
        } catch (\Throwable $e) {
            echo 'start exception: ' . $e->getMessage() . "\n";
        }
        echo "D: start() returned, running=" . var_export($server->isRunning(), true) . " pending=" . var_export($server->watchReloadPending, true) . "\n";
    });

    echo "B: waiting for server\n";
    $deadline = microtime(true) + 10;
    $up = false;
    while (microtime(true) < $deadline) {
        $client = new Client('127.0.0.1', 29777);
        $up = $client->get('/');
        $client->close();
        if ($up) {
            break;
        }
        Coroutine::sleep(0.2);
    }
    echo $up ? "B: server up\n" : "B: SERVER NEVER CAME UP\n";
    if (!$up) {
        exit(1);
    }

    echo "C: touching excluded dir\n";
    file_put_contents($watchDir . '/runtime/noise.php', '<?php return 2;');
    Coroutine::sleep(0.6);
    echo 'C: running=' . var_export($server->isRunning(), true) . "\n";

    echo "C: touching watched file\n";
    file_put_contents($watchDir . '/changed.php', '<?php return 3;');
    $deadline = microtime(true) + 5;
    while ($server->isRunning() && microtime(true) < $deadline) {
        Coroutine::sleep(0.1);
    }
    echo 'C: after change running=' . var_export($server->isRunning(), true) . ' pending=' . var_export($server->watchReloadPending, true) . "\n";
});

echo "E: run() exited cleanly\n";

// cleanup
foreach (glob($watchDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($watchDir . '/runtime');
@rmdir($watchDir);
