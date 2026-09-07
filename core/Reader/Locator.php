<?php

declare(strict_types=1);

namespace Portal\Reader;

/**
 * Where you are in a book, and how to say so.
 *
 * # EVERY STORED POSITION IS A PDF PAGE
 *
 * The printed number is worked out for display and never written down. That is
 * the rule this class exists for, and the reason is what happens when a book's
 * offset turns out to be wrong by two — which it will, because somebody scanned
 * a cover and a blank leaf and nobody counted.
 *
 * With the PDF page stored, correcting the offset RELABELS EVERYTHING at once:
 * every bookmark, every highlight, every contents entry, every saved position,
 * with nothing rewritten. Store the printed number instead and the same
 * correction is a migration over four tables, run against live data, that has
 * to be right first time.
 *
 * # A SHARE LINK GOES BY NUMBER WHERE THERE IS ONE
 *
 * Same reasoning pointing outward. "Page 214" means nothing to somebody holding
 * a different edition, and stops meaning anything at all when the book is
 * re-scanned with the front matter fixed. "Hymn 214" survives both, because it
 * is a fact about the book rather than about this copy of the file.
 *
 * Only an unnumbered spot — front matter, an unindexed page — falls back to its
 * page, and that reference is honestly fragile rather than pretending not to be.
 */
final class Locator
{
    /** A hymn or an entry number: the durable way to point at a place. */
    public const NUMBER = 'n';

    /** A raw PDF page: the fallback, and it means nothing in another edition. */
    public const PAGE = 'p';

    /**
     * What is printed on this page, or null if nothing is.
     *
     * The offset is how many leaves come before printed page 1 — a cover, a
     * title page, a blank. Pages inside that run have no printed number at all,
     * and saying "page 0" or "page -1" would be inventing one.
     */
    public static function printedNumber(int $pdfPage, int $offset): ?int
    {
        $printed = $pdfPage - $offset;

        return $printed >= 1 ? $printed : null;
    }

    /**
     * Which PDF page carries a printed number.
     *
     * A number below 1 is not a page anybody printed — a typo in the go-to box
     * or a crafted URL. It is read as printed page 1, so the answer is the
     * first NUMBERED page rather than the first page of the file: somebody
     * asking for a page wants the body of the book, not the cover.
     */
    public static function pdfPage(int $printedNumber, int $offset): int
    {
        return max(1, max(1, $printedNumber) + $offset);
    }

    /**
     * How to write down where somebody is, for a link.
     *
     * @param int|null $number the contents entry's number, when the spot has one
     */
    public static function reference(int $pdfPage, ?int $number): string
    {
        return $number !== null && $number > 0
            ? self::NUMBER . $number
            : self::PAGE . max(1, $pdfPage);
    }

    /**
     * Read one back.
     *
     * Anything unreadable is the first page rather than an error: a truncated
     * or mistyped link should open the book, not refuse to.
     *
     * @return array{kind: string, value: int}
     */
    public static function parse(string $reference): array
    {
        $reference = trim($reference);

        if (preg_match('/^([np])(\d{1,6})$/i', $reference, $m) !== 1) {
            return ['kind' => self::PAGE, 'value' => 1];
        }

        return [
            'kind'  => strtolower($m[1]) === self::NUMBER ? self::NUMBER : self::PAGE,
            'value' => max(1, (int) $m[2]),
        ];
    }

    /**
     * How far through the book somebody is, as a percentage.
     *
     * Stored alongside the opaque position rather than derived from it on
     * every render, because an EPUB's position is a string only its renderer
     * understands — there is nothing here that could work a percentage out of
     * one, and a progress bar that only works for PDFs is worse than none.
     */
    public static function percent(int $pdfPage, int $pageCount): int
    {
        if ($pageCount < 1) {
            return 0;
        }

        return max(0, min(100, (int) round($pdfPage / $pageCount * 100)));
    }
}
