<?php

/**
 * @link https://github.com/yii2s/yii2-swoole
 * @copyright Copyright (c) 2026 Yii2S Software LLC
 * @license MIT
 */

declare(strict_types=1);

namespace Yii2S\Swoole\Console;

/**
 * Inspects the server's process tree from a separate CLI process, via `pgrep`
 * and `ps` (Swoole exposes no cross-process API for this).
 */
final class ProcessInspector
{
    /**
     * RSS and worker count of the server process tree (master + manager +
     * workers).
     *
     * @return array{workers: int, rssMb: float}|null null when the process is gone
     */
    public function processTreeInfo(int $pid): ?array
    {
        $pids = [$pid];
        $frontier = [$pid];
        for ($depth = 0; $depth < 4 && $frontier !== []; $depth++) {
            $next = [];
            foreach ($frontier as $p) {
                $out = @shell_exec("pgrep -P {$p} 2>/dev/null");
                if (!is_string($out)) {
                    continue;
                }
                foreach (preg_split('/\s+/', trim($out)) ?: [] as $child) {
                    $child = (int) $child;
                    if ($child > 0) {
                        $next[] = $child;
                        $pids[] = $child;
                    }
                }
            }
            $frontier = $next;
        }

        $totalRss = 0;
        foreach (array_unique($pids) as $p) {
            $out = @shell_exec("ps -o rss= -p {$p} 2>/dev/null");
            $totalRss += (int) trim((string) $out);
        }

        if ($totalRss <= 0) {
            return null;
        }

        return [
            // master + manager + N workers: workers = tree size - 2
            'workers' => max(0, count(array_unique($pids)) - 2),
            'rssMb' => round($totalRss / 1024, 1),
        ];
    }
}
