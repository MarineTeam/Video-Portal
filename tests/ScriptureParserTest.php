<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\ScriptureBooks;
use Portal\Content\ScriptureParser;

/**
 * Finding references in prose nobody wrote for a parser.
 *
 * The governing trade is stated on the class and tested here: it is tuned to be
 * QUIET. A missed reference costs one sermon not appearing under one chapter,
 * and somebody can add it by hand. A false one files a sermon under a passage
 * it never mentions — confidently wrong, invisible to whoever caused it, and
 * enough to make nobody trust the browse pages. So roughly half of these are
 * tests that something is NOT found.
 */
final class ScriptureParserTest extends TestCase
{
    // ----------------------------------------------------------- book names

    public function testTheOrdinaryFormsResolve(): void
    {
        self::assertSame('john', ScriptureBooks::resolve('John'));
        self::assertSame('1-corinthians', ScriptureBooks::resolve('1 Corinthians'));
        self::assertSame('revelation', ScriptureBooks::resolve('Revelation'));
    }

    /**
     * The alias table is the whole feature. A parser that only knows
     * "1 Corinthians" misses most of what gets typed into a description.
     */
    public function testEveryWayPeopleWriteAnOrdinalResolves(): void
    {
        foreach (['1 Corinthians', '1Corinthians', '1 Cor', '1Cor', '1 Co', 'I Corinthians',
                  'I Cor', 'First Corinthians', '1st Cor', '1 cor.'] as $written) {
            self::assertSame('1-corinthians', ScriptureBooks::resolve($written), $written);
        }
    }

    /**
     * Four books have names of more than one word, and a pattern matching a
     * single capitalised word finds none of them — silently, while every other
     * book works. Found by the "every book in a sentence" check at the bottom
     * of this file rather than by anyone reading the regex.
     */
    public function testMultiWordBookNamesAreFoundInText(): void
    {
        self::assertSame('song-of-songs', ScriptureParser::parse('A sermon on Song of Songs 2:1')[0]['book']);
        self::assertSame('song-of-songs', ScriptureParser::parse('A sermon on Song of Solomon 2:1')[0]['book']);
        self::assertSame('wisdom', ScriptureParser::parse('Wisdom of Solomon 7:22')[0]['book']);
        self::assertSame('acts', ScriptureParser::parse('Acts of the Apostles 2:42')[0]['book']);
    }

    /** And the pattern must not wander into the rest of the sentence. */
    public function testTheMultiWordPatternDoesNotSwallowProse(): void
    {
        self::assertSame([], ScriptureParser::parse('The Book of Life 3 is not scripture'));

        $found = ScriptureParser::parse('Acts 2 and the Word of God');
        self::assertCount(1, $found);
        self::assertSame('acts', $found[0]['book']);
    }

    public function testAbbreviationsAndAlternativeNamesResolve(): void
    {
        self::assertSame('psalms', ScriptureBooks::resolve('Ps'));
        self::assertSame('song-of-songs', ScriptureBooks::resolve('Song of Solomon'));
        self::assertSame('sirach', ScriptureBooks::resolve('Ecclesiasticus'));
        self::assertSame('revelation', ScriptureBooks::resolve('Apocalypse'));
        self::assertSame('2-john', ScriptureBooks::resolve('IIJohn'));
    }

    /**
     * The important half. "Jud" is written for both Jude and Judges and "Ez"
     * for both Ezra and Ezekiel; guessing files a sermon under the wrong book,
     * which is worse than not indexing it, because a missing entry is invisible
     * and a wrong one is confidently misleading.
     */
    public function testASpellingTwoBooksAnswerToIsRefused(): void
    {
        self::assertNull(ScriptureBooks::resolve('Jud'));
        self::assertNull(ScriptureBooks::resolve('Ez'));
    }

    /** But the unambiguous neighbours still work. */
    public function testTheUnambiguousNeighboursStillResolve(): void
    {
        self::assertSame('jude', ScriptureBooks::resolve('Jude'));
        self::assertSame('judges', ScriptureBooks::resolve('Judges'));
        self::assertSame('ezra', ScriptureBooks::resolve('Ezra'));
        self::assertSame('ezekiel', ScriptureBooks::resolve('Ezekiel'));
    }

