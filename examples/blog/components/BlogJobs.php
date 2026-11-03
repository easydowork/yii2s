<?php

namespace app\components;

use app\models\Post;
use Yii;

/**
 * Queue handler and cache-version helpers.
 *
 * Publishing a post pushes a job; `php yii queue/serve` consumes it and
 * writes an audit row. That exercises the Redis queue component, the pooled
 * DB connection in the worker and the cache invalidation path.
 */
final class BlogJobs
{
    /** Redis key holding the list-cache version. */
    public const CACHE_VERSION_KEY = 'blog:list:version';

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $job
     */
    public static function handle(array $payload, array $job): void
    {
        if (($payload['force_fail'] ?? false) === true) {
            // Demo hook for the retry -> dead-letter path (`blog/queue-failure`).
            throw new \RuntimeException('forced queue failure (demo)');
        }

        $action = (string) ($payload['action'] ?? 'unknown');
        $postId = (int) ($payload['post_id'] ?? 0);
        $post = $postId > 0 ? Post::findOne($postId) : null;

        Yii::$app->db->createCommand()->insert('{{%audit_log}}', [
            'action' => $action,
            'post_id' => $postId > 0 ? $postId : null,
            'message' => $post === null
                ? sprintf('job %s for post %d: post not found', $job['id'] ?? '?', $postId)
                : sprintf('job %s: %s "%s" (status %d)', $job['id'] ?? '?', $action, $post->title, $post->status),
            'created_at' => time(),
        ])->execute();
    }

    /**
     * Invalidates the cached front-page list: cache keys embed the version,
     * so bumping it makes every page miss without scanning Redis.
     */
    public static function invalidateListCache(): void
    {
        Yii::$app->redis->incr(self::CACHE_VERSION_KEY);
    }

    public static function listVersion(): int
    {
        $version = Yii::$app->redis->get(self::CACHE_VERSION_KEY);

        return is_numeric($version) ? (int) $version : 1;
    }
}
