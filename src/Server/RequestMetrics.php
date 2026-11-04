<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

/**
 * In-process RED metrics (Rate / Errors / Duration) for the HTTP server,
 * rendered in the Prometheus text exposition format.
 *
 * Counters are plain PHP arrays: Swoole coroutines only switch at I/O yield
 * points and never between the opcodes of a single increment, so the
 * read-modify-write here is safe without locks.
 *
 * Each worker keeps its own instance; [[MetricsAggregator]] merges them across
 * workers through a shared store so `/metrics` can report the whole server.
 */
class RequestMetrics
{
    /**
     * Histogram buckets in seconds (upper bounds).
     */
    private const LATENCY_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    private int $inProgress = 0;

    /**
     * @var array<string, array{count: int, sum: float, buckets: array<int, int>, statuses: array<int, int>}>
     * per-method counters, keyed by the upper-cased HTTP method
     */
    private array $byMethod = [];

    public function beginRequest(): void
    {
        $this->inProgress++;
    }

    public function record(float $durationSeconds, int $statusCode, string $method): void
    {
        $this->inProgress = max(0, $this->inProgress - 1);

        if (!isset($this->byMethod[$method])) {
            $this->byMethod[$method] = ['count' => 0, 'sum' => 0.0, 'buckets' => [], 'statuses' => []];
        }

        $this->byMethod[$method]['count']++;
        $this->byMethod[$method]['sum'] += $durationSeconds;
        $this->byMethod[$method]['statuses'][$statusCode] = ($this->byMethod[$method]['statuses'][$statusCode] ?? 0) + 1;

        foreach (self::LATENCY_BUCKETS as $i => $upper) {
            if ($durationSeconds <= $upper) {
                $this->byMethod[$method]['buckets'][$i] = ($this->byMethod[$method]['buckets'][$i] ?? 0) + 1;
            }
        }
    }

    /**
     * Exports the per-worker HTTP counters so they can be merged across
     * workers by [[MetricsAggregator]].
     *
     * @return array{byMethod: array, inProgress: int}
     */
    public function snapshot(): array
    {
        return ['byMethod' => $this->byMethod, 'inProgress' => $this->inProgress];
    }

    /**
     * Renders this worker's metrics (no cross-worker aggregation).
     *
     * @param array<string, array<string, int|bool>> $pools pool metrics keyed by pool id
     */
    public function render(array $pools = []): string
    {
        return $this->renderHttp($this->byMethod, $this->inProgress)
            . $this->renderPoolMetrics($pools)
            . $this->renderProcess();
    }

    /**
     * Renders an aggregated snapshot produced by [[MetricsAggregator]].
     *
     * @param array{byMethod?: array, inProgress?: int, memory?: array<int, int>, coroutines?: int, pools?: array} $aggregate
     */
    public function renderAggregate(array $aggregate): string
    {
        return $this->renderHttp($aggregate['byMethod'] ?? [], (int) ($aggregate['inProgress'] ?? 0))
            . $this->renderPoolMetrics($aggregate['pools'] ?? [])
            . $this->renderProcessAggregate($aggregate['memory'] ?? [], (int) ($aggregate['coroutines'] ?? 0));
    }

    /**
     * @param array<string, array<string, mixed>> $byMethod
     */
    private function renderHttp(array $byMethod, int $inProgress): string
    {
        $out = "# HELP yii2s_http_requests_total Total HTTP requests handled.\n"
            . "# TYPE yii2s_http_requests_total counter\n";
        foreach ($byMethod as $method => $m) {
            foreach ($m['statuses'] as $status => $count) {
                $out .= sprintf("yii2s_http_requests_total{method=\"%s\",status=\"%d\"} %d\n", $method, (int) $status, (int) $count);
            }
        }

        $out .= "# HELP yii2s_http_request_duration_seconds HTTP request duration in seconds.\n"
            . "# TYPE yii2s_http_request_duration_seconds histogram\n";
        foreach ($byMethod as $method => $m) {
            foreach (self::LATENCY_BUCKETS as $i => $upper) {
                $out .= sprintf(
                    "yii2s_http_request_duration_seconds_bucket{method=\"%s\",le=\"%s\"} %d\n",
                    $method,
                    (string) $upper,
                    (int) ($m['buckets'][$i] ?? 0)
                );
            }
            $out .= sprintf(
                "yii2s_http_request_duration_seconds_bucket{method=\"%s\",le=\"+Inf\"} %d\n",
                $method,
                (int) ($m['count'] ?? 0)
            );
            $out .= sprintf("yii2s_http_request_duration_seconds_sum{method=\"%s\"} %.6f\n", $method, (float) ($m['sum'] ?? 0.0));
            $out .= sprintf("yii2s_http_request_duration_seconds_count{method=\"%s\"} %d\n", $method, (int) ($m['count'] ?? 0));
        }

        $out .= "# HELP yii2s_http_requests_in_progress Currently handled requests.\n"
            . "# TYPE yii2s_http_requests_in_progress gauge\n"
            . sprintf("yii2s_http_requests_in_progress %d\n", $inProgress);

        return $out;
    }

