<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * Counting what got watched.
 *
 * Every write is an upsert against (video_id, day), so two people finishing the
 * same video at the same moment cannot lose a count to a read-then-write — the
 * same discipline the fire-once tables use, applied to the opposite problem.
 *
 * There is deliberately no per-viewer history. See the migration.
 */
final class ViewRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ----------------------------------------------------------------- writes

    /**
     * Record one view, and optionally that it was finished.
     *
     * Deduplication is the CALLER's job — this counts what it is told. That
     * split is deliberate: "one view per person per video per session" needs a
     * session, and a repository that reached for one could not be tested
     * without inventing a request.
     */
    public function record(int $videoId, bool $completed = false): void
    {
        $this->db->execute(
            'INSERT INTO {video_views} (video_id, day, views, completions)
             VALUES (?, CURDATE(), 1, ?)
             ON DUPLICATE KEY UPDATE
               views = views + 1,
               completions = completions + VALUES(completions)',
            [$videoId, $completed ? 1 : 0]
        );
    }

    /**
     * Record a completion against a view already counted.
     *
     * Somebody starts a video and finishes it in the same session: the view was
     * counted when they started, and counting a second one when they finish
     * would report twice the audience. Only the completion is added.
     */
    public function recordCompletion(int $videoId): void
    {
        $this->db->execute(
            'INSERT INTO {video_views} (video_id, day, views, completions)
             VALUES (?, CURDATE(), 0, 1)
             ON DUPLICATE KEY UPDATE completions = completions + 1',
            [$videoId]
        );
    }

    // ------------------------------------------------------------------ reads

    /**
     * Totals across a window.
     *
     * @return array{views: int, completions: int}
     */
    public function summary(int $days = 30): array
    {
        $row = $this->db->first(
            'SELECT COALESCE(SUM(views), 0) AS v, COALESCE(SUM(completions), 0) AS c
               FROM {video_views}
              WHERE day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)',
            [$this->clampDays($days)]
        );

        return [
            'views'       => (int) ($row['v'] ?? 0),
            'completions' => (int) ($row['c'] ?? 0),
        ];
    }

    /**
     * The most-watched videos in a window.
     *
     * Joined to {videos} rather than left-joined: a view whose video has been
     * deleted is a row the cascade should already have removed, and showing a
     * nameless line would be a bug report waiting to happen.
     *
     * @return list<array<string, mixed>>
     */
    public function topVideos(int $days = 30, int $limit = 25): array
    {
        return $this->db->all(
            'SELECT vv.video_id,
                    SUM(vv.views) AS views,
                    SUM(vv.completions) AS completions,
                    v.title, v.slug
               FROM {video_views} vv
               JOIN {videos} v ON v.id = vv.video_id
              WHERE vv.day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                AND v.deleted_at IS NULL
              GROUP BY vv.video_id, v.title, v.slug
              ORDER BY views DESC, v.title ASC
              LIMIT ' . max(1, min(200, $limit)),
            [$this->clampDays($days)]
        );
    }

    /**
     * One video's daily figures, oldest first.
     *
     * @return list<array{day: string, views: int, completions: int}>
     */
    public function forVideo(int $videoId, int $days = 30): array
    {
        $rows = $this->db->all(
            'SELECT day, views, completions FROM {video_views}
              WHERE video_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              ORDER BY day',
            [$videoId, $this->clampDays($days)]
        );

        return array_map(static fn (array $row): array => [
            'day'         => (string) $row['day'],
            'views'       => (int) $row['views'],
            'completions' => (int) $row['completions'],
        ], $rows);
    }

    /** Everything ever counted for one video. */
    public function totalFor(int $videoId): int
    {
        return (int) $this->db->value(
            'SELECT COALESCE(SUM(views), 0) FROM {video_views} WHERE video_id = ?',
            [$videoId]
        );
    }

    /**
     * The window options the screen offers.
     *
     * @return array<int, string>
     */
    public static function periods(): array
    {
        return [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'];
    }

    /**
     * A window that is one of the offered ones.
     *
     * Bound rather than free, because the value arrives in a query string and
     * an unbounded INTERVAL is a scan of the whole table somebody can ask for
     * repeatedly.
     */
    public static function sanitizePeriod(mixed $raw): int
    {
        $days = (int) $raw;

        return array_key_exists($days, self::periods()) ? $days : 30;
    }

    private function clampDays(int $days): int
    {
        return max(1, min(3650, $days));
    }
}
