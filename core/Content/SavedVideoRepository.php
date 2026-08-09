<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * What one viewer has saved for themselves.
 *
 * Favourites and watch-later are the same mechanism wearing two labels, so they
 * share a table and differ by a column. The distinction that matters is against
 * playlists, not between these two: a playlist is content somebody published, a
 * saved video is a private bookmark nobody else can see or count.
 */
final class SavedVideoRepository
{
    public const FAVORITE = 'favorite';
    public const WATCH_LATER = 'watch_later';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<string> the lists a viewer can put something on */
    public static function lists(): array
    {
        return [self::FAVORITE, self::WATCH_LATER];
    }

    /**
     * A submitted list name, or null if it is not one.
     *
     * Refused rather than defaulted. Quietly treating an unrecognised name as
     * "favourite" would put a video somewhere the person did not ask for, and a
     * button that saves to the wrong list is worse than one that does nothing.
     */
    public static function sanitizeList(mixed $raw): ?string
    {
        $list = is_string($raw) ? trim($raw) : '';

        return in_array($list, self::lists(), true) ? $list : null;
    }

    // ------------------------------------------------------------------ reads

    /**
     * One viewer's saved videos, newest first.
     *
     * Visibility is applied here, not left to the caller. Somebody can save a
     * video and later lose access to it — the category becomes members-only, or
     * it is unpublished — and a saved list is exactly the back door that would
     * keep showing it.
     *
     * @return list<Video>
     */
    public function videos(int $userId, string $list, bool $includeMemberOnly = false, int $limit = 100): array
    {
        $conditions = [
            's.user_id = ?',
            's.list = ?',
            'v.deleted_at IS NULL',
            "v.status = 'ready'",
            'v.is_published = 1',
        ];

        if (!$includeMemberOnly) {
            $conditions[] = 'v.member_only = 0';
        }

        $rows = $this->db->all(
            'SELECT v.* FROM {saved_videos} s
               JOIN {videos} v ON v.id = s.video_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY s.created_at DESC, v.id DESC
              LIMIT ' . max(1, min(500, $limit)),
            [$userId, $list]
        );

        return array_map(static fn (array $row): Video => Video::fromRow($row), $rows);
    }

    /**
     * Which of this viewer's lists a video is on.
     *
     * @return list<string>
     */
    public function listsFor(int $userId, int $videoId): array
    {
        $rows = $this->db->all(
            'SELECT list FROM {saved_videos} WHERE user_id = ? AND video_id = ?',
            [$userId, $videoId]
        );

        return array_map(static fn (array $row): string => (string) $row['list'], $rows);
    }

    /** @return array<string, int> list => how many */
    public function counts(int $userId): array
    {
        $out = [self::FAVORITE => 0, self::WATCH_LATER => 0];

        foreach ($this->db->all(
            'SELECT list, COUNT(*) AS n FROM {saved_videos} WHERE user_id = ? GROUP BY list',
            [$userId]
        ) as $row) {
            $out[(string) $row['list']] = (int) $row['n'];
        }

        return $out;
    }

    // ----------------------------------------------------------------- writes

    /**
     * Save a video.
     *
     * INSERT IGNORE against the primary key rather than a lookup followed by an
     * insert. A double-click is the normal way somebody saves twice, and a
     * read-then-write has a window between the two.
     *
     * @return bool whether this was new
     */
    public function save(int $userId, int $videoId, string $list): bool
    {
        if (!in_array($list, self::lists(), true)) {
            return false;
        }

        return $this->db->execute(
            'INSERT IGNORE INTO {saved_videos} (user_id, video_id, list, created_at)
             VALUES (?, ?, ?, NOW())',
            [$userId, $videoId, $list]
        ) > 0;
    }

    /** Unsave. Silent if it was not saved. */
    public function forget(int $userId, int $videoId, string $list): void
    {
        $this->db->execute(
            'DELETE FROM {saved_videos} WHERE user_id = ? AND video_id = ? AND list = ?',
            [$userId, $videoId, $list]
        );
    }

    /**
     * Save if it is not saved, forget if it is.
     *
     * One route for both, because the button is one button. Deciding in PHP
     * between two statements would race with a second tab; instead the delete
     * runs first and its row count decides whether an insert follows, so the
     * worst a race can produce is the state the person last asked for.
     *
     * @return bool the state afterwards: true when saved
     */
    public function toggle(int $userId, int $videoId, string $list): bool
    {
        if (!in_array($list, self::lists(), true)) {
            return false;
        }

        $removed = $this->db->execute(
            'DELETE FROM {saved_videos} WHERE user_id = ? AND video_id = ? AND list = ?',
            [$userId, $videoId, $list]
        );

        if ($removed > 0) {
            return false;
        }

        $this->save($userId, $videoId, $list);

        return true;
    }
}
