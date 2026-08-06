<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * A video's chapters.
 *
 * Deliberately thin. Chapters are a handful of rows read whole for one video
 * and never searched across the library, so there is nothing here beyond
 * storing a list and handing it back in order — and a repository that invented
 * more than that would be inventing work.
 */
final class ChapterRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return list<array{start: int, title: string}>
     */
    public function forVideo(int $videoId): array
    {
        $rows = $this->db->all(
            'SELECT start_at, title FROM {chapters} WHERE video_id = ? ORDER BY start_at',
            [$videoId]
        );

        return array_map(static fn (array $row): array => [
            'start' => (int) $row['start_at'],
            'title' => (string) $row['title'],
        ], $rows);
    }

    public function count(int $videoId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {chapters} WHERE video_id = ?', [$videoId]);
    }

    /**
     * Replace a video's chapters.
     *
     * Wholesale, in one transaction, for the same reason as a transcript: the
     * screen edits the list as text, so the list as submitted IS the answer.
     * Diffing it against what was there would be more code to produce the same
     * result, and could leave the two half-merged if it went wrong.
     *
     * @param  list<array{start: int, title: string}> $chapters
     * @return int how many were stored
     */
    public function replace(int $videoId, array $chapters): int
    {
        $this->db->transaction(function () use ($videoId, $chapters): void {
            $this->db->execute('DELETE FROM {chapters} WHERE video_id = ?', [$videoId]);

            foreach ($chapters as $chapter) {
                // INSERT IGNORE against the unique key, so a duplicated moment
                // the parser somehow let through cannot abort the whole save
                // and lose the rest of an editor's list.
                $this->db->execute(
                    'INSERT IGNORE INTO {chapters} (video_id, start_at, title) VALUES (?, ?, ?)',
                    [$videoId, $chapter['start'], $chapter['title']]
                );
            }
        });

        return $this->count($videoId);
    }

    public function delete(int $videoId): void
    {
        $this->db->execute('DELETE FROM {chapters} WHERE video_id = ?', [$videoId]);
    }
}
