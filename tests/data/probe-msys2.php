<?php

declare(strict_types=1);

// Capability probe for the msys2 swoole-cli build.
echo 'PHP: ' . PHP_VERSION . ' OS: ' . PHP_OS . ' FAMILY: ' . PHP_OS_FAMILY . "\n";

\Swoole\Coroutine\run(function (): void {
    // 1. Swoole\Timer availability (dead on cygwin build)
    $timerOk = false;
    $timerId = \Swoole\Timer::tick(50, function () use (&$timerOk, &$timerId): void {
        $timerOk = true;
        \Swoole\Timer::clear($timerId);
    });
    \Swoole\Coroutine::sleep(0.3);
    echo '1. Timer::tick: ' . ($timerOk ? 'OK' : 'BROKEN') . "\n";

    // 2. File IO in coroutine
    $probe = sys_get_temp_dir() . '/yii2s-probe-' . bin2hex(random_bytes(3)) . '.txt';
    file_put_contents($probe, 'x');
    $seen = is_file($probe);
    unlink($probe);
    echo '2. coroutine file IO: ' . ($seen ? 'OK' : 'BROKEN') . "\n";
});

// 3. exec from plain PHP
exec('echo exec-ok 2>&1', $out, $code);
echo '3. exec: ' . (trim(implode(' ', $out)) !== '' ? 'OK (' . trim(implode(' ', $out)) . ')' : "BROKEN (code=$code)") . "\n";

// 4. popen
$ph = @popen('echo popen-ok', 'r');
if (is_resource($ph)) {
    $line = trim((string) fgets($ph));
    pclose($ph);
    echo '4. popen: ' . ($line !== '' ? "OK ($line)" : 'BROKEN (empty)') . "\n";
} else {
    echo "4. popen: BROKEN\n";
}

// 5. proc_open
$ph = proc_open('echo proc-open-ok', [1 => ['pipe', 'w']], $pipes);
if (is_resource($ph)) {
    $line = trim((string) fgets($pipes[1]));
    fclose($pipes[1]);
    proc_close($ph);
    echo '5. proc_open: ' . ($line !== '' ? "OK ($line)" : 'BROKEN (empty)') . "\n";
} else {
    echo "5. proc_open: BROKEN\n";
}