    /**
     * A leading "i" must not be read as a roman numeral, or Isaiah becomes
     * "1saiah" and the commonest Old Testament book stops resolving.
     */
    public function testIsaiahIsNotMistakenForAnOrdinal(): void
    {
        self::assertSame('isaiah', ScriptureBooks::resolve('Isaiah'));
        self::assertSame('isaiah', ScriptureBooks::resolve('Isa'));
        self::assertSame('isaiah', ScriptureBooks::resolve('Is'));
    }

    public function testNonsenseResolvesToNothing(): void
    {
        self::assertNull(ScriptureBooks::resolve(''));
        self::assertNull(ScriptureBooks::resolve('Hesitations'));
        self::assertNull(ScriptureBooks::resolve('42'));
    }

    // -------------------------------------------------------------- parsing

    public function testASimpleReferenceIsFound(): void
    {
        $found = ScriptureParser::parse('A sermon on John 3:16.');

        self::assertCount(1, $found);
        self::assertSame('john', $found[0]['book']);
        self::assertSame(3, $found[0]['chapter']);
        self::assertSame(16, $found[0]['verse']);
        self::assertSame('John 3:16', ScriptureParser::format($found[0]));
    }

    public function testAWholeChapterHasNoVerse(): void
    {
        $found = ScriptureParser::parse('Psalm 23');

        self::assertCount(1, $found);
        self::assertNull($found[0]['verse'], 'a whole-chapter reference is not verse zero');
        self::assertSame('Psalms 23', ScriptureParser::format($found[0]));
    }

    public function testAVerseRangeIsFound(): void
    {
        $found = ScriptureParser::parse('Romans 8:28-30');

        self::assertSame(28, $found[0]['verse']);
        self::assertSame(8, $found[0]['endChapter']);
        self::assertSame(30, $found[0]['endVerse']);
    }

    /**
     * "John 3-4" is a chapter range and "John 3:16-18" is a verse range, and
     * both end in a bare number — the difference is entirely whether a verse
     * was given at the start.
     */
    public function testABareRangeIsChaptersWhenNoVerseWasGiven(): void
    {
        $found = ScriptureParser::parse('John 3-4');

        self::assertSame(3, $found[0]['chapter']);
        self::assertNull($found[0]['verse']);
        self::assertSame(4, $found[0]['endChapter']);
        self::assertNull($found[0]['endVerse'], 'a bare range after a bare chapter is chapters');
    }

    public function testARangeAcrossChaptersIsFound(): void
    {
        $found = ScriptureParser::parse('Genesis 1:1-2:3');

        self::assertSame(1, $found[0]['chapter']);
        self::assertSame(1, $found[0]['verse']);
        self::assertSame(2, $found[0]['endChapter']);
        self::assertSame(3, $found[0]['endVerse']);
    }

    /** Descriptions are pasted from word processors, which rewrite hyphens. */
    public function testEnAndEmDashesWork(): void
    {
        self::assertSame(30, ScriptureParser::parse('Romans 8:28–30')[0]['endVerse']);
        self::assertSame(30, ScriptureParser::parse('Romans 8:28—30')[0]['endVerse']);
    }

    public function testSeveralReferencesInOnePieceOfText(): void
    {
        $found = ScriptureParser::parse(
            'We begin in Genesis 1, turn to Isaiah 53:5, and finish with 1 Peter 2:24.'
        );

        self::assertSame(['genesis', 'isaiah', '1-peter'], array_column($found, 'book'));
    }

    /** Deduplicated on the resolved value, so two spellings collapse. */
    public function testTheSamePassageWrittenTwiceIsOneReference(): void
    {
        $found = ScriptureParser::parse('Jn 3:16 — and again, John 3:16.');

        self::assertCount(1, $found);
    }

    public function testDifferentPassagesInOneBookAreNotCollapsed(): void
    {
        $found = ScriptureParser::parse('John 3:16 and John 14:6');

        self::assertCount(2, $found);
    }

    // -------------------------------------------------- what is NOT a reference

    /**
     * The single most effective guard there is. "he acts 2 ways" is ordinary
     * English and "Acts 2" is a reference; in running prose the capital letter
     * is the only thing separating them.
     */
    public function testLowercaseProseIsNotAReference(): void
    {
        self::assertSame([], ScriptureParser::parse('he acts 2 ways about it'));
        self::assertSame([], ScriptureParser::parse('in job 3 he was still working'));
        self::assertSame([], ScriptureParser::parse('a mark 4 on the scale'));
    }

