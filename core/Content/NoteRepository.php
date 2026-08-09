<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * Notes somebody took while watching.
 *
 * Every method takes a user id and every query is scoped by it. Not as a
 * convention — as the only access control this has. There is no capability
 * that grants reading somebody else's notes, no admin screen that lists them,
 * and nothing here accepts a note id without also being told whose it should
 * be. A repository that could return a note by id alone would be one call away
 * from a screen that did.
 */
final class NoteRepository
{
    /**
     * A ceiling on one note.
     *
     * Generous — a page of typing is a couple of kilobytes and this is sixty —
     * and it exists because the panel autosaves, so a paste into it becomes a
     * write on a loop rather than a single mistake.
     */
    public const MAX_LENGTH = 60000;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * One person's note on one video.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $videoId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {video_notes} WHERE user_id = ? AND video_id = ?',
            [$userId, $videoId]
        );
    }

    public function body(int $userId, int $videoId): string
    {
        return (string) ($this->find($userId, $videoId)['body'] ?? '');
    }

    /**
     * Everything one person has written, with the video it belongs to.
     *
     * Joined rather than resolved per row, because the notes page shows all of
     * them at once and a query per note is the mistake the batched thumbnail
     * modes exist to avoid.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT n.*, v.title, v.slug
               FROM {video_notes} n
               JOIN {videos} v ON v.id = n.video_id
              WHERE n.user_id = ? AND v.deleted_at IS NULL AND n.body <> \'\'
              ORDER BY n.updated_at DESC
              LIMIT ' . max(1, min($limit, 500)),
            [$userId]
        );
    }

    public function count(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {video_notes} WHERE user_id = ? AND body <> \'\'',
            [$userId]
        );
    }

    /**
     * Write a note, or replace the one that is there.
     *
     * An upsert rather than a read-then-write. The panel autosaves, so several
     * saves from one page are in flight at once; reading first means a slow
     * request and a fast one produce two rows, and the older text wins.
     *
     * An EMPTY body deletes the note rather than storing nothing. That is how
     * somebody removes one — and it also keeps the notes page from listing a
     * video with a blank entry under it, which reads as a bug rather than as an
     * empty note.
     *
     * @return bool whether anything is now stored
     */
    public function save(int $userId, int $videoId, string $body): bool
    {
        $body = mb_substr(trim($body), 0, self::MAX_LENGTH);

        if ($body === '') {
            $this->delete($userId, $videoId);

            return false;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO {video_notes} (user_id, video_id, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE body = VALUES(body), updated_at = VALUES(updated_at)',
            [$userId, $videoId, $body, $now, $now]
        );

        return true;
    }

    public function delete(int $userId, int $videoId): void
    {
        $this->db->execute(
            'DELETE FROM {video_notes} WHERE user_id = ? AND video_id = ?',
            [$userId, $videoId]
        );
    }
}
