<?php

declare(strict_types=1);

namespace Portal\Plugins\Reactions;

use Portal\Db;

/**
 * Storing and counting reactions.
 *
 * Counts are queried, never cached. See the migration for why: there is nothing
 * to derive, and a stored counter is right until the first thing that goes
 * wrong and then wrong forever with nothing to compare it against.
 */
final class ReactionRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * How many of each kind this video has.
     *
     * @return array<string, int> every kind in vocabulary order, zeroes included
     */
    public function forVideo(int $videoId): array
    {
        $rows = $this->db->all(
            'SELECT kind, COUNT(*) AS n FROM {reactions} WHERE video_id = ? GROUP BY kind',
            [$videoId]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['kind']] = (int) $row['n'];
        }

        // Through the policy, so a row left behind by an older vocabulary is
        // dropped rather than rendered as a button nobody can press.
        return ReactionPolicy::fill($counts);
    }

    /**
     * Which kinds this person has already left on this video.
     *
     * @return list<string>
     */
    public function byPerson(int $videoId, string $email): array
    {
        if ($email === '') {
            return [];
        }

        $rows = $this->db->column(
            'SELECT kind FROM {reactions} WHERE video_id = ? AND reactor_email = ?',
            [$videoId, $email]
        );

        return array_values(array_filter(
            array_map('strval', $rows),
            static fn (string $kind): bool => ReactionPolicy::isKind($kind)
        ));
    }

    /**
     * Add a reaction, or take it away if it is already there.
     *
     * A toggle rather than an add, because the button is the only thing that
     * shows the state — a person who pressed "Amen" by mistake has nowhere else
     * to go and would otherwise be stuck with it forever. Pressing again is the
     * obvious gesture and it is the one that works.
     *
     * @return bool true if the reaction is now ON
     */
    public function toggle(int $videoId, ?int $userId, string $email, string $kind): bool
    {
        if (!ReactionPolicy::isKind($kind) || $email === '') {
            return false;
        }

        /*
         * DELETE first and look at what it matched.
         *
         * That single statement answers "was it already there" and removes it,
         * with no window between asking and acting — where a read-then-write
         * would let two tabs both find nothing and both insert, and the unique
         * key would turn the second into an error rather than a toggle.
         */
        $removed = $this->db->execute(
            'DELETE FROM {reactions} WHERE video_id = ? AND reactor_email = ? AND kind = ?',
            [$videoId, $email, $kind]
        );

        if ($removed > 0) {
            return false;
        }

        // IGNORE, so the losing side of a genuine race is a no-op rather than a
        // 500. The row it collided with says exactly what this one would have.
        $this->db->execute(
            'INSERT IGNORE INTO {reactions} (video_id, user_id, reactor_email, kind, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$videoId, $userId, $email, $kind]
        );

        return true;
    }

    /**
     * Totals for a whole listing at once.
     *
     * One query for a page rather than one per card — the cost the batched
     * thumbnail modes, comment counts and tag lookups all exist to avoid, and a
     * listing is exactly where somebody reaches for the single-video version.
     *
     * @param  list<int> $videoIds
     * @return array<int, array<string, int>> video id => counts, omitting videos with none
     */
    public function forVideos(array $videoIds): array
    {
        $videoIds = array_values(array_unique(array_filter(array_map('intval', $videoIds))));
        if ($videoIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($videoIds), '?'));
        $rows = $this->db->all(
            "SELECT video_id, kind, COUNT(*) AS n
               FROM {reactions}
              WHERE video_id IN ({$placeholders})
              GROUP BY video_id, kind",
            $videoIds
        );

        $raw = [];
        foreach ($rows as $row) {
            $raw[(int) $row['video_id']][(string) $row['kind']] = (int) $row['n'];
        }

        $out = [];
        foreach ($raw as $videoId => $counts) {
            $out[$videoId] = ReactionPolicy::fill($counts);
        }

        return $out;
    }
}
