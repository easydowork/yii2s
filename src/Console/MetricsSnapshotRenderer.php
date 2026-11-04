<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Console;

/**
 * Fetches and formats the runtime snapshots a running server exposes over
 * `/metrics` and `/__pool`, for the `swoole/status` panel.
 */
final class MetricsSnapshotRenderer
{
    /**
     * Fetches a URL from the running server (best-effort, short timeout).
     */
    public function fetch(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['host'], $parsed['port'])) {
            return null;
        }

        $body = null;
        \Swoole\Coroutine\run(static function () use ($parsed, &$body): void {
            $client = new \Swoole\Coroutine\Http\Client((string) $parsed['host'], (int) $parsed['port']);
            $client->set(['timeout' => 2.0]);
            $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
            if ($client->get($path)) {
                $body = (string) $client->body;
            }
            $client->close();
        });

        return $body;
    }

    /**
     * @return string[] formatted metrics lines (empty when the endpoint is off)
     */
    public function renderMetrics(?string $metrics): array
    {
        // A disabled endpoint returns a 404 page, not the Prometheus text; only
        // parse real metric output so we do not print a misleading "0 requests".
        if ($metrics === null || $metrics === '' || !str_contains($metrics, 'yii2s_http_requests_total')) {
            return [];
        }

        $requests = 0;
        $errors = 0;
        $coroutines = null;
        foreach (explode("\n", $metrics) as $line) {
            if (preg_match('/^yii2s_http_requests_total\{[^}]*status="(\d+)"\} (\d+)$/', $line, $m)) {
                $requests += (int) $m[2];
                if ((int) $m[1] >= 400) {
                    $errors += (int) $m[2];
                }
            } elseif (preg_match('/^yii2s_process_coroutines (\d+)$/', $line, $m)) {
                $coroutines = (int) $m[1];
            }
        }

        $lines = [sprintf('  requests: %d (errors %d) [single worker]', $requests, $errors)];
        if ($coroutines !== null) {
            $lines[] = sprintf('  coroutines: %d [single worker]', $coroutines);
        }

        return $lines;
    }

    /**
     * @return string[] formatted pool lines (empty when the endpoint is off)
     */
    public function renderPools(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['pools']) || !is_array($data['pools'])) {
            return [];
        }

        $lines = [];
        foreach ($data['pools'] as $id => $m) {
            if (!is_array($m)) {
                continue;
            }
            $lines[] = sprintf(
                '  %s: %d active / %d idle (created %d, timeouts %d, discarded %d)',
                $id,
                (int) ($m['active'] ?? 0),
                (int) ($m['idle'] ?? 0),
                (int) ($m['total_created'] ?? 0),
                (int) ($m['total_timeouts'] ?? 0),
                (int) ($m['total_discarded'] ?? 0)
            );
        }

        return $lines;
    }
}