    /** What stops a year, and a mistyped number, becoming a browse page. */
    public function testAChapterTheBookDoesNotHaveIsNotAReference(): void
    {
        self::assertSame([], ScriptureParser::parse('John 99:1'));
        self::assertSame([], ScriptureParser::parse('Exodus 2026 was a good year'));
        self::assertSame([], ScriptureParser::parse('Jude 2'), 'Jude has one chapter');
    }

    public function testAnUnknownBookIsNotAReference(): void
    {
        self::assertSame([], ScriptureParser::parse('Hesitations 3:16'));
    }

    /**
     * "John 3:16-2" is far more likely to be "3:16" followed by something else
     * than a range anybody meant, so it is refused rather than swapped.
     */
    public function testABackwardsRangeIsRefused(): void
    {
        self::assertSame([], ScriptureParser::parse('John 3:16-2'));
        self::assertSame([], ScriptureParser::parse('Genesis 5:1-2:3'));
    }

    public function testEmptyTextFindsNothing(): void
    {
        self::assertSame([], ScriptureParser::parse(''));
        self::assertSame([], ScriptureParser::parse('   '));
        self::assertSame([], ScriptureParser::parse('A sermon with no references at all.'));
    }

    /**
     * A timestamp is not a reference. This matters because the same
     * descriptions carry chapter lists — "2:15 The reading" — and there is no
     * book name in front of them.
     */
    public function testATimestampIsNotAReference(): void
    {
        self::assertSame([], ScriptureParser::parse("0:00 Welcome\n2:15 The reading\n14:30 Questions"));
    }

    /** The input arrives from a textarea; one paste must not become a thousand inserts. */
    public function testTheNumberOfReferencesIsCapped(): void
    {
        $text = str_repeat('Psalm 1 Psalm 2 Psalm 3 Psalm 4 Psalm 5 Psalm 6 Psalm 7 Psalm 8 Psalm 9 Psalm 10 ', 20);

        self::assertLessThanOrEqual(ScriptureParser::MAX_REFERENCES, count(ScriptureParser::parse($text)));
    }

    // ------------------------------------------------------------ formatting

    /**
     * Built from the resolved parts rather than echoing what was typed, so a
     * list of references written five ways reads as one list.
     */
    public function testFormattingIsCanonicalRatherThanWhatWasTyped(): void
    {
        self::assertSame('1 Corinthians 13', ScriptureParser::format(ScriptureParser::parse('1Co 13')[0]));
        self::assertSame('Psalms 23', ScriptureParser::format(ScriptureParser::parse('Ps 23')[0]));
    }

    public function testRangesFormatReadably(): void
    {
        self::assertSame('Romans 8:28–30', ScriptureParser::format(ScriptureParser::parse('Rom 8:28-30')[0]));
        self::assertSame('Genesis 1:1–2:3', ScriptureParser::format(ScriptureParser::parse('Gen 1:1-2:3')[0]));
        self::assertSame('John 3–4', ScriptureParser::format(ScriptureParser::parse('John 3-4')[0]));
    }

    // ------------------------------------------------------------- the canon

    public function testEveryBookResolvesFromItsOwnNameAndSlug(): void
    {
        foreach (ScriptureBooks::all() as $slug => [$name, $testament, $chapters]) {
            self::assertSame($slug, ScriptureBooks::resolve($name), $name . ' does not resolve from its own name');
            self::assertSame($slug, ScriptureBooks::resolve($slug), $slug . ' does not resolve from its own slug');
            self::assertGreaterThan(0, $chapters, $name . ' has no chapters');
            self::assertContains($testament, ['ot', 'nt', 'dc'], $name . ' is in no testament');
        }
    }

    /**
     * Every book has to be parseable from real text, not merely resolvable.
     * A book whose name the regex cannot reach is a book nothing is ever filed
     * under, and nothing else here would notice.
     */
    public function testEveryBookCanBeFoundInASentence(): void
    {
        foreach (ScriptureBooks::names() as $slug => $name) {
            $found = ScriptureParser::parse('A sermon on ' . $name . ' 1.');

            self::assertCount(1, $found, $name . ' cannot be found in ordinary text');
            self::assertSame($slug, $found[0]['book'], $name . ' was read as something else');
        }
    }
}
