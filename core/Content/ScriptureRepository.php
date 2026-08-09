<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * Storing references, and finding what was preached on a passage.
 *
 * The two halves of the feature that need a database: keeping a video's
 * references in step with its description, and answering "everything on Romans
 * 8" without a LIKE that also matches Romans 80.
 */
final class ScriptureRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ reads

    /**
     * A video's references, in canonical order.
     *
     * Ordered by the book's position in the canon rather than alphabetically,
     * because a list reading Genesis, John, Romans is a list somebody can scan
     * and one reading 1 Corinthians, Acts, Genesis is not. FIELD() over the
     * slug list does it in SQL; the alternative is sorting in PHP after every
     * read, in three places.
     *
     * @return list<array<string, mixed>>
     */
    public function forVideo(int $videoId): array
    {
        return $this->db->all(
            'SELECT * FROM {scripture_refs}
              WHERE video_id = ?
              ORDER BY ' . $this->canonicalOrder() . ', chapter, COALESCE(verse, 0)',
            [$videoId]
        );
    }

    /**
     * Which of these videos have references.
     *
     * Batched, so a listing does not ask per card.
     *
     * @param  list<int> $videoIds
     * @return array<int, list<array<string, mixed>>> keyed by video id
     */
    public function forVideos(array $videoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $videoIds))));

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT * FROM {scripture_refs}
              WHERE video_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY ' . $this->canonicalOrder() . ', chapter',
            $ids
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['video_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Books that have anything under them, with counts.
     *
     * Only books with content. A browse page listing all seventy-three with
     * sixty-eight of them empty is a page that hides the five worth clicking.
     *
     * @return list<array{book: string, videos: int, chapters: int}>
     */
    public function booksInUse(): array
    {
        return $this->db->all(
            'SELECT r.book,
                    COUNT(DISTINCT r.video_id) AS videos,
                    COUNT(DISTINCT r.chapter)  AS chapters
               FROM {scripture_refs} r
               JOIN {videos} v ON v.id = r.video_id
              WHERE ' . $this->visible() . '
              GROUP BY r.book
              ORDER BY ' . $this->canonicalOrder('r.book')
        );
    }

    /**
     * Chapters of one book that have anything under them.
     *
     * A reference spanning chapters counts under every chapter it touches —
     * "Genesis 1:1-2:3" belongs on both the chapter 1 and chapter 2 pages,
     * because somebody looking for a sermon on Genesis 2 should find it. That
     * is why end_chapter exists as a real column rather than being derived.
     *
     * @return array<int, int> chapter => video count
     */
    public function chaptersInUse(string $book): array
    {
        $rows = $this->db->all(
            'SELECT n.chapter, COUNT(DISTINCT r.video_id) AS videos
               FROM {scripture_refs} r
               JOIN {videos} v ON v.id = r.video_id
               JOIN (' . $this->chapterNumbers(ScriptureBooks::chapters($book)) . ') n
                 ON n.chapter BETWEEN r.chapter AND r.end_chapter
              WHERE r.book = ? AND ' . $this->visible() . '
              GROUP BY n.chapter
              ORDER BY n.chapter',
            [$book]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['chapter']] = (int) $row['videos'];
        }

        return $counts;
    }

    /**
     * Video ids for a book, optionally narrowed to one chapter.
     *
     * Returns ids rather than videos, so the caller resolves them through
     * VideoRepository and gets the same visibility rules, presenter and
     * thumbnail handling every other listing gets. A second way to build a
     * video listing is a second place for the members-only rules to be wrong.
     *
     * @return list<int>
     */
    public function videoIds(string $book, ?int $chapter = null, int $limit = 200): array
    {
        $where = 'r.book = ? AND ' . $this->visible();
        $params = [$book];

        if ($chapter !== null) {
            // Overlap, not equality: a reference from chapter 1 to chapter 3
            // belongs on the chapter 2 page.
            $where .= ' AND ? BETWEEN r.chapter AND r.end_chapter';
            $params[] = $chapter;
        }

        $rows = $this->db->all(
            'SELECT DISTINCT r.video_id
               FROM {scripture_refs} r
               JOIN {videos} v ON v.id = r.video_id
              WHERE ' . $where . '
              ORDER BY r.video_id DESC
              LIMIT ' . max(1, min($limit, 500)),
            $params
        );

        return array_map(static fn (array $row): int => (int) $row['video_id'], $rows);
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {scripture_refs}');
    }

    // ----------------------------------------------------------------- writes

    /**
     * Replace a video's references from one source.
     *
     * Scoped to the source, which is the whole reason that column exists. A
     * re-scan of a description must not delete a reference an editor typed by
     * hand — they added it precisely because the description did not say it —
     * and an editor's list must not be silently extended by whatever the
     * description happens to mention.
     *
     * @param  list<array{book: string, chapter: int, verse: ?int, endChapter: int, endVerse: ?int, raw: string}> $references
     * @return int how many were stored
     */
    public function replace(int $videoId, array $references, string $source = 'parsed'): int
    {
        $source = $source === 'manual' ? 'manual' : 'parsed';
        $now = date('Y-m-d H:i:s');

        $this->db->transaction(function () use ($videoId, $references, $source, $now): void {
            $this->db->execute(
                'DELETE FROM {scripture_refs} WHERE video_id = ? AND source = ?',
                [$videoId, $source]
            );

            foreach ($references as $reference) {
                /*
                 * INSERT IGNORE against the unique key rather than checking
                 * first. The same passage can legitimately arrive twice from
                 * two sources — an editor types "Romans 8" and the description
                 * also says it — and the second one is not an error to report,
                 * it is the same fact.
                 */
                $this->db->execute(
                    'INSERT IGNORE INTO {scripture_refs}
                        (video_id, book, chapter, verse, end_chapter, end_verse, raw, source, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $videoId,
                        $reference['book'],
                        $reference['chapter'],
                        $reference['verse'],
                        $reference['endChapter'],
                        $reference['endVerse'],
                        substr($reference['raw'] ?? '', 0, 100),
                        $source,
                        $now,
                    ]
                );
            }
        });

        return count($references);
    }

    public function markScanned(int $videoId): void
    {
        $this->db->execute(
            'UPDATE {videos} SET scripture_scanned_at = NOW() WHERE id = ?',
            [$videoId]
        );
    }

    /**
     * Videos whose descriptions have never been read.
     *
     * @return list<array{id: int, description: string}>
     */
    public function unscanned(int $limit = 50): array
    {
        $rows = $this->db->all(
            'SELECT id, description FROM {videos}
              WHERE scripture_scanned_at IS NULL AND deleted_at IS NULL
              ORDER BY id
              LIMIT ' . max(1, min($limit, 200))
        );

        return array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'description' => (string) ($row['description'] ?? ''),
        ], $rows);
    }

    /**
     * Forget every scan, so the whole library is read again.
     *
     * The button an admin wants after correcting the parser or importing a
     * batch of descriptions. Only the parsed references go; manual ones are
     * somebody's decision and are not the parser's to withdraw.
     */
    public function rescanEverything(): void
    {
        $this->db->transaction(function (): void {
            $this->db->execute('DELETE FROM {scripture_refs} WHERE source = \'parsed\'');
            $this->db->execute('UPDATE {videos} SET scripture_scanned_at = NULL');
        });
    }

    // -------------------------------------------------------------- internals

    /** Published, not hidden, not scheduled, not expired, not deleted. */
    private function visible(): string
    {
        return 'v.deleted_at IS NULL
                AND v.is_published = 1
                AND v.hidden = 0
                AND (v.published_at IS NULL OR v.published_at <= NOW())
                AND (v.unpublish_at IS NULL OR v.unpublish_at > NOW())';
    }

    /**
     * ORDER BY that puts books in the order of the canon.
     *
     * The slug list is generated from ScriptureBooks, so the ordering can never
     * drift from the book list — a hand-written CASE would be a second copy of
     * the canon that somebody eventually updates only one of.
     */
    private function canonicalOrder(string $column = 'book'): string
    {
        $slugs = array_keys(ScriptureBooks::all());

        // Safe to interpolate: these are slugs from a constant in this
        // codebase, not user input, and there is no way to bind a list to
        // FIELD().
        return sprintf(
            'FIELD(%s, %s)',
            $column,
            implode(',', array_map(static fn (string $s): string => "'" . $s . "'", $slugs))
        );
    }

    /**
     * A derived table of chapter numbers 1..n.
     *
     * MySQL has no generate_series, and joining against one lets a single query
     * report which chapters of a book have content — including the chapters in
     * the middle of a range, which a GROUP BY on the stored chapter would miss
     * entirely.
     */
    private function chapterNumbers(int $count): string
    {
        $count = max(1, min($count, 150));

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = 'SELECT ' . $i . ' AS chapter';
        }

        return implode(' UNION ALL ', $rows);
    }
}
