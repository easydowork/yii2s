<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

/**
 * Sustained-load soak test: drives the server with keep-alive traffic while
 * sampling the pool metrics (and, optionally, the process RSS) to surface
 * leaks that only appear over time.
 *
 * Usage:
 *   swoole-cli scripts/soak.php --path=/demo/db --concurrency=50 --duration=60 --interval=2 [--pid=<server pid>]
 *
 * Leak signals watched:
 *   - pool `active` never settling back to ~0    -> borrowed connections leak
 *   - pool `size` growing past `max_active`       -> pool never shrinks
 *   - pool `waiting` > 0                          -> pool exhaustion / head-of-line
 *   - RSS monotonically growing (with --pid)      -> memory leak
 */

$options = getopt('', ['host:', 'port:', 'path:', 'concurrency:', 'duration:', 'interval:', 'pid:']);
$host = (string) ($options['host'] ?? '127.0.0.1');
$port = (int) ($options['port'] ?? 9501);
$path = (string) ($options['path'] ?? '/');
$concurrency = max(1, (int) ($options['concurrency'] ?? 50));
$duration = max(1.0, (float) ($options['duration'] ?? 30.0));
$interval = max(0.5, (float) ($options['interval'] ?? 2.0));
$pid = isset($options['pid']) ? (int) $options['pid'] : 0;

/**
 * Reads the /__pool metrics endpoint.
 * @return array<string, mixed>|null
 */
function poolMetrics(string $host, int $port): ?array
{
    $client = new \Swoole\Coroutine\Http\Client($host, $port);
    if (!$client->get('/__pool') || $client->statusCode !== 200) {
        $client->close();

        return null;
    }
    $body = $client->body;
    $client->close();
    $decoded = json_decode((string) $body, true);

    return is_array($decoded) ? $decoded : null;
}

/** @return int|null RSS in kilobytes */
function rssKb(int $pid): ?int
{
    if ($pid <= 0) {
        return null;
    }
    $out = shell_exec('ps -o rss= -p ' . $pid . ' 2>/dev/null');
    if (!is_string($out)) {
        return null;
    }
    $rss = (int) trim($out);

    return $rss > 0 ? $rss : null;
}

\Swoole\Coroutine\run(function () use ($host, $port, $path, $concurrency, $duration, $interval, $pid): void {
    $deadline = microtime(true) + $duration;

    $requests = 0;
    $errors = 0;

    $startRss = rssKb($pid);
    $samples = [];

    // Sampler coroutine: record pool metrics and RSS on a fixed cadence.
    \Swoole\Coroutine::create(function () use ($host, $port, $interval, $deadline, $pid, &$samples): void {
        while (microtime(true) < $deadline) {
            $metrics = poolMetrics($host, $port);
            if ($metrics !== null) {
                $samples[] = [
                    't' => microtime(true),
                    'metrics' => $metrics,
                    'rss' => rssKb($pid),
                ];
            }
            \Swoole\Coroutine::sleep($interval);
        }
    });

    // Load coroutines, tracked so we can join them before the final check.
    $loaders = new \Swoole\Coroutine\WaitGroup();
    for ($i = 0; $i < $concurrency; $i++) {
        $loaders->add();
        \Swoole\Coroutine::create(function () use ($host, $port, $path, $deadline, $loaders, &$requests, &$errors): void {
            $client = new \Swoole\Coroutine\Http\Client($host, $port);
            try {
                while (microtime(true) < $deadline) {
                    $ok = $client->get($path);
                    if (!$ok || $client->statusCode === 0 || $client->errCode !== 0) {
                        $errors++;
                        $client->close();
                        \Swoole\Coroutine::sleep(0.01);
                        continue;
                    }
                    $requests++;
                }
            } finally {
                $client->close();
                $loaders->done();
            }
        });
    }

    // Wait out the deadline, then join the loaders so the final pool snapshot
    // reflects a quiescent server rather than in-flight requests.
    while (microtime(true) < $deadline) {
        \Swoole\Coroutine::sleep(0.1);
    }
    $loaders->wait();
    $endRss = rssKb($pid);

    $elapsed = $duration;
    $qps = $elapsed > 0 ? $requests / $elapsed : 0.0;

    fwrite(STDOUT, sprintf("soak: %d req in %.1fs (%.0f req/s), %d errors\n", $requests, $elapsed, $qps, $errors));
    if ($startRss !== null && $endRss !== null) {
        fwrite(STDOUT, sprintf("rss: %d KB -> %d KB (drift %+d KB)\n", $startRss, $endRss, $endRss - $startRss));
    }

    if ($samples === []) {
        fwrite(STDOUT, "no pool metrics collected (is enablePoolMetricsEndpoint on?)\n");
        return;
    }

    // Summarise the first and last sample per pool, and report any signal.
    // The /__pool endpoint returns {"pools": {"db": {...}, "redis": {...}}}.
    $first = $samples[0]['metrics']['pools'] ?? $samples[0]['metrics'];
    $last = $samples[count($samples) - 1]['metrics']['pools'] ?? $samples[count($samples) - 1]['metrics'];

    fwrite(STDOUT, "pool snapshot (first -> last):\n");
    foreach ($last as $id => $m) {
        if (!is_array($m)) {
            continue;
        }
        $prev = $first[$id] ?? null;
        $size = $m['size'] ?? 0;
        $idle = $m['idle'] ?? 0;
        $active = $m['active'] ?? 0;
        $waiting = $m['waiting'] ?? 0;
        $discarded = $m['total_discarded'] ?? 0;
        $timeouts = $m['total_timeouts'] ?? 0;
        $maxActive = $m['max_active'] ?? '?';

        fwrite(STDOUT, sprintf(
            "  %-8s size=%d/%s idle=%d active=%d waiting=%d discarded=%d timeouts=%d\n",
            $id,
            $size,
            (string) $maxActive,
            $idle,
            $active,
            $waiting,
            $discarded,
            $timeouts
        ));

        if (is_array($prev) && (int) ($prev['total_discarded'] ?? 0) !== $discarded) {
            fwrite(STDOUT, sprintf("    -> %d connection(s) discarded during the run (recovered from loss)\n", $discarded - (int) $prev['total_discarded']));
        }
        if ($timeouts > 0) {
            fwrite(STDOUT, sprintf("    !! %d pool timeout(s) - raise poolMaxActive or poolWaitTimeout\n", $timeouts));
        }
        if ($waiting > 0) {
            fwrite(STDOUT, sprintf("    note: %d coroutine(s) waiting - pool saturated under load\n", $waiting));
        }
    }

    // A final idle check: with all load coroutines finished, nothing should be
    // left borrowed.
    $tail = poolMetrics($host, $port);
    $tailPools = is_array($tail) ? ($tail['pools'] ?? $tail) : null;
    if (is_array($tailPools)) {
        foreach ($tailPools as $id => $m) {
            if (is_array($m) && (int) ($m['active'] ?? 0) > 0) {
                fwrite(STDOUT, sprintf("    !! %s still has %d active connection(s) after load stopped (leak?)\n", $id, $m['active']));
            }
        }
    }
});
