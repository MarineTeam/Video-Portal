<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\TranscriptParser;

/**
 * Parsing subtitle files.
 *
 * The input always comes from a machine — a captioning service, a
 * transcription tool, an export from somewhere else — and the person pasting
 * it did not write it and cannot fix it. So the tests are mostly about
 * tolerating what those tools actually emit, and about the one thing the
 * parser must never do: guess at a timestamp.
 */
final class TranscriptParserTest extends TestCase
{
    // --------------------------------------------------------------- WebVTT

    public function testAPlainVttFileParses(): void
    {
        $cues = TranscriptParser::parse(<<<VTT
        WEBVTT

        00:00:01.000 --> 00:00:04.000
        Hello there.

        00:00:04.500 --> 00:00:08.000
        This is the second line.
        VTT);

        self::assertCount(2, $cues);
        self::assertSame(1, $cues[0]['start']);
        self::assertSame(4, $cues[0]['end']);
        self::assertSame('Hello there.', $cues[0]['text']);
        self::assertSame('This is the second line.', $cues[1]['text']);
    }

    public function testCueIdentifiersAreIgnored(): void
    {
        $cues = TranscriptParser::parse(<<<VTT
        WEBVTT

        intro
        00:00:01.000 --> 00:00:04.000
        Hello there.
        VTT);

        self::assertCount(1, $cues);
        self::assertSame('Hello there.', $cues[0]['text']);
    }

    /** Legal WebVTT, and what most short clips emit. */
    public function testHoursMayBeOmitted(): void
    {
        $cues = TranscriptParser::parse("WEBVTT\n\n01:30.000 --> 01:34.000\nLate in the clip.");

        self::assertCount(1, $cues);
        self::assertSame(90, $cues[0]['start']);
    }

    public function testCueSettingsAfterTheTimestampAreDiscarded(): void
    {
        $cues = TranscriptParser::parse(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000 align:start position:10%\nHello there."
        );

        self::assertCount(1, $cues);
        self::assertSame('Hello there.', $cues[0]['text']);
    }

    /** A comment rendered as a transcript line is worse than a comment lost. */
    public function testNoteAndStyleBlocksAreDropped(): void
    {
        $cues = TranscriptParser::parse(<<<VTT
        WEBVTT

        NOTE
        This file was generated automatically.

        STYLE
        ::cue { color: white }

        00:00:01.000 --> 00:00:04.000
        Hello there.
        VTT);

        self::assertCount(1, $cues);
        self::assertSame('Hello there.', $cues[0]['text']);
    }

    // ------------------------------------------------------------------ SRT

    public function testAnSrtFileParses(): void
    {
        $cues = TranscriptParser::parse(<<<SRT
        1
        00:00:01,000 --> 00:00:04,000
        Hello there.

        2
        00:00:04,500 --> 00:00:08,000
        Second line.
        SRT);

        self::assertCount(2, $cues);
        self::assertSame(1, $cues[0]['start']);
        self::assertSame('Hello there.', $cues[0]['text']);
    }

    /**
     * The format is detected rather than declared, so a file that mixes them —
     * which merged exports do — still works.
     */
    public function testCommaAndPeriodTimestampsCanBothAppear(): void
    {
        $cues = TranscriptParser::parse(<<<MIXED
        1
        00:00:01,000 --> 00:00:04.000
        Mixed separators.
        MIXED);

        self::assertCount(1, $cues);
        self::assertSame(1, $cues[0]['start']);
        self::assertSame(4, $cues[0]['end']);
    }

    // ----------------------------------------------------------- robustness

    /**
     * A BOM survives every copy-paste and makes the first timestamp
     * unparseable — which presents as "the first line is always missing".
     */
    public function testAByteOrderMarkDoesNotEatTheFirstCue(): void
    {
        $cues = TranscriptParser::parse("\u{FEFF}WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nFirst.");

        self::assertCount(1, $cues);
        self::assertSame('First.', $cues[0]['text']);
    }

    public function testWindowsLineEndingsWork(): void
    {
        $cues = TranscriptParser::parse("WEBVTT\r\n\r\n00:00:01.000 --> 00:00:04.000\r\nHello there.");

        self::assertCount(1, $cues);
        self::assertSame('Hello there.', $cues[0]['text']);
    }

    public function testAMultiLineCueBecomesOneSentence(): void
    {
        $cues = TranscriptParser::parse(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nThis line was\nwrapped for a subtitle box."
        );

        self::assertSame('This line was wrapped for a subtitle box.', $cues[0]['text']);
    }

    /** The speaker name is the most useful thing on a two-person recording. */
    public function testASpeakerTagBecomesAName(): void
    {
        $cues = TranscriptParser::parse(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\n<v Jane>Hello there.</v>"
        );

        self::assertSame('Jane: Hello there.', $cues[0]['text']);
    }

    public function testOtherInlineMarkupIsStripped(): void
    {
        $cues = TranscriptParser::parse(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\n<i>Softly</i>, <c.loud>then not</c>."
        );

        self::assertSame('Softly, then not.', $cues[0]['text']);
    }

    public function testEntitiesAreDecoded(): void
    {
        $cues = TranscriptParser::parse(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nRock &amp; roll &quot;forever&quot;"
        );

        self::assertSame('Rock & roll "forever"', $cues[0]['text']);
    }

