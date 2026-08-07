<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\CaptionFile;

/**
 * Preparing a subtitle file for somebody else's player.
 *
 * The claim under test throughout is that the conversion is TEXTUAL: timing
 * lines are rewritten and nothing else is, so milliseconds, cue settings and
 * inline styling survive. That is the entire reason this class exists rather
 * than the transcript parser being reused, and most of these tests exist to
 * stop somebody helpfully "simplifying" it into a parse-and-regenerate.
 */
final class CaptionFileTest extends TestCase
{
    // ------------------------------------------------------------- languages

    public function testAnOrdinaryLanguageTagIsAccepted(): void
    {
        self::assertSame('en', CaptionFile::language('en'));
        self::assertSame('spa', CaptionFile::language('spa'));
        self::assertSame('pt-br', CaptionFile::language('pt-br'));
        self::assertSame('zh-hant', CaptionFile::language('zh-hant'));
    }

    /**
     * The tag is the key the provider stores under, so two spellings of one
     * language would become two tracks and the player would offer the viewer
     * both.
     */
    public function testCaseAndSurroundingSpaceAreNotTwoDifferentLanguages(): void
    {
        self::assertSame('en', CaptionFile::language('EN'));
        self::assertSame('en', CaptionFile::language('  en  '));
        self::assertSame('pt-br', CaptionFile::language('pt-BR'));
    }

    /**
     * It becomes a URL path segment at the provider. Anything that could not be
     * one is refused here rather than encoded into something that gets stored
     * and can never be addressed again.
     */
    public function testATagThatIsNotALanguageIsRefused(): void
    {
        self::assertNull(CaptionFile::language(''));
        self::assertNull(CaptionFile::language('e'));
        self::assertNull(CaptionFile::language('english language'));
        self::assertNull(CaptionFile::language('../../etc/passwd'));
        self::assertNull(CaptionFile::language('en/../fr'));
        self::assertNull(CaptionFile::language('en?x=1'));
        self::assertNull(CaptionFile::language('e n'));
        self::assertNull(CaptionFile::language(str_repeat('en-', 20)));
    }

    // ---------------------------------------------------------------- labels

    public function testALabelIsKeptAsGiven(): void
    {
        self::assertSame('English (captioned live)', CaptionFile::label('English (captioned live)', 'en'));
    }

    /** A caption track with no label is one nobody can pick from the menu. */
    public function testAnEmptyLabelBecomesTheLanguageName(): void
    {
        self::assertSame('Spanish', CaptionFile::label('', 'es'));
        self::assertSame('Spanish', CaptionFile::label('   ', 'es'));
    }

    /** And a language with no name of its own still gets something. */
    public function testAnUnknownLanguageFallsBackToItsTag(): void
    {
        self::assertSame('cy-gb', CaptionFile::label('', 'cy-gb'));
    }

    public function testALabelIsOneLine(): void
    {
        self::assertSame('English subtitles', CaptionFile::label("English\nsubtitles", 'en'));
        self::assertSame('English subtitles', CaptionFile::label("English\t \tsubtitles", 'en'));
    }

    public function testALongLabelIsCut(): void
    {
        self::assertSame(60, mb_strlen(CaptionFile::label(str_repeat('a', 200), 'en')));
    }

    // ------------------------------------------------------------ conversion

    public function testWebVttPassesThroughWithItsHeaderIntact(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.500 --> 00:00:04.250\nHello.\n";

        $out = CaptionFile::toVtt($vtt);

        self::assertNotNull($out);
        self::assertStringStartsWith('WEBVTT', $out);
        self::assertStringContainsString('00:00:01.500 --> 00:00:04.250', $out);
        // One header, not two.
        self::assertSame(1, substr_count($out, 'WEBVTT'));
    }

