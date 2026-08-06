<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Support\Str;

/**
 * Storing and finding what was said.
 *
 * Replacing a transcript is a delete and an insert inside one transaction, not
 * a merge. Two transcripts of the same recording are not something to
 * reconcile — they are one mistake — and a partial replacement would leave
 * cues from two takes interleaved by timestamp, which is unreadable and very
 * hard to explain.
 */
final class TranscriptRepository
{
    /** How much context a search result shows around the matched words. */
    private const SNIPPET_RADIUS = 90;

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    /** @return array<string, mixed>|null */
    public function find(int $videoId): ?array
    {
        return $this->db->first('SELECT * FROM {transcripts} WHERE video_id = ?', [$videoId]);
    }

    public function has(int $videoId): bool
    {
        return $this->db->value('SELECT video_id FROM {transcripts} WHERE video_id = ?', [$videoId]) !== null;
    }

    /**
     * Every cue for one video, in order.
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    public function cues(int $videoId): array
    {
        $rows = $this->db->all(
            'SELECT start_at, end_at, text FROM {transcript_cues}
              WHERE video_id = ? ORDER BY start_at, id',
            [$videoId]
        );

        return array_map(static fn (array $row): array => [
            'start' => (int) $row['start_at'],
            'end'   => (int) $row['end_at'],
            'text'  => (string) $row['text'],
        ], $rows);
    }

    /**
     * Which of these videos have a transcript.
     *
     * Batched, because a listing that asked per card would be a query per
     * video — the same mistake the thumbnail modes exist to avoid.
     *
     * @param  list<int> $videoIds
     * @return list<int>
     */
    public function existingFor(array $videoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $videoIds))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT video_id FROM {transcripts} WHERE video_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );

        return array_map(static fn (array $row): int => (int) $row['video_id'], $rows);
    }

    /**
     * The first cue matching every term, for one video.
     *
     * What a search result shows: the moment somebody can click. Every term
     * must appear in the SAME cue here, unlike the library search where they
     * may be spread across a title and a series — a "moment" that spans two
     * unrelated sentences is not a moment.
     *
     * @param  list<string> $terms
     * @return array{start: int, text: string}|null
     */
    public function firstMatch(int $videoId, array $terms): ?array
    {
        if ($terms === []) {
            return null;
        }

        $conditions = [];
        $params = [$videoId];

        foreach ($terms as $term) {
            $conditions[] = 'LOWER(text) LIKE ?';
            $params[] = '%' . $this->db->escapeLike($term) . '%';
        }

        $row = $this->db->first(
            'SELECT start_at, text FROM {transcript_cues}
              WHERE video_id = ? AND ' . implode(' AND ', $conditions) . '
              ORDER BY start_at, id LIMIT 1',
            $params
        );

        return $row === null
            ? null
            : ['start' => (int) $row['start_at'], 'text' => (string) $row['text']];
    }

    /**
     * A short piece of the transcript around the first matching term.
     *
     * Built from the flattened body rather than a cue, because a phrase often
     * straddles two cues and a snippet cut at a cue boundary reads as though
     * the sentence was interrupted.
     *
     * @param list<string> $terms
     */
    public function snippet(int $videoId, array $terms): string
    {
        $body = (string) ($this->db->value(
            'SELECT body FROM {transcripts} WHERE video_id = ?',
            [$videoId]
        ) ?? '');

        if ($body === '' || $terms === []) {
            return '';
        }

        $position = false;
        foreach ($terms as $term) {
            $found = mb_stripos($body, $term);
            if ($found !== false) {
                $position = $found;
                break;
            }
        }

        if ($position === false) {
            return Str::truncate($body, self::SNIPPET_RADIUS * 2);
        }

        $from = max(0, $position - self::SNIPPET_RADIUS);
        $snippet = mb_substr($body, $from, self::SNIPPET_RADIUS * 2);

        // Ellipses only where text was actually cut, so a match near the start
        // does not look like it is missing something in front of it.
        return ($from > 0 ? '…' : '')
            . trim($snippet)
            . ($from + (self::SNIPPET_RADIUS * 2) < mb_strlen($body) ? '…' : '');
    }

    // ----------------------------------------------------------------- writes

    /**
     * Replace a video's transcript.
     *
     * One transaction: a half-written transcript — the summary row updated but
     * the cues from the old one still in place — would show a cue count that
     * does not match the panel, and nothing would ever notice.
     *
     * @param  list<array{start: int, end: int, text: string}> $cues
     * @return int how many cues were stored
     */
    public function replace(int $videoId, array $cues, string $source = ''): int
    {
        $body = TranscriptParser::plainText($cues);
        $now = date('Y-m-d H:i:s');

        $this->db->transaction(function () use ($videoId, $cues, $source, $body, $now): void {
            $this->db->execute('DELETE FROM {transcript_cues} WHERE video_id = ?', [$videoId]);

            foreach ($cues as $cue) {
                $this->db->execute(
                    'INSERT INTO {transcript_cues} (video_id, start_at, end_at, text)
                     VALUES (?, ?, ?, ?)',
                    [$videoId, $cue['start'], $cue['end'], $cue['text']]
                );
            }

            if ($cues === []) {
                // Nothing parsed. Removing the summary row too, rather than
                // leaving one claiming a transcript exists with no cues behind
                // it — the panel would render an empty box and the admin screen
                // would say "0 cues" as though that were a state worth having.
                $this->db->execute('DELETE FROM {transcripts} WHERE video_id = ?', [$videoId]);

                return;
            }

            $this->db->execute(
                'INSERT INTO {transcripts} (video_id, body, source, cue_count, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   body = VALUES(body), source = VALUES(source),
                   cue_count = VALUES(cue_count), updated_at = VALUES(updated_at)',
                [$videoId, $body, substr($source, 0, 100), count($cues), $now, $now]
            );
        });

        return count($cues);
    }

    public function delete(int $videoId): void
    {
        $this->db->transaction(function () use ($videoId): void {
            $this->db->execute('DELETE FROM {transcript_cues} WHERE video_id = ?', [$videoId]);
            $this->db->execute('DELETE FROM {transcripts} WHERE video_id = ?', [$videoId]);
        });
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {transcripts}');
    }
}
