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
 * Each worker writes its snapshot to `{prefix}worker:{pid}` and adds that key
 * to `{prefix}workers`. The reading worker walks the set. A snapshot that has
 * expired (the worker is gone) is removed from the set, so a scrape never
 * runs `KEYS`.
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
     * How often a worker should call [[publish()]] so its snapshot outlives
     * [[$ttl]] even when this worker never serves `/metrics`.
     */
    public const PUBLISH_INTERVAL = 10.0;

    /**
     * Publishes this worker's snapshot and renders the aggregated metrics.
     *
     * The publish keeps the worker that happens to serve the scrape fresh.
     * Every other worker must also call [[publish()]] on a timer: with the
     * default connection-sticky dispatch, a scraper talks to one worker and
     * the rest would otherwise age out of the index.
     *
     * @param array<string, \Yii2S\Swoole\Pools\ConnectionPool>|array<string, array<string, int|bool>> $pools pools or their collected metrics
     */
    public function render(RequestMetrics $metrics, array $pools): string
    {
        $this->publish($metrics, $pools);

        return $this->renderPublished($metrics);
    }

    /**
     * Renders whatever workers have already published, without writing this
     * worker's snapshot. Useful when the caller only wants to read.
     */
    public function renderPublished(RequestMetrics $metrics): string
    {
        return $metrics->renderAggregate($this->collect());
    }

    /**
     * @param array<string, \Yii2S\Swoole\Pools\ConnectionPool>|array<string, array<string, int|bool>> $pools pools or their collected metrics
     */
    public function publish(RequestMetrics $metrics, array $pools): void
    {
        $snapshot = $metrics->snapshot();
        $snapshot += [
            'worker' => getmypid(),
            'memory' => memory_get_usage(true),
            'coroutines' => (int) (\Swoole\Coroutine::stats()['coroutine_num'] ?? 0),
            'pools' => $pools,
        ];

        $key = $this->workerKey((int) getmypid());
        $this->redis->executeCommand('SET', [
            $key,
            (string) json_encode($snapshot),
            ['EX' => $this->ttl],
        ]);
        $this->redis->executeCommand('SADD', [$this->indexKey(), $key]);
    }

    private function indexKey(): string
    {
        return $this->prefix . 'workers';
    }

    private function workerKey(int $pid): string
    {
        return $this->prefix . 'worker:' . $pid;
    }

    /**
     * @return array{byMethod: array, inProgress: int, memory: array<int, int>, coroutines: int, pools: array}
     */
    private function collect(): array
    {
        $aggregate = ['byMethod' => [], 'inProgress' => 0, 'memory' => [], 'coroutines' => 0, 'pools' => []];

        $keys = $this->redis->executeCommand('SMEMBERS', [$this->indexKey()]);
        if (!is_array($keys)) {
            return $aggregate;
        }

        foreach ($keys as $key) {
            $key = (string) $key;
            $data = $this->redis->executeCommand('GET', [$key]);
            if (!is_string($data)) {
                // The snapshot TTL elapsed with the worker. Drop the index
                // entry so later scrapes do not keep asking for it.
                $this->redis->executeCommand('SREM', [$this->indexKey(), $key]);
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
