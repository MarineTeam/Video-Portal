<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\ChapterParser;

/**
 * Reading a pasted chapter list.
 *
 * The format is the one people already have — a timestamp and a title per
 * line, as pasted into a YouTube description. So most of these tests are about
 * accepting the shapes real lists come in, and two are about the shapes that
 * must be refused because accepting them moves a marker somewhere nobody
 * chose.
 */
final class ChapterParserTest extends TestCase
{
    // ---------------------------------------------------------------- shapes

    public function testThePlainConventionParses(): void
    {
        $chapters = ChapterParser::parse(<<<TXT
        0:00 Welcome
        2:15 The reading
        14:30 Questions
        TXT);

        self::assertCount(3, $chapters);
        self::assertSame(0, $chapters[0]['start']);
        self::assertSame('Welcome', $chapters[0]['title']);
        self::assertSame(135, $chapters[1]['start']);
        self::assertSame(870, $chapters[2]['start']);
    }

    public function testHoursWork(): void
    {
        $chapters = ChapterParser::parse("1:02:03 Late in the recording");

        self::assertSame(3723, $chapters[0]['start']);
    }

    /** Every one of these turns up in a list somebody pasted. */
    public function testSeparatorsAndBracketsAreTolerated(): void
    {
        foreach ([
            '2:15 The reading',
            '2:15 - The reading',
            '2:15 – The reading',
            '2:15 — The reading',
            '2:15 : The reading',
            '[2:15] The reading',
            '(2:15) The reading',
        ] as $line) {
            $chapters = ChapterParser::parse($line);

            self::assertCount(1, $chapters, "Failed on: {$line}");
            self::assertSame(135, $chapters[0]['start'], "Failed on: {$line}");
            self::assertSame('The reading', $chapters[0]['title'], "Failed on: {$line}");
        }
    }

    public function testWindowsLineEndingsWork(): void
    {
        self::assertCount(2, ChapterParser::parse("0:00 Welcome\r\n2:15 The reading"));
    }

    public function testLinesComeBackInTimeOrder(): void
    {
        $chapters = ChapterParser::parse("14:30 Questions\n0:00 Welcome\n2:15 The reading");

        self::assertSame(['Welcome', 'The reading', 'Questions'], array_column($chapters, 'title'));
    }

    // -------------------------------------------------------------- refusals

    /**
     * A title containing a time must not become a marker. Anchoring the
     * timestamp to the start of the line is what prevents it.
     */
    public function testATimeInsideATitleIsNotAMarker(): void
    {
        $chapters = ChapterParser::parse("0:00 Reading from Psalm 1:1\n2:15 Second thing");

        self::assertCount(2, $chapters);
        self::assertSame('Reading from Psalm 1:1', $chapters[0]['title']);
    }

    /**
     * The case the anchor actually exists for.
     *
     * A line with no marker in front but a scripture reference inside it must
     * not become a chapter. Without the anchor the regex finds "1:1" partway
     * along, and the list gains a marker at 61 seconds titled "and grace" —
     * which nobody wrote and nothing on screen would explain.
     */
    public function testALineWithNoLeadingTimestampIsNeverAMarker(): void
    {
        $chapters = ChapterParser::parse("Some notes on Psalm 1:1 and grace\n2:15 The reading");

        self::assertCount(1, $chapters);
        self::assertSame('The reading', $chapters[0]['title']);
        self::assertSame(135, $chapters[0]['start']);
    }

    /**
     * "1:75" is a typo. Normalising it would silently move the marker to a
     * moment nobody chose, and the list would still look right.
     */
    public function testAnImpossibleTimeIsRefused(): void
    {
        self::assertSame([], ChapterParser::parse('1:75 Nonsense'));
        self::assertSame([], ChapterParser::parse('0:60:00 Nonsense'));
    }

    /** A heading or a blank line must not cost somebody their whole list. */
    public function testLinesWithoutATimestampAreSkipped(): void
    {
        $chapters = ChapterParser::parse(<<<TXT
        Chapters:

        0:00 Welcome

        2:15 The reading
        TXT);

        self::assertCount(2, $chapters);
    }

    public function testATimestampWithNoTitleIsSkipped(): void
    {
        $chapters = ChapterParser::parse("0:00\n2:15 The reading");

        self::assertCount(1, $chapters);
        self::assertSame('The reading', $chapters[0]['title']);
    }

    public function testNothingParsesToNothing(): void
    {
        self::assertSame([], ChapterParser::parse(''));
        self::assertSame([], ChapterParser::parse("  \n\n "));
        self::assertSame([], ChapterParser::parse("Just some prose.\nAnd more of it."));
    }

    /** A paste that went in twice would otherwise render two identical links. */
    public function testADuplicatedTimestampIsKeptOnce(): void
    {
        $chapters = ChapterParser::parse("0:00 Welcome\n0:00 Welcome again");

        self::assertCount(1, $chapters);
        self::assertSame('Welcome', $chapters[0]['title']);
    }

    public function testTheCountIsCapped(): void
    {
        $lines = '';
        for ($i = 0; $i < ChapterParser::MAX_CHAPTERS + 20; $i++) {
            $lines .= sprintf("%d:%02d Chapter %d\n", intdiv($i, 60), $i % 60, $i);
        }

        self::assertCount(ChapterParser::MAX_CHAPTERS, ChapterParser::parse($lines));
    }

    public function testALongTitleIsTruncated(): void
    {
        $chapters = ChapterParser::parse('0:00 ' . str_repeat('word ', 200));

        self::assertLessThanOrEqual(ChapterParser::MAX_TITLE_LENGTH, mb_strlen($chapters[0]['title']));
    }

    // --------------------------------------------------------------- output

    /**
     * The edit screen shows what is stored in the shape somebody typed it, so
     * changing one title does not mean rebuilding the list.
     */
    public function testChaptersRoundTripThroughText(): void
    {
        $original = ChapterParser::parse("0:00 Welcome\n2:15 The reading\n1:02:03 Questions");

        self::assertSame($original, ChapterParser::parse(ChapterParser::toText($original)));
    }

    public function testHoursAreOnlyWrittenWhenThereAreSome(): void
    {
        $text = ChapterParser::toText(ChapterParser::parse("0:00 Welcome\n1:02:03 Late"));

        self::assertStringContainsString('0:00 Welcome', $text);
        self::assertStringContainsString('1:02:03 Late', $text);
    }
}
