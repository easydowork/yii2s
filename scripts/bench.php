<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

/**
 * Coroutine load generator for benchmarking the yii2s server.
 *
 * Usage:
 *   swoole-cli scripts/bench.php --path=/demo/db --concurrency=100 --duration=5
 *
 * Each worker coroutine holds one keep-alive connection, mirroring how a
 * reverse proxy would talk to the server.
 */

$options = getopt('', ['host:', 'port:', 'path:', 'concurrency:', 'duration:', 'warmup:']);
$host = (string) ($options['host'] ?? '127.0.0.1');
$port = (int) ($options['port'] ?? 9501);
$path = (string) ($options['path'] ?? '/');
$concurrency = max(1, (int) ($options['concurrency'] ?? 100));
$duration = max(1.0, (float) ($options['duration'] ?? 5.0));
$warmup = max(0.0, (float) ($options['warmup'] ?? 1.0));

\Swoole\Coroutine\run(function () use ($host, $port, $path, $concurrency, $duration, $warmup): void {
    $deadline = microtime(true) + $duration;

    $requests = 0;
    $errors = 0;
    $statusCounts = [];
    /** @var array<int> $latencies microseconds */
    $latencies = [];

    // Warm-up (connection and pool setup) is not measured.
    $warmupDeadline = microtime(true) + $warmup;
    while (microtime(true) < $warmupDeadline) {
        $client = new \Swoole\Coroutine\Http\Client($host, $port);
        $client->get($path);
        $client->close();
        \Swoole\Coroutine::sleep(0.01);
    }

    for ($worker = 0; $worker < $concurrency; $worker++) {
        \Swoole\Coroutine::create(function () use ($host, $port, $path, $deadline, &$requests, &$errors, &$statusCounts, &$latencies): void {
            $client = new \Swoole\Coroutine\Http\Client($host, $port);
            try {
                while (microtime(true) < $deadline) {
                    $start = microtime(true);
                    $ok = $client->get($path);
                    $elapsed = (int) round((microtime(true) - $start) * 1_000_000);

                    if (!$ok || $client->statusCode === 0 || $client->errCode !== 0) {
                        $errors++;
                        // Reconnect on error; back off so a burst of failures
                        // cannot starve the event loop (or the server).
                        $client->close();
                        \Swoole\Coroutine::sleep(0.01);
                        $client = new \Swoole\Coroutine\Http\Client($host, $port);
                        continue;
                    }

                    $code = $client->statusCode;
                    $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
                    $latencies[] = $elapsed;
                    $requests++;
                }
            } finally {
                $client->close();
            }
        });
    }

    // Wait until the deadline passes (workers stop on their own).
    \Swoole\Coroutine::sleep($duration + 0.5);

    $report = [
        'path' => $path,
        'concurrency' => $concurrency,
        'duration_s' => $duration,
        'requests' => $requests,
        'qps' => round($requests / $duration, 1),
        'errors' => $errors,
        'status' => $statusCounts,
        'latency_ms' => [],
    ];

    sort($latencies);
    $count = count($latencies);
    if ($count > 0) {
        $pick = static function (float $p) use ($latencies, $count): float {
            $index = (int) min($count - 1, floor($p * $count));
            return round($latencies[$index] / 1000, 2);
        };
        $report['latency_ms'] = [
            'min' => round($latencies[0] / 1000, 2),
            'p50' => $pick(0.50),
            'p95' => $pick(0.95),
            'p99' => $pick(0.99),
            'max' => round($latencies[$count - 1] / 1000, 2),
        ];
        $report['latency_ms']['avg'] = round(array_sum($latencies) / $count / 1000, 2);
    }

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
});