    /**
     * A file out of order exists — a merge, or an appended correction. Display
     * and seeking both assume order, so it is established once.
     */
    public function testCuesComeBackInTimeOrder(): void
    {
        $cues = TranscriptParser::parse(<<<VTT
        WEBVTT

        00:00:10.000 --> 00:00:12.000
        Later.

        00:00:01.000 --> 00:00:04.000
        Earlier.
        VTT);

        self::assertSame(['Earlier.', 'Later.'], array_column($cues, 'text'));
    }

    /**
     * The words were said. A slightly wrong end time is better than the line
     * vanishing.
     */
    public function testAnEndBeforeTheStartIsClampedRatherThanDropped(): void
    {
        $cues = TranscriptParser::parse("WEBVTT\n\n00:00:10.000 --> 00:00:04.000\nBackwards.");

        self::assertCount(1, $cues);
        self::assertSame(10, $cues[0]['start']);
        self::assertSame(10, $cues[0]['end']);
    }

    // ------------------------------------------------------------- refusals

    /**
     * The one thing the parser must never do. A line placed at the wrong
     * moment is worse than a line missing.
     */
    public function testACueWithNoReadableTimestampIsDropped(): void
    {
        $cues = TranscriptParser::parse(<<<VTT
        WEBVTT

        somewhere around the start
        Hello there.

        00:00:04.000 --> 00:00:08.000
        This one is fine.
        VTT);

        self::assertCount(1, $cues);
        self::assertSame('This one is fine.', $cues[0]['text']);
    }

    /**
     * A file with no blank lines between cues is one "block" holding the whole
     * transcript. Taking the first timestamp and everything after it would
     * produce a single cue containing the entire recording — and search would
     * then index "00:00:04.000" as a word somebody could match.
     */
    public function testAFileWithNoBlankLinesStillSplitsIntoCues(): void
    {
        $cues = TranscriptParser::parse(
            "WEBVTT\n\n"
            . "00:00:01.000 --> 00:00:04.000\nHello there.\n"
            . "00:00:04.000 --> 00:00:08.000\nSecond line.\n"
            . "00:00:08.000 --> 00:00:12.000\nThird line."
        );

        self::assertCount(3, $cues);
        self::assertSame(['Hello there.', 'Second line.', 'Third line.'], array_column($cues, 'text'));
        self::assertStringNotContainsString('-->', TranscriptParser::plainText($cues));
    }

    /**
     * The words after the timestamp were said at a known moment. Losing them
     * to tidy up around the prose in front of them is the wrong trade.
     */
    public function testProseBeforeATimestampIsDroppedButTheCueIsKept(): void
    {
        $cues = TranscriptParser::parse(<<<VTT
        WEBVTT

        Here is a note somebody pasted
        across two lines
        00:00:04.000 --> 00:00:08.000
        The actual line.
        VTT);

        self::assertCount(1, $cues);
        self::assertSame('The actual line.', $cues[0]['text']);
        self::assertSame(4, $cues[0]['start']);
    }

    public function testProseIsNotMistakenForCues(): void
    {
        self::assertSame([], TranscriptParser::parse(
            "Here is a paragraph somebody pasted by mistake.\n\nAnd a second one."
        ));
    }

    public function testAnEmptyDocumentParsesToNothing(): void
    {
        self::assertSame([], TranscriptParser::parse(''));
        self::assertSame([], TranscriptParser::parse("   \n\n  "));
        self::assertSame([], TranscriptParser::parse('WEBVTT'));
    }

    public function testACueWithATimestampAndNoWordsIsDropped(): void
    {
        $cues = TranscriptParser::parse("WEBVTT\n\n00:00:01.000 --> 00:00:04.000\n\n");

        self::assertSame([], $cues);
    }

    /**
     * The input arrives from a textarea and an upload; neither should turn one
     * request into a hundred thousand inserts.
     */
    public function testTheCueCountIsCapped(): void
    {
        $blocks = '';
        for ($i = 0; $i < TranscriptParser::MAX_CUES + 50; $i++) {
            $blocks .= sprintf("00:00:%02d.000 --> 00:00:%02d.000\nLine %d\n\n", $i % 60, ($i % 60) + 1, $i);
        }

        self::assertCount(TranscriptParser::MAX_CUES, TranscriptParser::parse("WEBVTT\n\n" . $blocks));
    }

    public function testAnAbsurdlyLongCueIsTruncated(): void
    {
        $cues = TranscriptParser::parse(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\n" . str_repeat('word ', 2000)
        );

        self::assertLessThanOrEqual(TranscriptParser::MAX_TEXT_LENGTH, mb_strlen($cues[0]['text']));
    }

    // -------------------------------------------------------------- output

    public function testPlainTextIsEverythingSaid(): void
    {
        $cues = TranscriptParser::parse(<<<VTT
        WEBVTT

        00:00:01.000 --> 00:00:04.000
        Hello there.

        00:00:04.500 --> 00:00:08.000
        Second line.
        VTT);

        self::assertSame('Hello there. Second line.', TranscriptParser::plainText($cues));
    }

    /** Timestamps must not be indexed, or "2026" matches every transcript. */
    public function testPlainTextCarriesNoTimestamps(): void
    {
        $cues = TranscriptParser::parse("WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nHello there.");

        self::assertSame('Hello there.', TranscriptParser::plainText($cues));
    }

    public function testCuesRoundTripThroughVtt(): void
    {
        $original = TranscriptParser::parse(<<<VTT
        WEBVTT

        00:01:01.000 --> 00:01:04.000
        Hello there.

        00:01:04.000 --> 00:01:08.000
        Second line.
        VTT);

        $again = TranscriptParser::parse(TranscriptParser::toVtt($original));

        self::assertSame($original, $again);
    }
}
