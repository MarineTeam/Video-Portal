<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Playlists and their running order.
 *
 * Deliberately shaped like SeriesRepository, because an editor should not have
 * to learn two mental models for two screens that look the same. The one place
 * they diverge is membership: a series owns its videos through a column, so
 * setVideos() detaches the old ones; a playlist owns nothing, so the same call
 * only rewrites rows in the join table and a video removed from one playlist is
 * untouched everywhere else.
 */
final class PlaylistRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    public function find(int $id): ?Playlist
    {
        $row = $this->db->first($this->selectWithCount() . ' WHERE p.id = ?', [$id]);
        return $row === null ? null : Playlist::fromRow($row);
    }

    public function findBySlug(string $slug): ?Playlist
    {
        $row = $this->db->first($this->selectWithCount() . ' WHERE p.slug = ?', [$slug]);
        return $row === null ? null : Playlist::fromRow($row);
    }

    /**
     * An old address, after a rename.
     *
     * Same treatment categories and series get: a printed or emailed link
     * outlives the title somebody typed on the first attempt.
     */
    public function findByAlias(string $slug): ?Playlist
    {
        $id = $this->db->value(
            'SELECT target_id FROM {slug_aliases} WHERE target_type = "playlist" AND slug = ?',
            [$slug]
        );

        return $id === null ? null : $this->find((int) $id);
    }

    /** @return list<Playlist> */
    public function all(bool $includeUnpublished = false): array
    {
        $where = $includeUnpublished ? '' : ' WHERE p.is_published = 1 AND p.hidden = 0';

        $rows = $this->db->all($this->selectWithCount() . $where . ' ORDER BY p.position, p.title');

        return array_map(static fn (array $row): Playlist => Playlist::fromRow($row), $rows);
    }

    /**
     * The videos in one playlist, in the order somebody arranged.
     *
     * Visibility is applied here rather than left to the caller. A playlist is
     * a hand-made list, so it is exactly the place an unpublished or
     * members-only video would be quietly included and then rendered to
     * everybody.
     *
     * @return list<Video>
     */
    public function videos(int $playlistId, bool $includeUnpublished = false, bool $includeMemberOnly = false): array
    {
        $conditions = ['pi.playlist_id = ?', 'v.deleted_at IS NULL'];
        $params = [$playlistId];

        if (!$includeUnpublished) {
            $conditions[] = "v.status = 'ready'";
            $conditions[] = 'v.is_published = 1';
            $conditions[] = '(v.published_at IS NULL OR v.published_at <= NOW())';
        }
        if (!$includeMemberOnly) {
            $conditions[] = 'v.member_only = 0';
        }

        $rows = $this->db->all(
            'SELECT v.* FROM {playlist_items} pi
               JOIN {videos} v ON v.id = pi.video_id
              WHERE ' . implode(' AND ', $conditions) . '
              ORDER BY pi.position, v.id',
            $params
        );

        return array_map(static fn (array $row): Video => Video::fromRow($row), $rows);
    }

    /**
     * Which playlists a video is in.
     *
     * @return list<int>
     */
    public function playlistIdsFor(int $videoId): array
    {
        $rows = $this->db->all(
            'SELECT playlist_id FROM {playlist_items} WHERE video_id = ?',
            [$videoId]
        );

        return array_map(static fn (array $row): int => (int) $row['playlist_id'], $rows);
    }

    /**
     * The ordered video ids in one playlist, including ones a visitor could not
     * see.
     *
     * For the edit screen, which must show an editor everything they put in —
     * a video that vanished from the form because it was unpublished would be
     * silently dropped the next time they saved.
     *
     * @return list<int>
     */
    public function orderedVideoIds(int $playlistId): array
    {
        $rows = $this->db->all(
            'SELECT pi.video_id FROM {playlist_items} pi
               JOIN {videos} v ON v.id = pi.video_id
              WHERE pi.playlist_id = ? AND v.deleted_at IS NULL
              ORDER BY pi.position, pi.video_id',
            [$playlistId]
        );

        return array_map(static fn (array $row): int => (int) $row['video_id'], $rows);
    }

    // ----------------------------------------------------------------- writes

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Playlist
    {
        $title = trim((string) ($attributes['title'] ?? ''));
        if ($title === '') {
            throw HttpException::badRequest('A playlist needs a title.');
        }

        $now = date('Y-m-d H:i:s');

        $id = $this->db->insert('playlists', [
            'slug'         => $this->uniqueSlug((string) ($attributes['slug'] ?? $title)),
            'title'        => $title,
            'description'  => $attributes['description'] ?? null,
            'position'     => $this->nextPosition(),
            'is_published' => isset($attributes['is_published']) ? (int) (bool) $attributes['is_published'] : 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $playlist = $this->find($id);
        if ($playlist === null) {
            throw new \RuntimeException('The playlist vanished immediately after being created.');
        }

        return $playlist;
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): Playlist
    {
        $playlist = $this->find($id);
        if ($playlist === null) {
            throw HttpException::notFound('That playlist does not exist.');
        }

        $fields = [];

        if (isset($attributes['title'])) {
            $title = trim((string) $attributes['title']);
            if ($title === '') {
                throw HttpException::badRequest('A playlist needs a title.');
            }
            $fields['title'] = $title;
        }

        if (isset($attributes['slug'])) {
            $slug = $this->uniqueSlug((string) $attributes['slug'], $id);
            if ($slug !== $playlist->slug) {
                $this->recordAlias($id, $playlist->slug);
                $fields['slug'] = $slug;
            }
        }

        if (array_key_exists('description', $attributes)) {
            $fields['description'] = $attributes['description'];
        }

        foreach (['is_published', 'member_only', 'hidden', 'featured'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $fields[$key] = (int) (bool) $attributes[$key];
            }
        }

        if ($fields === []) {
            return $playlist;
        }

        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('playlists', $fields, ['id' => $id]);

        return $this->find($id) ?? $playlist;
    }

    /**
     * Delete a playlist without deleting its videos.
     *
     * The cascade removes the membership rows only. Deleting content because
     * somebody removed the list it appeared on would be an unrecoverable
     * surprise, and a playlist is the most casually deleted thing here.
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {playlists} WHERE id = ?', [$id]);
        $this->db->execute(
            'DELETE FROM {slug_aliases} WHERE target_type = "playlist" AND target_id = ?',
            [$id]
        );
    }

    // --------------------------------------------------------------- ordering

    /**
     * Set the videos in a playlist, in the given order.
     *
     * The whole membership is rewritten inside a transaction rather than
     * diffed. A diff would be fewer writes and would have to get "was here,
     * still here, moved" right for every row; rewriting cannot leave the list
     * half-updated, and these lists are tens of items, not thousands.
     *
     * @param list<int> $orderedVideoIds
     */
    public function setVideos(int $playlistId, array $orderedVideoIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderedVideoIds))));

        $this->db->transaction(function () use ($playlistId, $ids): void {
            $this->db->execute('DELETE FROM {playlist_items} WHERE playlist_id = ?', [$playlistId]);

            $position = 0;
            foreach ($ids as $videoId) {
                // INSERT IGNORE, so a video id that no longer exists is skipped
                // rather than aborting the whole save and losing the rest of an
                // editor's arrangement.
                $this->db->execute(
                    'INSERT IGNORE INTO {playlist_items} (playlist_id, video_id, position, created_at)
                     VALUES (?, ?, ?, NOW())',
                    [$playlistId, $videoId, $position]
                );
                $position++;
            }
        });
    }

    /**
     * Move one video up or down within a playlist.
     *
     * Swapping with the neighbour rather than renumbering the run: two writes
     * regardless of length, and it cannot disturb the order of anything the
     * editor was not looking at.
     *
     * The playlist id is part of every lookup. Without it the neighbour search
     * finds whichever row in ANY playlist happens to hold the adjacent
     * position, and two playlists then reorder each other.
     */
    public function move(int $playlistId, int $videoId, int $direction): void
    {
        $item = $this->db->first(
            'SELECT video_id, position FROM {playlist_items} WHERE playlist_id = ? AND video_id = ?',
            [$playlistId, $videoId]
        );

        if ($item === null) {
            return;
        }

        $comparison = $direction < 0 ? '<' : '>';
        $order = $direction < 0 ? 'DESC' : 'ASC';

        $neighbour = $this->db->first(
            "SELECT video_id, position FROM {playlist_items}
              WHERE playlist_id = ? AND position {$comparison} ?
              ORDER BY position {$order} LIMIT 1",
            [$playlistId, (int) $item['position']]
        );

        if ($neighbour === null) {
            return; // Already at the end.
        }

        $this->db->transaction(function () use ($playlistId, $item, $neighbour): void {
            $this->db->execute(
                'UPDATE {playlist_items} SET position = ? WHERE playlist_id = ? AND video_id = ?',
                [(int) $neighbour['position'], $playlistId, (int) $item['video_id']]
            );
            $this->db->execute(
                'UPDATE {playlist_items} SET position = ? WHERE playlist_id = ? AND video_id = ?',
                [(int) $item['position'], $playlistId, (int) $neighbour['video_id']]
            );
        });
    }

    // ------------------------------------------------------------- internals

    public function uniqueSlug(string $desired, ?int $ignoreId = null): string
    {
        $base = Str::slug($desired);
        if ($base === '') {
            $base = 'playlist';
        }

        $slug = $base;
        $suffix = 1;

        while (true) {
            $sql = 'SELECT id FROM {playlists} WHERE slug = ?';
            $params = [$slug];

            if ($ignoreId !== null) {
                $sql .= ' AND id <> ?';
                $params[] = $ignoreId;
            }

            if ($this->db->value($sql, $params) === null) {
                return $slug;
            }

            $suffix++;
            $slug = $base . '-' . $suffix;
        }
    }

    private function selectWithCount(): string
    {
        /*
         * The count is what a visitor would see, not what the playlist holds:
         * "12 videos" next to a page showing 9 because three are unpublished is
         * the kind of small lie that makes people distrust the rest of the page.
         */
        return 'SELECT p.*,
                       (SELECT COUNT(*) FROM {playlist_items} pi
                          JOIN {videos} v ON v.id = pi.video_id
                         WHERE pi.playlist_id = p.id
                           AND v.deleted_at IS NULL
                           AND v.is_published = 1
                           AND v.hidden = 0) AS video_count
                  FROM {playlists} p';
    }

    private function recordAlias(int $id, string $oldSlug): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO {slug_aliases} (target_type, target_id, slug, created_at)
             VALUES ("playlist", ?, ?, NOW())',
            [$id, $oldSlug]
        );
    }

    private function nextPosition(): int
    {
        $max = $this->db->value('SELECT MAX(position) FROM {playlists}');
        return $max === null ? 0 : ((int) $max) + 1;
    }
}
