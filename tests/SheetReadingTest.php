<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Schedules\SheetDate;
use Portal\Schedules\SheetLayout;

/**
 * Reading a rota out of a spreadsheet.
 *
 * The rule this exists for: an ambiguous date is REFUSED rather than guessed.
 * Everything else here is about a sheet somebody actually keeps — a title row
 * above the table, a blank week, a note with a comma in it, a job column with
 * nobody in it yet.
 */
final class SheetReadingTest extends TestCase
{
    /** A fixed "today", so year inference is tested rather than the calendar. */
    private const TODAY = '2026-09-05';

    // ----------------------------------------------------------- the dates

    /** Year first is unambiguous everywhere and needs nobody to say anything. */
    public function testAYearFirstDateNeedsNoConvention(): void
    {
        self::assertSame('2026-09-05', SheetDate::parse('2026-09-05'));
        self::assertSame('2026-09-05', SheetDate::parse('2026/9/5'));
    }

    /**
     * THE RULE. "5/9" is 5 September to most of the world and 9 May to the
     * rest, and reading it either way puts somebody on a date four months from
     * the one they agreed to. Nobody finds out until the day.
     */
    public function testAnAmbiguousDateIsRefusedRatherThanGuessed(): void
    {
        self::assertNull(SheetDate::parse('5/9/2026'), 'it guessed');
        self::assertTrue(SheetDate::isAmbiguous('5/9/2026'));

        // And is read once the sheet has said which way round it is.
        self::assertSame('2026-09-05', SheetDate::parse('5/9/2026', SheetDate::DMY));
        self::assertSame('2026-05-09', SheetDate::parse('5/9/2026', SheetDate::MDY));
    }

    /**
     * There is no thirteenth month, so this one is not ambiguous and must not
     * be refused — refusing it would make the setting compulsory for sheets
     * that never needed it.
     */
    public function testADateOverTwelveIsReadWithoutBeingTold(): void
    {
        self::assertSame('2026-09-13', SheetDate::parse('13/9/2026'));
        self::assertSame('2026-09-13', SheetDate::parse('9/13/2026'));
        self::assertFalse(SheetDate::isAmbiguous('13/9/2026'));
    }

    /** A month in words is unambiguous however it is arranged. */
    public function testAMonthInWordsIsReadEitherWayRound(): void
    {
        foreach (['5 Sep 2026', '5th September 2026', 'Sep 5, 2026', 'Sunday, 5 Sep 2026'] as $written) {
            self::assertSame('2026-09-05', SheetDate::parse($written), $written);
        }
    }

    /**
     * A bare "5 Sep" means the one coming round.
     *
     * Refusing it would mean most real sheets never sync; guessing the wrong
     * year would put a rota twelve months out.
     */
    public function testAMissingYearMeansTheOneComingRound(): void
    {
        self::assertSame('2026-12-25', SheetDate::parse('25 Dec', SheetDate::AUTO, self::TODAY));

        // Six weeks back is still this year: a sheet is often read a little
        // after the date it holds.
        self::assertSame('2026-08-02', SheetDate::parse('2 Aug', SheetDate::AUTO, self::TODAY));

        // Well past, so it means next year's.
        self::assertSame('2027-01-10', SheetDate::parse('10 Jan', SheetDate::AUTO, self::TODAY));
    }

    /** Neither a date nor a phrase strtotime would have obliged with. */
    public function testWordsThatAreNotDatesAreNotDates(): void
    {
        foreach (['next tuesday', '+1 week', 'TBC', '', 'Sound desk', '31 Feb 2026'] as $notADate) {
            self::assertNull(SheetDate::parse($notADate), $notADate);
        }
    }

    // ------------------------------------------------------ a row per date

    public function testARowPerPersonIsRead(): void
    {
        $csv = <<<CSV
        Autumn rota
        Date,Name,Doing,Note
        2026-09-06,Jane Cole,Coffee,"Arrives early, has the key"
        2026-09-13,Sam Ives,Reading,
        CSV;

        $read = SheetLayout::read($csv, SheetLayout::ROWS);

        self::assertSame([], $read['problems']);
        self::assertSame(
            [
                ['date' => '2026-09-06', 'name' => 'Jane Cole', 'role' => 'Coffee',
                 'note' => 'Arrives early, has the key'],
                ['date' => '2026-09-13', 'name' => 'Sam Ives', 'role' => 'Reading', 'note' => ''],
            ],
            $read['rows']
        );
    }

    /** A blank name is nobody yet — normal six weeks out, and not a problem. */
    public function testAnEmptyNameIsNotAProblem(): void
    {
        $csv = "Date,Name\n2026-09-06,Jane Cole\n2026-09-13,\n\n";

        $read = SheetLayout::read($csv, SheetLayout::ROWS);

        self::assertCount(1, $read['rows']);
        self::assertSame([], $read['problems'], 'an empty week was reported as a fault');
    }

