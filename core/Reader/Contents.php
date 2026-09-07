<?php

declare(strict_types=1);

namespace Portal\Reader;

/**
 * A book's contents, and stepping through it.
 *
 * # BACK AND NEXT STEP BY ENTRY, NOT BY PAGE
 *
 * A hymn is not a page. Some run to three pages and some sit two to a page, so
 * "next" that adds one to the page number lands in the middle of the hymn
 * somebody is already looking at, or skips one entirely. Both are the kind of
 * wrong that makes a congregation stop using the reader on a Sunday.
 *
 * Pure: a list of entries in, an answer out. The reader page and the present
 * screen both step through this, so they cannot disagree about what comes next.
 */
final class Contents
{
    /**
     * Put entries in reading order.
     *
     * By PAGE, not by number. A hymnal's numbers usually ascend with its pages,
     * but an appendix, a second section of choruses, or a supplement bound in
     * at the back all break that — and sorting by number would then make "next"
     * jump backwards through the book.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public static function inOrder(array $entries): array
    {
        usort($entries, static function (array $a, array $b): int {
            return [(int) $a['pdf_page'], (int) ($a['number'] ?? 0), (string) $a['title']]
               <=> [(int) $b['pdf_page'], (int) ($b['number'] ?? 0), (string) $b['title']];
        });

        return array_values($entries);
    }

    /**
     * The entry somebody is inside.
     *
     * The last entry that starts at or before this page — not the nearest one,
     * which would claim a page for the hymn on the page after it.
     *
     * @param list<array<string, mixed>> $entries in reading order
     * @return array<string, mixed>|null
     */
    public static function at(array $entries, int $pdfPage): ?array
    {
        $found = null;

        foreach ($entries as $entry) {
            if ((int) $entry['pdf_page'] > $pdfPage) {
                break;
            }

            $found = $entry;
        }

        return $found;
    }

    /**
     * What comes after the entry containing this page.
     *
     * From the PAGE rather than from an entry index, so it is right when
     * somebody has scrolled a few pages into a long reading and then pressed
     * next — they mean the next hymn, not the one after the one they started in.
     *
     * @param list<array<string, mixed>> $entries in reading order
     * @return array<string, mixed>|null
     */
    public static function next(array $entries, int $pdfPage): ?array
    {
        foreach ($entries as $entry) {
            if ((int) $entry['pdf_page'] > $pdfPage) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * And before.
     *
     * The entry before the one containing this page — so pressing back from
     * the middle of hymn 12 gives hymn 11, not hymn 12 again. Pressing back
     * from the FIRST page of hymn 12 does the same thing, which is what
     * somebody who has just pressed next and changed their mind expects.
     *
     * @param list<array<string, mixed>> $entries in reading order
     * @return array<string, mixed>|null
     */
    public static function previous(array $entries, int $pdfPage): ?array
    {
        $inside = self::at($entries, $pdfPage);

        if ($inside === null) {
            return null;
        }

        /*
         * The entry before the one CONTAINING this page, found by walking to
         * that entry rather than by taking the last one before the page.
         *
         * The difference shows on page two of a three-page hymn: "the last
         * entry starting before page 8" is hymn 4 itself, so back would land
         * at the top of the hymn already on screen. A congregation pressing a
         * button labelled by hymn means the hymn before, and a button that
         * appears to do nothing reads as broken.
         */
        $before = null;

        foreach ($entries as $entry) {
            if ($entry === $inside) {
                break;
            }

            $before = $entry;
        }

        return $before;
    }

    /**
     * Find a numbered entry.
     *
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    public static function byNumber(array $entries, int $number): ?array
    {
        foreach ($entries as $entry) {
            if ((int) ($entry['number'] ?? 0) === $number) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Turn a reference into a page.
     *
     * The one place that decides, so a link, the "go to hymn" box and the
     * present screen all land in the same spot. A number nothing answers to
     * falls back to the first page rather than erroring — somebody typing 999
     * into a hymnal of 600 has made a typo, not broken anything.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function resolve(array $entries, string $reference, int $offset): int
    {
        $parsed = Locator::parse($reference);

        if ($parsed['kind'] === Locator::PAGE) {
            return $parsed['value'];
        }

        $entry = self::byNumber($entries, $parsed['value']);

        if ($entry !== null) {
            return (int) $entry['pdf_page'];
        }

        /*
         * No entry with that number. In a book with printed page numbers and no
         * contents at all, "n214" is somebody asking for printed page 214, and
         * the offset is exactly what turns that into a file page. In a hymnal
         * it will land somewhere unhelpful, which is the honest outcome of
         * asking for a hymn that is not there.
         */
        return Locator::pdfPage($parsed['value'], $offset);
    }
}
