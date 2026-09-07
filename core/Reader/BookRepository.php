<?php

declare(strict_types=1);

namespace Portal\Reader;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Books, what is in them, and where people had got to.
 *
 * The one rule everything here keeps: A PAGE IS WHAT IS STORED. Nothing writes
 * a printed number to any column, so correcting a book's offset relabels the
 * whole thing at once — see Locator for why that is worth arranging.
 */
final class BookRepository
{
    public const PDF  = 'pdf';
    public const EPUB = 'epub';

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------- books

    /**
     * The shelf, as somebody sees it.
     *
     * A members-only book is absent rather than refused, the rule events,
     * forms and small groups all keep — the title is a leak too.
     *
     * @return list<array<string, mixed>>
     */
    public function shelf(bool $includeMemberOnly, ?int $categoryId = null, bool $includeDrafts = false): array
    {
        $where = [];
        $params = [];

        if (!$includeDrafts) {
            $where[] = 'is_published = 1';
        }

        if (!$includeMemberOnly) {
            $where[] = 'member_only = 0';
        }

        if ($categoryId !== null) {
            $where[] = 'category_id = ?';
            $params[] = $categoryId;
        }

        return $this->db->all(
            'SELECT * FROM {books}'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY position, title',
            $params
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {books} WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM {books} WHERE slug = ?', [$slug]);
    }

    public function create(string $title, string $kind = self::PDF): int
    {
        $title = trim($title);

        if ($title === '') {
            throw HttpException::badRequest('A book needs a title.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('books', [
            'slug'       => $this->uniqueSlug($title),
            'title'      => mb_substr($title, 0, 300),
            'kind'       => $kind === self::EPUB ? self::EPUB : self::PDF,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(int $id, array $attributes): void
    {
        $sets = [];
        $params = [];

        // Absent means LEAVE ALONE. A handler that reads absence as a value is
        // one that will eventually destroy something it was never asked about.
        foreach (['title' => 300, 'subtitle' => 300, 'author' => 190] as $field => $limit) {
            if (array_key_exists($field, $attributes)) {
                $sets[] = "{$field} = ?";
                $value = mb_substr(trim((string) $attributes[$field]), 0, $limit);
                $params[] = $field === 'title' ? $value : ($value === '' ? null : $value);
            }
        }

        foreach (['page_count', 'page_offset', 'category_id', 'position'] as $number) {
            if (array_key_exists($number, $attributes)) {
                $sets[] = "{$number} = ?";
                $value = (int) $attributes[$number];
                $params[] = $number === 'category_id' ? ($value > 0 ? $value : null) : $value;
            }
        }

        if (!empty($attributes['_whole_form'])) {
            foreach (['is_published', 'member_only', 'is_hymnal'] as $flag) {
                $sets[] = "{$flag} = ?";
                $params[] = !empty($attributes[$flag]) ? 1 : 0;
            }
        }

        if ($sets === []) {
            return;
        }

        $params[] = $id;

        $this->db->execute(
            'UPDATE {books} SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?',
            $params
        );
    }

    /**
     * Point a book at a different file WITHOUT it becoming a different book.
     *
     * Everything else survives: the contents, the bookmarks, the reading
     * positions. That is the point — a re-scan of the same hymnal is a better
     * copy of the same object, and making it a new row would throw away every
     * mark anybody had made in it.
     *
     * The revision goes up so a device holding a saved copy knows its copy is
     * stale. Without that a re-scanned book keeps serving the old pages to
     * everybody who ever opened it, silently, with the numbering one out.
     */
    public function replaceFile(int $id, int $assetId, int $pageCount = 0): void
    {
        $this->db->execute(
            'UPDATE {books}
                SET asset_id = ?, file_revision = file_revision + 1,
                    page_count = IF(? > 0, ?, page_count),
                    /*
                     * The index is cleared, not kept. Page 40 of the new file
                     * is not page 40 of the old one, so keeping the extracted
                     * text would leave search confidently pointing at the
                     * wrong pages — worse than search finding nothing.
                     */
                    indexed_at = NULL,
                    updated_at = NOW()
              WHERE id = ?',
            [$assetId, $pageCount, $pageCount, $id]
        );

        $this->db->execute('DELETE FROM {book_pages} WHERE book_id = ?', [$id]);
    }

    // ---------------------------------------------------------- contents

    /**
     * @return list<array<string, mixed>>
     */
    public function contents(int $bookId): array
    {
        return Contents::inOrder($this->db->all(
            'SELECT * FROM {book_contents} WHERE book_id = ? ORDER BY pdf_page, number, id',
            [$bookId]
        ));
    }

    public function addEntry(int $bookId, string $title, int $pdfPage, ?int $number = null): int
    {
        $title = trim($title);

        if ($title === '') {
            throw HttpException::badRequest('An entry needs a title.');
        }

        return (int) $this->db->insert('book_contents', [
            'book_id'    => $bookId,
            'number'     => $number !== null && $number > 0 ? $number : null,
            'title'      => mb_substr($title, 0, 300),
            'pdf_page'   => max(1, $pdfPage),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function removeEntry(int $id): void
    {
        $this->db->execute('DELETE FROM {book_contents} WHERE id = ?', [$id]);
    }

    /**
     * Replace a book's contents with what a browser worked out.
     *
     * In one transaction, because a book with half its contents is worse than
     * one with none: "go to hymn 214" would answer for some numbers and
     * silently fall back to page arithmetic for the rest.
     *
     * @param list<array{title: string, pdf_page: int, number?: int|null, depth?: int}> $entries
     */
    public function replaceContents(int $bookId, array $entries): int
    {
        return $this->db->transaction(function () use ($bookId, $entries): int {
            $this->db->execute('DELETE FROM {book_contents} WHERE book_id = ?', [$bookId]);

            $now = date('Y-m-d H:i:s');
            $written = 0;
            $seen = [];

            foreach ($entries as $entry) {
                $title = trim((string) ($entry['title'] ?? ''));

                if ($title === '') {
                    continue;
                }

                $number = (int) ($entry['number'] ?? 0);

                /*
                 * A number already used in this book is dropped rather than
                 * refused. A scan that reads "214" twice is a real thing, and
                 * losing the whole index over it would mean the book could
                 * never be indexed at all — where dropping the duplicate
                 * leaves everything else searchable and somebody can fix the
                 * one entry by hand.
                 */
                if ($number > 0 && isset($seen[$number])) {
                    $number = 0;
                }

                if ($number > 0) {
                    $seen[$number] = true;
                }

                $this->db->insert('book_contents', [
                    'book_id'    => $bookId,
                    'number'     => $number > 0 ? $number : null,
                    'title'      => mb_substr($title, 0, 300),
                    'pdf_page'   => max(1, (int) ($entry['pdf_page'] ?? 1)),
                    'epub_href'  => mb_substr(trim((string) ($entry['epub_href'] ?? '')), 0, 500) ?: null,
                    'depth'      => max(0, min(9, (int) ($entry['depth'] ?? 0))),
                    'created_at' => $now,
                ]);

                $written++;
            }

            return $written;
        });
    }

    // ------------------------------------------------------- the text

    /**
     * Store what a browser read off a page.
     *
     * @param array<int, array{body: string, source?: string}> $pages keyed by pdf page
     */
    public function storePages(int $bookId, array $pages): int
    {
        $now = date('Y-m-d H:i:s');
        $written = 0;

        foreach ($pages as $pdfPage => $page) {
            $body = trim((string) ($page['body'] ?? ''));

            if ($body === '') {
                // A blank page is not an error and not worth a row — but it
                // must not be stored as empty either, or a later run would see
                // "already indexed" and never try again.
                continue;
            }

            $this->db->execute(
                'INSERT INTO {book_pages} (book_id, pdf_page, body, source, created_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE body = VALUES(body), source = VALUES(source)',
                [
                    $bookId,
                    max(1, (int) $pdfPage),
                    mb_substr($body, 0, 60000),
                    ($page['source'] ?? '') === 'ocr' ? 'ocr' : 'text',
                    $now,
                ]
            );

            $written++;
        }

        return $written;
    }

    public function markIndexed(int $bookId): void
    {
        $this->db->execute(
            'UPDATE {books} SET indexed_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$bookId]
        );
    }

    public function indexedPages(int $bookId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {book_pages} WHERE book_id = ?',
            [$bookId]
        );
    }

    /**
     * Search inside one book, or across a whole shelf.
     *
     * FULLTEXT in natural-language mode, which is the first fallback this
     * product's platform note names: MySQL has no pg_trgm, so there is no
     * trigram similarity to lean on. The admin screen says so rather than
     * implying the search is doing more than it is.
     *
     * @param list<int> $bookIds
     * @return list<array<string, mixed>>
     */
    public function search(array $bookIds, string $query, int $limit = 50): array
    {
        $query = trim($query);

        if ($query === '' || $bookIds === []) {
            return [];
        }

        $ids = array_map('intval', $bookIds);
        $marks = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->all(
            "SELECT p.book_id, p.pdf_page, p.source, b.title AS book_title, b.slug AS book_slug,
                    b.page_offset,
                    MATCH(p.body) AGAINST (? IN NATURAL LANGUAGE MODE) AS score,
                    SUBSTRING(p.body, 1, 300) AS snippet
               FROM {book_pages} p
               INNER JOIN {books} b ON b.id = p.book_id
              WHERE p.book_id IN ({$marks})
                AND MATCH(p.body) AGAINST (? IN NATURAL LANGUAGE MODE)
              ORDER BY score DESC
              LIMIT " . max(1, min(200, $limit)),
            array_merge([$query], $ids, [$query])
        );
    }

    // --------------------------------------------------- where you were

    /** @return array<string, mixed>|null */
    public function positionFor(int $bookId, int $userId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {reading_positions} WHERE book_id = ? AND user_id = ?',
            [$bookId, $userId]
        );
    }

    public function savePosition(
        int $bookId,
        int $userId,
        int $pdfPage,
        int $percent,
        string $epubCfi = ''
    ): void {
        $this->db->execute(
            'INSERT INTO {reading_positions}
                (book_id, user_id, pdf_page, epub_cfi, percent, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE pdf_page = VALUES(pdf_page), epub_cfi = VALUES(epub_cfi),
                percent = VALUES(percent), updated_at = NOW()',
            [
                $bookId,
                $userId,
                max(1, $pdfPage),
                mb_substr(trim($epubCfi), 0, 500) ?: null,
                max(0, min(100, $percent)),
            ]
        );
    }

    // --------------------------------------------------------- the marks

    /** @return list<array<string, mixed>> */
    public function marks(int $bookId, int $userId): array
    {
        return $this->db->all(
            'SELECT * FROM {book_marks} WHERE book_id = ? AND user_id = ? ORDER BY pdf_page, id',
            [$bookId, $userId]
        );
    }

    /** @param array<string, mixed> $mark */
    public function addMark(int $bookId, int $userId, array $mark): int
    {
        $now = date('Y-m-d H:i:s');
        $kind = (string) ($mark['kind'] ?? 'bookmark');

        return (int) $this->db->insert('book_marks', [
            'book_id'    => $bookId,
            'user_id'    => $userId,
            'kind'       => in_array($kind, ['bookmark', 'highlight', 'note'], true) ? $kind : 'bookmark',
            'pdf_page'   => max(1, (int) ($mark['pdf_page'] ?? 1)),
            'anchor'     => mb_substr(trim((string) ($mark['anchor'] ?? '')), 0, 1000) ?: null,
            'quote'      => mb_substr(trim((string) ($mark['quote'] ?? '')), 0, 1000) ?: null,
            'body'       => mb_substr(trim((string) ($mark['body'] ?? '')), 0, 2000) ?: null,
            'colour'     => self::colour((string) ($mark['colour'] ?? '')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Ownership is in the WHERE clause, not in the handler.
     *
     * Ids are sequential, so a delete taking only an id would let anybody
     * destroy a stranger's notes by counting. The address of the person doing
     * it goes into the statement, the same rule the notification record keeps.
     */
    public function removeMark(int $id, int $userId): void
    {
        $this->db->execute('DELETE FROM {book_marks} WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** Validated rather than escaped: it goes into a style attribute. */
    private static function colour(string $raw): ?string
    {
        $raw = trim($raw);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $raw) === 1 ? strtolower($raw) : null;
    }

    // -------------------------------------------------------- the counting

    /**
     * Somebody looked up a hymn.
     *
     * Counted per day, never logged per person. A row per lookup would be a
     * record of what each individual searched for in a hymnal, which is nobody's
     * business and grows without limit; this answers "what do we sing" and
     * nothing at all about who.
     */
    public function countLookup(int $bookId, int $number): void
    {
        if ($number <= 0) {
            return;
        }

        $this->db->execute(
            'INSERT INTO {hymn_lookups} (book_id, number, on_date, lookups)
             VALUES (?, ?, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE lookups = lookups + 1',
            [$bookId, $number]
        );
    }

    /**
     * What has been looked up lately.
     *
     * @return list<array<string, mixed>>
     */
    public function mostLookedUp(int $days = 90, int $limit = 25): array
    {
        return $this->db->all(
            'SELECT l.book_id, l.number, SUM(l.lookups) AS lookups,
                    b.title AS book_title, b.slug AS book_slug, c.title AS hymn_title
               FROM {hymn_lookups} l
               INNER JOIN {books} b ON b.id = l.book_id
               LEFT JOIN {book_contents} c ON c.book_id = l.book_id AND c.number = l.number
              WHERE l.on_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY l.book_id, l.number, b.title, b.slug, c.title
              ORDER BY lookups DESC
              LIMIT ' . max(1, min(200, $limit)),
            [max(1, $days)]
        );
    }

    // --------------------------------------------------------- internals

    private function uniqueSlug(string $desired): string
    {
        $base = Str::slug($desired) ?: 'book';
        $slug = $base;
        $suffix = 1;

        while ($this->db->value('SELECT id FROM {books} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
