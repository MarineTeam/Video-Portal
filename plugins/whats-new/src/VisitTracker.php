<?php

declare(strict_types=1);

namespace Portal\Plugins\WhatsNew;

use Portal\Db;

/**
 * Keeps one marker per person, and answers which videos are newer than it.
 *
 * The reading and writing only; every judgement lives in WhatsNewPolicy.
 */
final class VisitTracker
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Roll the marker if this is a new visit, and hand back what to compare
     * publication dates against. Null means "badge nothing".
     *
     * Both writes are guarded UPDATEs rather than read-then-write. Pseudo-cron
     * aside, ordinary browsing fires several requests at once — a page and its
     * stylesheet and its script — and two of them agreeing that the visit is
     * new would otherwise roll the marker twice, the second time setting it to
     * the first one's timestamp and wiping every badge on the page being
     * rendered.
     */
    public function markerFor(int $userId, int $horizonDays, ?int $now = null): ?string
    {
        $now ??= time();
        $nowSql = date('Y-m-d H:i:s', $now);

        $row = $this->db->first(
            'SELECT marker_at, seen_at FROM {whats_new_visits} WHERE user_id = ?',
            [$userId]
        );

        if ($row === null) {
            /*
             * Never seen before. INSERT IGNORE because the primary key is the
             * guard, and nothing is badged: there is no previous visit for
             * anything to be new since, and marking the whole library on
             * somebody's first day is noise dressed as a feature.
             */
            $this->db->execute(
                'INSERT IGNORE INTO {whats_new_visits} (user_id, marker_at, seen_at) VALUES (?, NULL, ?)',
                [$userId, $nowSql]
            );

            return null;
        }

        $seenAt = isset($row['seen_at']) ? (string) $row['seen_at'] : null;
        $markerAt = isset($row['marker_at']) ? (string) $row['marker_at'] : null;

        if (WhatsNewPolicy::isReturning($seenAt, $now)) {
            $this->roll($userId, $now);

            /*
             * Used whether or not that UPDATE matched. If a concurrent request
             * rolled first it wrote this same value — it read the same seen_at
             * — so the answer is identical and re-reading would only cost a
             * query.
             */
            $markerAt = $seenAt;
        } elseif (WhatsNewPolicy::shouldTouch($seenAt, $now)) {
            $this->db->execute(
                'UPDATE {whats_new_visits} SET seen_at = ? WHERE user_id = ? AND seen_at <= ?',
                [$nowSql, $userId, date('Y-m-d H:i:s', $now - WhatsNewPolicy::TOUCH_INTERVAL)]
            );
        }

        return WhatsNewPolicy::cutoff($markerAt, $now, $horizonDays);
    }

    /**
     * Move the marker to the end of the previous visit. Answers whether it did.
     *
     * PUBLIC, and returning a bool, because this is the whole concurrency guard
     * and nothing else can watch it work. Calling markerFor() twice in one
     * process does not exercise it: the second call re-reads the stamp the
     * first one just wrote, correctly decides the visit is not new, and never
     * reaches here — so the WHERE clause could be deleted and the entire suite
     * would stay green. It did, when this was private.
     *
     * Two real requests arriving together both read the OLD stamp and both
     * decide to roll, which is the case that matters and the case a
     * single-threaded test can only reproduce by calling this directly. The
     * second roll would set the marker to the first one's timestamp — NOW —
     * and every badge on the page being rendered would vanish.
     *
     * Same shape as Notifier::claim(): a guard whose purpose is a condition the
     * test cannot stage has to be exposed and tested rather than tested around.
     */
    public function roll(int $userId, int $now): bool
    {
        return $this->db->execute(
            'UPDATE {whats_new_visits}
                SET marker_at = seen_at, seen_at = ?
              WHERE user_id = ? AND seen_at <= ?',
            [
                date('Y-m-d H:i:s', $now),
                $userId,
                date('Y-m-d H:i:s', $now - WhatsNewPolicy::SESSION_GAP),
            ]
        ) > 0;
    }

    /**
     * Which of these videos were published after $cutoff.
     *
     * One query for a whole page of cards, not one per card. The obvious
     * version of this is a lookup inside the loop that builds the badges, which
     * is fifty queries on a fifty-card page and only shows up on a real
     * library.
     *
     * COALESCE, because published_at is null for anything published the moment
     * it was created — the common case — and comparing null to anything drops
     * the row, so a plain comparison would badge nothing on most sites.
     *
     * @param list<int> $videoIds
     * @return array<int, true> the ids that are new, as a set
     */
    public function newAmong(array $videoIds, string $cutoff): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $videoIds))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT id FROM {videos}
              WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                AND COALESCE(published_at, created_at) > ?',
            [...$ids, $cutoff]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = true;
        }

        return $out;
    }
}
