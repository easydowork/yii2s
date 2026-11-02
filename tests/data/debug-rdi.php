<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

$watchDir = sys_get_temp_dir() . '/dbg-rdi-' . bin2hex(random_bytes(3));
mkdir($watchDir . '/runtime', 0777, true);
for ($i = 0; $i < 20; $i++) {
    file_put_contents($watchDir . "/file{$i}.php", '<?php');
}

register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e !== null) {
        echo "\nLAST_ERROR: " . json_encode($e) . "\n";
    }
});

echo "scenario: RecursiveDirectoryIterator scan concurrent with file IO\n";

\Swoole\Coroutine\run(function () use ($watchDir): void {
    // Scanner coroutine - mirrors HttpServer::scanWatchFiles().
    \Swoole\Coroutine::create(function () use ($watchDir): void {
        for ($round = 0; $round < 30; $round++) {
            $snapshot = [];
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($watchDir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $file) {
                    if ($file->getExtension() === 'php') {
                        $snapshot[$file->getPathname()] = (int) @filemtime($file->getPathname());
                    }
                }
            } catch (\Throwable $e) {
                echo 'scan catch: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 80) . "\n";
            }
            \Swoole\Coroutine::sleep(0.05);
        }
        echo "scanner finished 30 rounds\n";
    });

    // IO coroutine - mirrors request handling writing files.
    \Swoole\Coroutine::create(function () use ($watchDir): void {
        for ($i = 0; $i < 20; $i++) {
            file_put_contents($watchDir . "/runtime/r{$i}.php", '<?php');
            \Swoole\Coroutine::sleep(0.05);
        }
        echo "io finished 20 writes\n";
    });
});

echo "run() exited cleanly\n";