    /**
     * @param array<string, array<string, int|bool>> $pools
     */
    private function renderPoolMetrics(array $pools): string
    {
        if ($pools === []) {
            return '';
        }

        $out = "# HELP yii2s_pool_size Current number of pooled connections.\n"
            . "# TYPE yii2s_pool_size gauge\n"
            . "# TYPE yii2s_pool_idle gauge\n"
            . "# TYPE yii2s_pool_active gauge\n"
            . "# TYPE yii2s_pool_waiting gauge\n"
            . "# TYPE yii2s_pool_total_created counter\n"
            . "# TYPE yii2s_pool_total_borrowed counter\n"
            . "# TYPE yii2s_pool_total_timeouts counter\n"
            . "# TYPE yii2s_pool_total_discarded counter\n";

        foreach ($pools as $id => $m) {
            $out .= sprintf("yii2s_pool_size{pool=\"%s\"} %d\n", $id, (int) ($m['size'] ?? 0));
            $out .= sprintf("yii2s_pool_idle{pool=\"%s\"} %d\n", $id, (int) ($m['idle'] ?? 0));
            $out .= sprintf("yii2s_pool_active{pool=\"%s\"} %d\n", $id, (int) ($m['active'] ?? 0));
            $out .= sprintf("yii2s_pool_waiting{pool=\"%s\"} %d\n", $id, (int) ($m['waiting'] ?? 0));
            $out .= sprintf("yii2s_pool_total_created{pool=\"%s\"} %d\n", $id, (int) ($m['total_created'] ?? 0));
            $out .= sprintf("yii2s_pool_total_borrowed{pool=\"%s\"} %d\n", $id, (int) ($m['total_borrowed'] ?? 0));
            $out .= sprintf("yii2s_pool_total_timeouts{pool=\"%s\"} %d\n", $id, (int) ($m['total_timeouts'] ?? 0));
            $out .= sprintf("yii2s_pool_total_discarded{pool=\"%s\"} %d\n", $id, (int) ($m['total_discarded'] ?? 0));
        }

        return $out;
    }

    /**
     * Per-worker process metrics (no cross-worker aggregation).
     */
    private function renderProcess(): string
    {
        $stats = \Swoole\Coroutine::stats();

        $out = "# HELP yii2s_process_memory_bytes Memory currently allocated by the process.\n"
            . "# TYPE yii2s_process_memory_bytes gauge\n"
            . sprintf("yii2s_process_memory_bytes %d\n", memory_get_usage(true))
            . "# HELP yii2s_process_coroutines Number of active coroutines.\n"
            . "# TYPE yii2s_process_coroutines gauge\n"
            . sprintf("yii2s_process_coroutines %d\n", $stats['coroutine_num'] ?? 0);

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if (is_array($load)) {
            $out .= "# HELP yii2s_process_load_average System load average (1/5/15 minutes).\n"
                . "# TYPE yii2s_process_load_average gauge\n"
                . sprintf("yii2s_process_load_average{window=\"1m\"} %.2f\n", (float) $load[0])
                . sprintf("yii2s_process_load_average{window=\"5m\"} %.2f\n", (float) $load[1])
                . sprintf("yii2s_process_load_average{window=\"15m\"} %.2f\n", (float) $load[2]);
        }

        return $out;
    }

    /**
     * Cross-worker process metrics: per-worker memory + total coroutines.
     *
     * @param array<int, int> $memory worker id => memory bytes
     */
    private function renderProcessAggregate(array $memory, int $coroutines): string
    {
        $out = "# HELP yii2s_process_memory_bytes Memory currently allocated by a worker.\n"
            . "# TYPE yii2s_process_memory_bytes gauge\n";
        foreach ($memory as $workerId => $bytes) {
            $out .= sprintf("yii2s_process_memory_bytes{worker=\"%d\"} %d\n", (int) $workerId, (int) $bytes);
        }

        $out .= "# HELP yii2s_process_coroutines Number of active coroutines.\n"
            . "# TYPE yii2s_process_coroutines gauge\n"
            . sprintf("yii2s_process_coroutines %d\n", $coroutines);

        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if (is_array($load)) {
            $out .= "# HELP yii2s_process_load_average System load average (1/5/15 minutes).\n"
                . "# TYPE yii2s_process_load_average gauge\n"
                . sprintf("yii2s_process_load_average{window=\"1m\"} %.2f\n", (float) $load[0])
                . sprintf("yii2s_process_load_average{window=\"5m\"} %.2f\n", (float) $load[1])
                . sprintf("yii2s_process_load_average{window=\"15m\"} %.2f\n", (float) $load[2]);
        }

        return $out;
    }
}
