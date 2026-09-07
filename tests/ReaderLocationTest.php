<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Reader\Contents;
use Portal\Reader\Locator;

/**
 * Where you are in a book, and how to get to the next hymn.
 *
 * Two rules carry this section. Every stored position is a PDF PAGE, so
 * correcting a book's offset relabels everything without rewriting anything.
 * And back/next step by ENTRY, because a hymn is not a page.
 */
final class ReaderLocationTest extends TestCase
{
    /**
     * A hymnal with a cover, a title page and a blank before printed page 1,
     * and hymns that are not one to a page.
     *
     * @return list<array<string, mixed>>
     */
    private function hymnal(): array
    {
        return Contents::inOrder([
            ['number' => 1, 'title' => 'All people that on earth do dwell', 'pdf_page' => 4],
            ['number' => 2, 'title' => 'Amazing grace', 'pdf_page' => 4],
            ['number' => 3, 'title' => 'And can it be', 'pdf_page' => 5],
            // Three pages long.
            ['number' => 4, 'title' => 'Be thou my vision', 'pdf_page' => 7],
            ['number' => 5, 'title' => 'Crown him', 'pdf_page' => 10],
        ]);
    }

    // -------------------------------------------- the page is what is stored

    /**
     * THE RULE. The printed number is worked out, never written down — so an
     * offset that turns out to be wrong by two is one column edit rather than
     * a migration over every bookmark, highlight and saved position.
     */
    public function testThePrintedNumberIsDerivedFromThePage(): void
    {
        self::assertSame(1, Locator::printedNumber(4, 3));
        self::assertSame(37, Locator::printedNumber(40, 3));

        // And correcting the offset relabels everything at once, with no
        // stored value having changed.
        self::assertSame(38, Locator::printedNumber(40, 2));
    }

    /** Front matter has no printed number, and saying "page 0" would invent one. */
    public function testFrontMatterHasNoPrintedNumber(): void
    {
        self::assertNull(Locator::printedNumber(1, 3));
        self::assertNull(Locator::printedNumber(3, 3));
        self::assertSame(1, Locator::printedNumber(4, 3));
    }

    public function testAPrintedNumberComesBackToItsPage(): void
    {
        foreach ([1, 37, 214] as $printed) {
            self::assertSame(
                $printed,
                Locator::printedNumber(Locator::pdfPage($printed, 3), 3),
                (string) $printed
            );
        }
    }

    /**
     * A mistyped page 0 lands on the first NUMBERED page rather than erroring
     * — somebody asking for a page wants the body of the book, not the cover.
     */
    public function testAnImpossiblePageIsTheFirstNumberedOne(): void
    {
        self::assertSame(4, Locator::pdfPage(0, 3));
        self::assertSame(4, Locator::pdfPage(-99, 3));
        self::assertSame(1, Locator::pdfPage(0, 0), 'a book with no front matter starts at page 1');
    }

    // ------------------------------------------------------- share links

    /**
     * THE RULE, pointing outward. "Hymn 214" survives a re-scan and a different
     * edition; "page 214" survives neither.
     */
    public function testAShareLinkUsesTheNumberWhereThereIsOne(): void
    {
        self::assertSame('n214', Locator::reference(230, 214));
    }

    /** And falls back to the page only where there is no number at all. */
    public function testAnUnnumberedSpotFallsBackToItsPage(): void
    {
        self::assertSame('p2', Locator::reference(2, null));
        self::assertSame('p2', Locator::reference(2, 0));
    }

    public function testAReferenceReadsBack(): void
    {
        self::assertSame(['kind' => Locator::NUMBER, 'value' => 214], Locator::parse('n214'));
        self::assertSame(['kind' => Locator::PAGE, 'value' => 12], Locator::parse('p12'));
        self::assertSame(['kind' => Locator::NUMBER, 'value' => 7], Locator::parse('N7'));
    }

    /** A truncated or mistyped link opens the book rather than refusing to. */
    public function testSomethingUnreadableIsTheFirstPage(): void
    {
        foreach (['', 'x9', 'n', 'nonsense', 'p-4'] as $broken) {
            self::assertSame(
                ['kind' => Locator::PAGE, 'value' => 1],
                Locator::parse($broken),
                $broken
            );
        }
    }

    // ------------------------------------------------ stepping by entry