    public function testSubRipGainsTheHeaderAndLosesTheComma(): void
    {
        $srt = "1\n00:00:01,500 --> 00:00:04,250\nHello.\n\n2\n00:00:05,000 --> 00:00:07,000\nAgain.\n";

        $out = CaptionFile::toVtt($srt);

        self::assertNotNull($out);
        self::assertStringStartsWith("WEBVTT\n\n", $out);
        self::assertStringContainsString('00:00:01.500 --> 00:00:04.250', $out);
        self::assertStringContainsString('00:00:05.000 --> 00:00:07.000', $out);
        self::assertStringNotContainsString(',', $out);
    }

    /**
     * The point of the whole class.
     *
     * A caption half a second late lands on the wrong shot. The transcript
     * parser rounds to the second deliberately — a transcript panel seeks to
     * the second — and a conversion that went through it would round these too,
     * with nothing anywhere reporting a problem.
     */
    public function testMillisecondsSurvive(): void
    {
        $out = CaptionFile::toVtt("WEBVTT\n\n00:01:02.345 --> 00:01:04.987\nExactly then.\n");

        self::assertNotNull($out);
        self::assertStringContainsString('00:01:02.345 --> 00:01:04.987', $out);
    }

    /** ".5" is half a second, not five thousandths. */
    public function testAShortFractionIsPaddedOnTheRight(): void
    {
        $out = CaptionFile::toVtt("1\n00:00:01,5 --> 00:00:04,25\nHello.\n");

        self::assertNotNull($out);
        self::assertStringContainsString('00:00:01.500 --> 00:00:04.250', $out);
    }

    /** WebVTT requires exactly three digits; some tools emit none. */
    public function testAMissingFractionBecomesZeroes(): void
    {
        $out = CaptionFile::toVtt("1\n00:00:01 --> 00:00:04\nHello.\n");

        self::assertNotNull($out);
        self::assertStringContainsString('00:00:01.000 --> 00:00:04.000', $out);
    }

    /**
     * The difference between a caption in the corner and one over somebody's
     * face. A regenerating converter drops these silently.
     */
    public function testCueSettingsSurvive(): void
    {
        $out = CaptionFile::toVtt(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000 line:0 position:20% align:start\nUp there.\n"
        );

        self::assertNotNull($out);
        self::assertStringContainsString('line:0 position:20% align:start', $out);
    }

    public function testCueIdentifiersAndStylingSurvive(): void
    {
        $out = CaptionFile::toVtt(
            "WEBVTT\n\nNOTE Recorded live\n\nintro\n00:00:01.000 --> 00:00:04.000\n<i>Softly</i>\n"
        );

        self::assertNotNull($out);
        self::assertStringContainsString('NOTE Recorded live', $out);
        self::assertStringContainsString('intro', $out);
        self::assertStringContainsString('<i>Softly</i>', $out);
    }

    public function testMinutesAndSecondsWithoutHoursAreLegal(): void
    {
        $out = CaptionFile::toVtt("WEBVTT\n\n01:02.500 --> 01:04.000\nShort clip.\n");

        self::assertNotNull($out);
        self::assertStringContainsString('01:02.500 --> 01:04.000', $out);
    }

    // -------------------------------------------------------------- refusals

    /**
     * A file the provider would accept and the player would show as an empty
     * track, which a viewer reads as captions being broken rather than absent.
     */
    public function testSomethingWithNoTimingsIsRefused(): void
    {
        self::assertNull(CaptionFile::toVtt(''));
        self::assertNull(CaptionFile::toVtt('   '));
        self::assertNull(CaptionFile::toVtt('WEBVTT'));
        self::assertNull(CaptionFile::toVtt("WEBVTT\n\nNOTE nothing here\n"));
        self::assertNull(CaptionFile::toVtt("Just some prose about the sermon.\nNo times at all.\n"));
        self::assertNull(CaptionFile::toVtt('{"json": true}'));
    }

    // ----------------------------------------------------------- normalising

