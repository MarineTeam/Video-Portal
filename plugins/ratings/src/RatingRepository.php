<?php

declare(strict_types=1);

namespace Portal\Plugins\Ratings;

use Portal\Db;
use Portal\Support\Str;

/**
 * Reading and writing ratings.
 *
 * Every write goes through recount(), which rebuilds the cached total from the
 * rows it summarises. Incrementing would be one query cheaper and would be
 * wrong the first time anything failed halfway, with nothing left to notice it
 * by.
 */
final class RatingRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    /**
     * The totals for one video.
     *
     * @return array{count: int, sum: int, average: float}
     */
    public function forVideo(int $videoId): array
    {
        $row = $this->db->first(
            'SELECT vote_count, score_sum, average FROM {rating_totals} WHERE video_id = ?',
            [$videoId]
        );

        if ($row === null) {
            return ['count' => 0, 'sum' => 0, 'average' => 0.0];
        }

        return [
            'count'   => (int) $row['vote_count'],
            'sum'     => (int) $row['score_sum'],
            'average' => (float) $row['average'],
        ];
    }

    /**
     * Totals for many videos, in one query.
     *
     * For listings. The per-video version called in a loop is a query per card,
     * which is the same mistake the totals table exists to avoid.
     *
     * @param  list<int> $videoIds
     * @return array<int, array{count: int, sum: int, average: float}> keyed by video id
     */
    public function forVideos(array $videoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $videoIds))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT video_id, vote_count, score_sum, average FROM {rating_totals}
              WHERE video_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['video_id']] = [
                'count'   => (int) $row['vote_count'],
                'sum'     => (int) $row['score_sum'],
                'average' => (float) $row['average'],
            ];
        }

        // Videos nobody has rated get a zero entry rather than being absent, so
        // a caller can read every id it asked about without checking first.
        foreach ($ids as $id) {
            $out[$id] ??= ['count' => 0, 'sum' => 0, 'average' => 0.0];
        }

        return $out;
    }

    /** What this person gave this video, or null if they have not rated it. */
    public function scoreBy(int $videoId, string $email): ?int
    {
        $score = $this->db->value(
            'SELECT score FROM {ratings} WHERE video_id = ? AND rater_email = ?',
            [$videoId, Str::normalizeEmail($email)]
        );

        return $score === null ? null : (int) $score;
    }

    /**
     * The mean across every rating on the site.
     *
     * The prior the ranking pulls toward. Computed from the totals table rather
     * than from {ratings}, so it costs one small scan rather than a pass over
     * every vote ever cast.
     */
    public function siteAverage(): float
    {
        $row = $this->db->first(
            'SELECT SUM(score_sum) AS s, SUM(vote_count) AS n FROM {rating_totals}'
        );

        $count = (int) ($row['n'] ?? 0);
        $sum = (int) ($row['s'] ?? 0);

        // With nothing to average, the midpoint is the only defensible prior:
        // seeding at 0 would bury the first rated video and at 5 would crown it.
        return $count > 0
            ? RatingPolicy::average($count, $sum)
            : (RatingPolicy::MIN_SCORE + RatingPolicy::MAX_SCORE) / 2.0;
    }

    /**
     * Best rated first, weighted so a single vote cannot take the top.
     *
     * The weighting is done in SQL because the ordering has to be applied
     * before the LIMIT; doing it in PHP would mean fetching every rated video
     * to find ten.
     *
     * @return list<array<string, mixed>>
     */
    public function leaderboard(int $limit = 25, int $priorVotes = RatingPolicy::PRIOR_VOTES): array
    {
        $limit = max(1, min(200, $limit));
        $priorVotes = max(0, $priorVotes);
        $site = $this->siteAverage();

        return $this->db->all(
            'SELECT t.video_id, t.vote_count, t.score_sum, t.average,
                    v.title, v.slug,
                    ((? * ?) + t.score_sum) / (? + t.vote_count) AS ranking
               FROM {rating_totals} t
               JOIN {videos} v ON v.id = t.video_id
              WHERE t.vote_count > 0
              ORDER BY ranking DESC, t.vote_count DESC, v.title ASC
              LIMIT ' . $limit,
            [$priorVotes, $site, $priorVotes]
        );
    }

    /** How many videos have at least one rating. */
    public function ratedVideoCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {rating_totals} WHERE vote_count > 0'
        );
    }

    // ----------------------------------------------------------------- writes

    /**
     * Record, or change, one person's rating.
     *
     * ON DUPLICATE KEY UPDATE rather than a lookup followed by an insert or an
     * update: the unique index is what guarantees one vote per person, and any
     * version that decides between insert and update in PHP has a window
     * between the two where a second request can slip through.
     *
     * @return bool false when changing was refused because changes are off
     */
    public function rate(int $videoId, string $email, int $score, bool $allowChanges = true): bool
    {
        $email = Str::normalizeEmail($email);

        if (!$allowChanges && $this->scoreBy($videoId, $email) !== null) {
            return false;
        }

        $this->db->execute(
            'INSERT INTO {ratings} (video_id, user_id, rater_email, score, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()',
            [$videoId, $this->userIdFor($email), $email, $score]
        );

        $this->recount($videoId);

        return true;
    }

    /** Withdraw a rating. Silent if there was not one. */
    public function remove(int $videoId, string $email): void
    {
        $this->db->execute(
            'DELETE FROM {ratings} WHERE video_id = ? AND rater_email = ?',
            [$videoId, Str::normalizeEmail($email)]
        );

        $this->recount($videoId);
    }

    /**
     * Rebuild one video's cached total from its rows.
     *
     * Public because it is also the repair: if the cache is ever doubted, this
     * is what settles it, and a maintenance job can call it for everything.
     */
    public function recount(int $videoId): void
    {
        $row = $this->db->first(
            'SELECT COUNT(*) AS n, COALESCE(SUM(score), 0) AS s FROM {ratings} WHERE video_id = ?',
            [$videoId]
        );

        $count = (int) ($row['n'] ?? 0);
        $sum = (int) ($row['s'] ?? 0);

        if ($count === 0) {
            // No row at all rather than a row of zeroes: "unrated" is then one
            // state instead of two that have to agree.
            $this->db->execute('DELETE FROM {rating_totals} WHERE video_id = ?', [$videoId]);

            return;
        }

        $this->db->execute(
            'INSERT INTO {rating_totals} (video_id, vote_count, score_sum, average, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               vote_count = VALUES(vote_count),
               score_sum  = VALUES(score_sum),
               average    = VALUES(average),
               updated_at = NOW()',
            [$videoId, $count, $sum, RatingPolicy::average($count, $sum)]
        );
    }

    // ------------------------------------------------------------- internals

    private function userIdFor(string $email): ?int
    {
        $id = $this->db->value('SELECT id FROM {users} WHERE email = ?', [Str::normalizeEmail($email)]);

        return $id === null ? null : (int) $id;
    }
}
