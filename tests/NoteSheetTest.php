<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\NoteSheet;

/**
 * The fill-in-the-blank sheet's rules, without a database.
 */
final class NoteSheetTest extends TestCase
{
    public function testThreeOrMoreUnderscoresAreAGap(): void
    {
        self::assertSame(2, NoteSheet::gapCount("God's love is ___ and ______."));
        self::assertSame(0, NoteSheet::gapCount('first_name and a__b'), 'one or two underscores are text');
    }

    public function testSegmentsKeepTheOrderOfTextAndGaps(): void
    {
        self::assertSame([
            ['type' => 'text', 'text' => 'Love is '],
            ['type' => 'gap', 'index' => 0],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'gap', 'index' => 1],
            ['type' => 'text', 'text' => '.'],
        ], NoteSheet::segments('Love is ___ and ____.'));
    }

    /**
     * A gap at either end produces no empty text beside it; the space between
     * two gaps is kept, because it is what stops them printing as one line.
     */
    public function testGapsAtTheEdgesAndSideBySide(): void
    {
        self::assertSame([
            ['type' => 'gap', 'index' => 0],
            ['type' => 'text', 'text' => ' '],
            ['type' => 'gap', 'index' => 1],
            ['type' => 'text', 'text' => ' end '],
            ['type' => 'gap', 'index' => 2],
        ], NoteSheet::segments('___ ___ end ___'));

        self::assertSame(1, NoteSheet::gapCount('______'), 'a long run of underscores is one gap');
    }

    /**
     * Rewrapping is the same sheet; rewording is not — including words around a
     * gap, which change what an answer already written there means.
     */
    public function testTheFingerprintIgnoresLayoutButNotWords(): void
    {
        $base = NoteSheet::fingerprint("1. The ___ of God\n2. Is ___");

        self::assertSame($base, NoteSheet::fingerprint("  1. The ___ of God\r\n\n   2. Is ___  \n"));
        self::assertNotSame($base, NoteSheet::fingerprint("1. The ___ of man\n2. Is ___"));
        self::assertNotSame($base, NoteSheet::fingerprint("1. The ___ of God\n2. Is ___ ___"));
    }

    /** Exactly one answer per gap: extras dropped, missing ones empty. */
    public function testAnswersAreShapedToTheSheet(): void
    {
        self::assertSame(['grace', ''], NoteSheet::cleanAnswers(['grace'], 2));
        self::assertSame(['a', 'b'], NoteSheet::cleanAnswers(['a', 'b', 'c', 'd'], 2));
        self::assertSame([], NoteSheet::cleanAnswers(['a'], 0));
    }

    /** One line, trimmed, capped — and odd input is emptied rather than losing the sheet. */
    public function testEachAnswerIsOneCappedLine(): void
    {
        [$first, $second, $third] = NoteSheet::cleanAnswers(
            ["  full\nof  grace ", ['not', 'a', 'string'], str_repeat('é', 500)],
            3
        );

        self::assertSame('full of grace', $first);
        self::assertSame('', $second);
        self::assertSame(NoteSheet::MAX_ANSWER, mb_strlen($third), 'capped by characters, not bytes');
        self::assertSame(['', ''], NoteSheet::cleanAnswers('not even an array', 2));
    }

    /** Keyed answers from a crafted form are read in order, not by key. */
    public function testKeyedInputIsReadInOrder(): void
    {
        self::assertSame(['x', 'y'], NoteSheet::cleanAnswers([5 => 'x', 9 => 'y'], 2));
    }

    public function testChangedOnlyWhenThereAreAnswersFromAnotherVersion(): void
    {
        self::assertTrue(NoteSheet::changedSince(1, 2, ['grace', '']));
        self::assertFalse(NoteSheet::changedSince(2, 2, ['grace', '']));
        self::assertFalse(NoteSheet::changedSince(null, 2, ['', '']), 'never filled in');
        self::assertFalse(NoteSheet::changedSince(1, 2, ['', '']), 'an empty sheet has nothing out of line');
    }

    public function testTheOutlineIsNormalisedAndCapped(): void
    {
        self::assertSame("a\nb", NoteSheet::cleanOutline("  a\r\nb \n"));
        self::assertSame(NoteSheet::MAX_OUTLINE, mb_strlen(NoteSheet::cleanOutline(str_repeat('ü', NoteSheet::MAX_OUTLINE + 50))));
    }
}