    /** An unreadable date IS, because that row is otherwise silently lost. */
    public function testAnUnreadableDateIsReportedWithItsRowNumber(): void
    {
        $csv = "Date,Name\nTBC,Jane Cole\n";

        $read = SheetLayout::read($csv, SheetLayout::ROWS);

        self::assertSame([], $read['rows']);
        self::assertCount(1, $read['problems']);
        self::assertStringContainsString('Row 2', $read['problems'][0]);
        self::assertStringContainsString('TBC', $read['problems'][0]);
    }

    /**
     * And an ambiguous one says something different from an unreadable one.
     *
     * "Not a date" sends somebody to fix a typo. "Could be either" sends them
     * to the one setting that resolves it, and the wrong sentence wastes the
     * afternoon.
     */
    public function testAnAmbiguousDateSaysSoRatherThanCallingItUnreadable(): void
    {
        $read = SheetLayout::read("Date,Name\n5/9/2026,Jane Cole\n", SheetLayout::ROWS);

        self::assertCount(1, $read['problems']);
        self::assertStringContainsString('could be either', $read['problems'][0]);
    }

    public function testASheetWithNoHeadingsSaysWhatItWasLookingFor(): void
    {
        $read = SheetLayout::read("Jane Cole,Coffee\nSam Ives,Reading\n", SheetLayout::ROWS);

        self::assertSame([], $read['rows']);
        self::assertStringContainsString('"date"', $read['problems'][0]);
        self::assertStringContainsString('"name"', $read['problems'][0]);
    }

    // ------------------------------------------------------- a job per column

    public function testAGridIsReadAsOneRowPerFilledCell(): void
    {
        $csv = <<<CSV
        Autumn rota — please tell Jane if you cannot make it

        Date,Coffee,Reading,Sound
        6 Sep 2026,Jane Cole,Sam Ives,
        13 Sep 2026,,Ade Okafor,Sam Ives
        CSV;

        $read = SheetLayout::read($csv, SheetLayout::GRID);

        self::assertSame([], $read['problems']);
        self::assertSame(
            [
                ['date' => '2026-09-06', 'role' => 'Coffee', 'name' => 'Jane Cole', 'note' => ''],
                ['date' => '2026-09-06', 'role' => 'Reading', 'name' => 'Sam Ives', 'note' => ''],
                ['date' => '2026-09-13', 'role' => 'Reading', 'name' => 'Ade Okafor', 'note' => ''],
                ['date' => '2026-09-13', 'role' => 'Sound', 'name' => 'Sam Ives', 'note' => ''],
            ],
            $read['rows']
        );
    }

    /**
     * The title line above the table is skipped, and the heading row is found
     * rather than assumed to be the first one.
     *
     * Every real rota sheet has a banner over it, and taking row one as the
     * headings would name every job after a sentence.
     */
    public function testTheTitleAboveAGridIsNotMistakenForTheHeadings(): void
    {
        $csv = "Autumn rota\n\nDate,Coffee\n6 Sep 2026,Jane Cole\n";

        $read = SheetLayout::read($csv, SheetLayout::GRID);

        self::assertSame('Coffee', $read['rows'][0]['role'], 'the title row became the headings');
    }

    /**
     * A cell holding two names is ONE name.
     *
     * Splitting on "&" reads "Anne & Sons" as two people, and splitting on "-"
     * splits "Mary-Jane". There is no rule that gets both right, so the cell is
     * taken as written and the sheet gets a second column.
     */
    public function testTwoNamesInOneCellAreNotSplit(): void
    {
        $read = SheetLayout::read("Date,Coffee\n6 Sep 2026,Jane & Sam\n", SheetLayout::GRID);

        self::assertCount(1, $read['rows']);
        self::assertSame('Jane & Sam', $read['rows'][0]['name']);
    }

    /**
     * A quoted cell may hold a newline, and reading the file line by line
     * would split that row in half and take the rest of the sheet with it.
     */
    public function testANoteWithALineBreakDoesNotDestroyTheRestOfTheSheet(): void
    {
        $csv = "Date,Name,Note\n2026-09-06,Jane Cole,\"two\nlines\"\n2026-09-13,Sam Ives,\n";

        $read = SheetLayout::read($csv, SheetLayout::ROWS);

        self::assertCount(2, $read['rows'], 'a line break inside a cell ate the sheet');
        self::assertSame("two\nlines", $read['rows'][0]['note']);
    }

    /** A byte-order mark survives a Google export and must not hide a heading. */
    public function testAByteOrderMarkDoesNotHideTheFirstHeading(): void
    {
        $read = SheetLayout::read("\xEF\xBB\xBFDate,Name\n2026-09-06,Jane Cole\n", SheetLayout::ROWS);

        self::assertCount(1, $read['rows'], 'the BOM made the heading row unmatchable');
    }
}
