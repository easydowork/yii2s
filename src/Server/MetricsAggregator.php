<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Server;

use Yii2S\Swoole\Redis\CoroutineRedisConnection;

/**
 * Merges per-worker [[RequestMetrics]] snapshots through Redis so the
 * `/metrics` endpoint reports the whole server, not just the worker that
 * happened to serve the scrape request.
 *
 * Each worker writes its snapshot to `{prefix}worker:{pid}` on every scrape and
 * the reading worker sums the counters, adds up the gauges and keeps the
 * per-worker memory as a labelled metric. Snapshots carry a short TTL so a
 * dead worker drops out of the aggregation automatically.
 */
class MetricsAggregator
{
    private const DEFAULT_TTL = 30;

    public function __construct(
        private CoroutineRedisConnection $redis,
        private string $prefix = 'yii2s:metrics:',
        private int $ttl = self::DEFAULT_TTL,
    ) {
    }

    /**
     * Publishes this worker's snapshot and renders the aggregated metrics.
     *
     * @param array<string, array<string, int|bool>> $pools this worker's pool metrics
     */
    public function render(RequestMetrics $metrics, array $pools): string
    {
        $this->publish($metrics, $pools);

        return $metrics->renderAggregate($this->collect());
    }

    private function publish(RequestMetrics $metrics, array $pools): void
    {
        $snapshot = $metrics->snapshot();
        $snapshot += [
            'worker' => getmypid(),
            'memory' => memory_get_usage(true),
            'coroutines' => (int) (\Swoole\Coroutine::stats()['coroutine_num'] ?? 0),
            'pools' => $pools,
        ];

        $this->redis->executeCommand('SET', [
            $this->prefix . 'worker:' . getmypid(),
            (string) json_encode($snapshot),
            ['EX' => $this->ttl],
        ]);
    }

    /**
     * @return array{byMethod: array, inProgress: int, memory: array<int, int>, coroutines: int, pools: array}
     */
    private function collect(): array
    {
        $aggregate = ['byMethod' => [], 'inProgress' => 0, 'memory' => [], 'coroutines' => 0, 'pools' => []];

        $keys = $this->redis->executeCommand('KEYS', [$this->prefix . 'worker:*']);
        if (!is_array($keys)) {
            return $aggregate;
        }

        foreach ($keys as $key) {
            $data = $this->redis->executeCommand('GET', [$key]);
            if (!is_string($data)) {
                continue;
            }
            $snapshot = json_decode($data, true);
            if (!is_array($snapshot)) {
                continue;
            }
            $aggregate = $this->merge($aggregate, $snapshot);
        }

        return $aggregate;
    }

    private function merge(array $aggregate, array $snapshot): array
    {
        foreach ($snapshot['byMethod'] ?? [] as $method => $m) {
            foreach ($m['statuses'] ?? [] as $status => $count) {
                $aggregate['byMethod'][$method]['statuses'][$status] =
                    ($aggregate['byMethod'][$method]['statuses'][$status] ?? 0) + (int) $count;
            }
            $aggregate['byMethod'][$method]['count'] =
                ($aggregate['byMethod'][$method]['count'] ?? 0) + (int) ($m['count'] ?? 0);
            $aggregate['byMethod'][$method]['sum'] =
                ($aggregate['byMethod'][$method]['sum'] ?? 0.0) + (float) ($m['sum'] ?? 0.0);
            foreach ($m['buckets'] ?? [] as $i => $count) {
                $aggregate['byMethod'][$method]['buckets'][$i] =
                    ($aggregate['byMethod'][$method]['buckets'][$i] ?? 0) + (int) $count;
            }
        }

        $aggregate['inProgress'] += (int) ($snapshot['inProgress'] ?? 0);
        $aggregate['coroutines'] += (int) ($snapshot['coroutines'] ?? 0);
        $aggregate['memory'][(int) ($snapshot['worker'] ?? 0)] = (int) ($snapshot['memory'] ?? 0);

        foreach ($snapshot['pools'] ?? [] as $poolId => $m) {
            foreach ($m as $metric => $value) {
                if (is_int($value) || is_float($value)) {
                    $aggregate['pools'][$poolId][$metric] = ($aggregate['pools'][$poolId][$metric] ?? 0) + $value;
                }
            }
        }

        return $aggregate;
    }
}