    /** Presents as the first caption never appearing. */
    public function testAByteOrderMarkDoesNotEatTheFirstCue(): void
    {
        $out = CaptionFile::toVtt("\u{FEFF}1\n00:00:01,000 --> 00:00:04,000\nFirst line.\n");

        self::assertNotNull($out);
        self::assertSame(1, CaptionFile::cueCount($out));
        self::assertStringContainsString('First line.', $out);
    }

    public function testWindowsLineEndingsAreHandled(): void
    {
        $out = CaptionFile::toVtt("1\r\n00:00:01,000 --> 00:00:04,000\r\nHello.\r\n");

        self::assertNotNull($out);
        self::assertStringNotContainsString("\r", $out);
        self::assertSame(1, CaptionFile::cueCount($out));
    }

    /** Otherwise it reaches the provider and renders as mojibake over the video. */
    public function testALatinOneExportBecomesUtfEight(): void
    {
        $out = CaptionFile::toVtt(
            "1\n00:00:01,000 --> 00:00:04,000\n" . mb_convert_encoding('Café', 'Windows-1252', 'UTF-8') . "\n"
        );

        self::assertNotNull($out);
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
        self::assertStringContainsString('Café', $out);
    }

    // ------------------------------------------------------------ cue counts

    /**
     * The only feedback there is. Once uploaded the captions live at the
     * provider and nothing here can look inside them again, so a file that
     * yielded four cues out of four hundred has to be visible at that moment.
     */
    public function testCuesAreCounted(): void
    {
        $out = CaptionFile::toVtt(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nOne.\n\n"
            . "00:00:05.000 --> 00:00:08.000\nTwo.\n\n"
            . "00:00:09.000 --> 00:00:12.000\nThree.\n"
        );

        self::assertNotNull($out);
        self::assertSame(3, CaptionFile::cueCount($out));
    }

    /** Cue text is not a cue, however much of it there is. */
    public function testTextIsNotCountedAsCues(): void
    {
        $out = CaptionFile::toVtt(
            "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nOne line.\nAnd another.\nAnd a third.\n"
        );

        self::assertNotNull($out);
        self::assertSame(1, CaptionFile::cueCount($out));
    }

    // -------------------------------------------------- built from a transcript

    public function testATranscriptBecomesUsableCaptions(): void
    {
        $out = CaptionFile::fromTranscriptCues([
            ['start' => 1, 'end' => 4, 'text' => 'Welcome to the recording.'],
            ['start' => 4, 'end' => 9, 'text' => 'And the second line.'],
        ]);

        self::assertNotNull($out);
        self::assertStringStartsWith('WEBVTT', $out);
        self::assertSame(2, CaptionFile::cueCount($out));
        self::assertStringContainsString('Welcome to the recording.', $out);
        self::assertStringContainsString('00:00:01.000 --> 00:00:04.000', $out);
    }

    /**
     * The cost, asserted rather than only written down.
     *
     * Cues are stored at second precision because a transcript panel seeks to
     * the second, so captions built this way land on the second. The screen
     * that offers the conversion says so; this is what makes that claim true
     * and keeps it true.
     */
    public function testCaptionsFromATranscriptLandOnTheSecond(): void
    {
        $out = CaptionFile::fromTranscriptCues([
            ['start' => 62, 'end' => 65, 'text' => 'Somewhere in the middle.'],
        ]);

        self::assertNotNull($out);
        self::assertStringContainsString('00:01:02.000 --> 00:01:05.000', $out);
    }

    public function testAVideoWithNoTranscriptConvertsToNothing(): void
    {
        self::assertNull(CaptionFile::fromTranscriptCues([]));
    }

    // ------------------------------------------------------------- the roster

    public function testEveryOfferedLanguageIsOneTheValidatorAccepts(): void
    {
        foreach (CaptionFile::languages() as $tag => $name) {
            self::assertSame($tag, CaptionFile::language($tag), $tag . ' is offered but would be refused.');
            self::assertNotSame('', $name);
        }
    }
}
