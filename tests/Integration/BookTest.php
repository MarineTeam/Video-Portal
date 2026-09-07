<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Reader\BookRepository;
use Portal\Reader\Contents;
use Portal\Reader\Locator;

/**
 * What a book remembers, and what survives a re-scan.
 *
 * Against a real database because the rules are about rows: correcting an
 * offset must touch NOTHING but one column, replacing a file must keep every
 * mark, and a duplicate hymn number is a UNIQUE key rather than a hope.
 */
final class BookTest extends DatabaseTestCase
{
    private BookRepository $books;
    private int $bookId;

    protected function setUp(): void
    {
        $this->truncate([
            'hymn_lookups', 'book_marks', 'reading_positions', 'book_pages',
            'book_contents', 'books', 'users',
        ]);

        $this->books = new BookRepository($this->db());
        $this->bookId = $this->books->create('Hymns Ancient and Modern');

        // Three leaves of front matter: a cover, a title page and a blank.
        $this->books->update($this->bookId, ['page_offset' => 3, 'page_count' => 400]);
    }

    private function reader(): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('users', [
            'email'      => 'reader-' . bin2hex(random_bytes(4)) . '@example.test',
            'name'       => 'A reader',
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ------------------------------ correcting the offset costs one column

    /**
     * THE RULE. The offset was wrong by two — somebody scanned a cover and a
     * blank leaf and nobody counted. Correcting it relabels the whole book,
     * and NOTHING ELSE IS WRITTEN.
     */
    public function testCorrectingTheOffsetRelabelsEverythingAndRewritesNothing(): void
    {
        $userId = $this->reader();

        $this->books->addEntry($this->bookId, 'Abide with me', 30, 27);
        $this->books->savePosition($this->bookId, $userId, 30, 8);
        $this->books->addMark($this->bookId, $userId, ['kind' => 'bookmark', 'pdf_page' => 30]);

        $before = $this->db()->all(
            'SELECT pdf_page FROM {book_contents}
              UNION ALL SELECT pdf_page FROM {reading_positions}
              UNION ALL SELECT pdf_page FROM {book_marks}'
        );

        // Printed page 27 with three leaves of front matter.
        self::assertSame(27, Locator::printedNumber(30, 3));

        $this->books->update($this->bookId, ['page_offset' => 5]);

        $after = $this->db()->all(
            'SELECT pdf_page FROM {book_contents}
              UNION ALL SELECT pdf_page FROM {reading_positions}
              UNION ALL SELECT pdf_page FROM {book_marks}'
        );

        self::assertSame($before, $after, 'CORRECTING THE OFFSET REWROTE STORED POSITIONS');

        // And everything now reads two lower, with no row having moved.
        self::assertSame(25, Locator::printedNumber(30, 5));
    }

    // ------------------------------------------------------ replacing a file

    /**
     * A file can be replaced WITHOUT the book becoming a different book.
     *
     * A re-scan is a better copy of the same object. Making it a new row would
     * throw away every bookmark anybody had made in it.
     */
    public function testReplacingTheFileKeepsTheMarksAndTheContents(): void
    {
        $userId = $this->reader();
        $this->books->addEntry($this->bookId, 'Abide with me', 30, 27);
        $this->books->addMark($this->bookId, $userId, ['kind' => 'note', 'pdf_page' => 30, 'body' => 'Sung at the funeral']);

        $assetId = $this->asset();
        $this->books->replaceFile($this->bookId, $assetId, 402);

        self::assertCount(1, $this->books->contents($this->bookId));
        self::assertCount(1, $this->books->marks($this->bookId, $userId));
        self::assertSame(402, (int) ((array) $this->books->find($this->bookId))['page_count']);
    }

    /**
     * And the revision goes up, so a device holding a saved copy knows it is
     * stale. Without it a re-scanned book keeps serving old pages to everybody
     * who ever opened it, with the numbering silently one out.
     */
    public function testReplacingTheFileMakesSavedCopiesStale(): void
    {
        $before = (int) ((array) $this->books->find($this->bookId))['file_revision'];

        $this->books->replaceFile($this->bookId, $this->asset());

        self::assertSame(
            $before + 1,
            (int) ((array) $this->books->find($this->bookId))['file_revision'],
            'a saved copy could never find out it was out of date'
        );
    }

    /**
     * The extracted text IS cleared, unlike the marks.
     *
     * Page 40 of the new file is not page 40 of the old one, so keeping it
     * would leave search confidently pointing at the wrong pages — which is
     * worse than search finding nothing.
     */
    public function testReplacingTheFileClearsTheIndex(): void
    {
        $this->books->storePages($this->bookId, [40 => ['body' => 'Abide with me, fast falls the eventide']]);
        $this->books->markIndexed($this->bookId);

        $this->books->replaceFile($this->bookId, $this->asset());

        self::assertSame(0, $this->books->indexedPages($this->bookId));
        self::assertNull(
            ((array) $this->books->find($this->bookId))['indexed_at'],
            'the book still claims to be indexed'
        );
    }

    // ----------------------------------------------------------- contents

    public function testTheSameHymnNumberTwiceIsRefusedByTheDatabase(): void
    {
        $this->books->addEntry($this->bookId, 'Abide with me', 30, 27);

        $this->expectException(\Throwable::class);

        $this->books->addEntry($this->bookId, 'Something else', 44, 27);
    }

    /**
     * But a scan that reads a number twice does not lose the whole index.
     *
     * Losing it would mean the book could never be indexed at all; dropping
     * the duplicate leaves everything else searchable and one entry to fix by
     * hand.
     */
    public function testAnIndexWithADuplicateNumberStillLands(): void
    {
        $written = $this->books->replaceContents($this->bookId, [
            ['title' => 'Abide with me', 'pdf_page' => 30, 'number' => 27],
            ['title' => 'A misread line', 'pdf_page' => 31, 'number' => 27],
            ['title' => 'All people', 'pdf_page' => 32, 'number' => 28],
        ]);

        self::assertSame(3, $written, 'the duplicate lost the rest of the index');

        $contents = $this->books->contents($this->bookId);

        self::assertSame(27, (int) Contents::byNumber($contents, 27)['number']);
        self::assertSame(28, (int) Contents::byNumber($contents, 28)['number']);
        self::assertNull($contents[1]['number'], 'the duplicate kept its number');
    }

    /** Re-indexing replaces rather than accumulating. */
    public function testIndexingTwiceDoesNotDoubleTheContents(): void
    {
        $entries = [['title' => 'Abide with me', 'pdf_page' => 30, 'number' => 27]];

        $this->books->replaceContents($this->bookId, $entries);
        $this->books->replaceContents($this->bookId, $entries);

        self::assertCount(1, $this->books->contents($this->bookId));
    }

    // ------------------------------------------------------------ searching

    public function testTheTextOfAPageCanBeSearched(): void
    {
        $this->books->storePages($this->bookId, [
            30 => ['body' => 'Abide with me fast falls the eventide', 'source' => 'text'],
            31 => ['body' => 'All people that on earth do dwell', 'source' => 'ocr'],
        ]);

        $hits = $this->books->search([$this->bookId], 'eventide');

        self::assertCount(1, $hits);
        self::assertSame(30, (int) $hits[0]['pdf_page']);
    }

    /**
     * Whether the text came from a text layer or from OCR is kept, because OCR
     * text is good enough to search and not good enough to quote.
     */
    public function testWhereTheTextCameFromIsRemembered(): void
    {
        $this->books->storePages($this->bookId, [31 => ['body' => 'All people that on earth do dwell', 'source' => 'ocr']]);

        self::assertSame('ocr', (string) $this->books->search([$this->bookId], 'dwell')[0]['source']);
    }

    /** A blank page stores no row, so a later run does not think it is done. */
    public function testABlankPageIsNotStored(): void
    {
        self::assertSame(0, $this->books->storePages($this->bookId, [5 => ['body' => '   ']]));
        self::assertSame(0, $this->books->indexedPages($this->bookId));
    }

    // ------------------------------------------------------------ the marks

    /**
     * Ownership is in the WHERE clause. Ids are sequential, so a delete taking
     * only an id would let anybody destroy a stranger's notes by counting.
     */
    public function testSomebodyCannotDeleteAStrangersNote(): void
    {
        $mine = $this->reader();
        $theirs = $this->reader();

        $markId = $this->books->addMark($this->bookId, $mine, ['kind' => 'note', 'pdf_page' => 30, 'body' => 'Mine']);

        $this->books->removeMark($markId, $theirs);

        self::assertCount(1, $this->books->marks($this->bookId, $mine), 'A STRANGER DELETED SOMEBODY\'S NOTE');

        $this->books->removeMark($markId, $mine);

        self::assertSame([], $this->books->marks($this->bookId, $mine));
    }

    // --------------------------------------------------------- the counting

    /**
     * Lookups are counted, never logged per person — a row per lookup would be
     * a record of what each individual searched for in a hymnal.
     */
    public function testLookupsAreCountedRatherThanLogged(): void
    {
        $this->books->addEntry($this->bookId, 'Abide with me', 30, 27);

        foreach ([27, 27, 27, 28] as $number) {
            $this->books->countLookup($this->bookId, $number);
        }

        self::assertSame(
            2,
            (int) $this->db()->value('SELECT COUNT(*) FROM {hymn_lookups}'),
            'a row was written per lookup rather than per hymn per day'
        );

        $top = $this->books->mostLookedUp();

        self::assertSame(27, (int) $top[0]['number']);
        self::assertSame(3, (int) $top[0]['lookups']);
        self::assertSame('Abide with me', (string) $top[0]['hymn_title']);
    }

    // ------------------------------------------------------------ the shelf

    /** A members-only book is absent from a stranger's shelf, not refused. */
    public function testAMembersOnlyBookIsAbsentRatherThanRefused(): void
    {
        $this->books->update($this->bookId, [
            '_whole_form' => true,
            'is_published' => true,
            'member_only' => true,
        ]);

        self::assertSame([], $this->books->shelf(false));
        self::assertCount(1, $this->books->shelf(true));
    }

    public function testAnUnpublishedBookIsNotOnTheShelf(): void
    {
        self::assertSame([], $this->books->shelf(true));
        self::assertCount(1, $this->books->shelf(true, null, true), 'an admin cannot see the draft');
    }

    private function asset(): int
    {
        return (int) $this->db()->insert('file_assets', [
            'path'          => 'books/' . bin2hex(random_bytes(4)) . '.pdf',
            'original_name' => 'hymnal.pdf',
            'content_type'  => 'application/pdf',
            'size_bytes'    => 1024,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}