    /**
     * THE OTHER RULE. Next means the next hymn, not the next page — two hymns
     * on one page and a hymn spanning three both break page arithmetic.
     */
    public function testNextStepsByEntryAndNotByPage(): void
    {
        $hymnal = $this->hymnal();

        // Two hymns share page 4, so next from there is the one on page 5.
        self::assertSame(3, (int) Contents::next($hymnal, 4)['number']);

        // Hymn 4 runs pages 7 to 9. Next from the middle of it is hymn 5.
        self::assertSame(5, (int) Contents::next($hymnal, 8)['number']);
    }

    /**
     * And back from the middle of a long hymn is the hymn BEFORE it, not the
     * one you are already looking at.
     */
    public function testBackFromInsideALongEntryIsThePreviousOne(): void
    {
        $hymnal = $this->hymnal();

        self::assertSame(3, (int) Contents::previous($hymnal, 8)['number']);

        // From the first page of hymn 4, back is still hymn 3 — which is what
        // somebody who pressed next and changed their mind expects.
        self::assertSame(3, (int) Contents::previous($hymnal, 7)['number']);
    }

    public function testThereIsNothingAfterTheLastEntry(): void
    {
        self::assertNull(Contents::next($this->hymnal(), 10));
    }

    /**
     * And nothing before the first — but the page that is "first" is not
     * page 4, because two hymns share it.
     *
     * Where several entries start on one page, the page alone cannot say which
     * of them you are in, and at() takes the last. So on page 4 you are in
     * hymn 2 and back gives hymn 1, which is the useful answer; there is
     * nothing before only in the front matter, where you are inside no entry
     * at all.
     */
    public function testThereIsNothingBeforeAnyEntryInTheFrontMatter(): void
    {
        $hymnal = $this->hymnal();

        self::assertNull(Contents::previous($hymnal, 2));
        self::assertSame(1, (int) Contents::previous($hymnal, 4)['number']);
    }

    /**
     * Which entry a page belongs to is the last one that STARTS at or before
     * it — the nearest would claim a page for the hymn printed after it.
     */
    public function testAPageBelongsToTheEntryItIsInside(): void
    {
        $hymnal = $this->hymnal();

        self::assertSame(4, (int) Contents::at($hymnal, 8)['number'], 'a page mid-hymn');
        self::assertSame(4, (int) Contents::at($hymnal, 9)['number'], 'the last page of a hymn');
        self::assertSame(5, (int) Contents::at($hymnal, 10)['number']);
        self::assertNull(Contents::at($hymnal, 2), 'front matter is inside nothing');
    }

    /**
     * Entries are ordered by PAGE, not by number.
     *
     * A supplement bound in at the back numbers its choruses from 1 again, and
     * ordering by number would make "next" jump backwards through the book.
     */
    public function testEntriesAreOrderedByPageRatherThanByNumber(): void
    {
        $withSupplement = Contents::inOrder([
            ['number' => 500, 'title' => 'Zion', 'pdf_page' => 40],
            ['number' => 1, 'title' => 'Chorus one', 'pdf_page' => 90],
            ['number' => 2, 'title' => 'Chorus two', 'pdf_page' => 91],
        ]);

        self::assertSame([500, 1, 2], array_map(
            static fn (array $e): int => (int) $e['number'],
            $withSupplement
        ));

        self::assertSame(1, (int) Contents::next($withSupplement, 40)['number'], 'next went backwards');
    }

    // ------------------------------------------------------- resolving

    public function testGoingToAHymnLandsOnIt(): void
    {
        self::assertSame(7, Contents::resolve($this->hymnal(), 'n4', 3));
    }

    public function testAPageReferenceIsTakenAsWritten(): void
    {
        self::assertSame(12, Contents::resolve($this->hymnal(), 'p12', 3));
    }

    /**
     * A book with printed page numbers and no contents at all: "n214" is
     * somebody asking for printed page 214, and the offset is what turns that
     * into a file page.
     */
    public function testInABookWithNoContentsANumberIsAPrintedPage(): void
    {
        self::assertSame(217, Contents::resolve([], 'n214', 3));
    }

    // --------------------------------------------------------- progress

    public function testHowFarThroughABookSomebodyIs(): void
    {
        self::assertSame(50, Locator::percent(50, 100));
        self::assertSame(100, Locator::percent(100, 100));
        self::assertSame(0, Locator::percent(5, 0), 'a book with no pages is not divided by');
    }
}
