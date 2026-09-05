<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\TypoTolerance;

/**
 * Finding the word somebody meant.
 *
 * The interesting assertions here are the REFUSALS. A correction that fires
 * too readily is worse than none: it takes somebody who searched correctly and
 * shows them a different sermon, and they have no way to tell that is what
 * happened. So most of this file is about what it declines to do.
 */
final class TypoToleranceTest extends TestCase
{
    /**
     * @var list<string>
     *
     * "psalm" and "psalms" are both here on purpose, one edit apart. Without a
     * near neighbour, a test asserting that a real word is left alone passes
     * against an implementation that does not leave it alone — there is simply
     * nothing for it to wander to. That happened: the rule survived being
     * deleted until this pair was added.
     */
    private const VOCAB = [
        'marriage', 'romans', 'grace', 'philip', 'ecclesiastes', 'advent',
        'forgiveness', 'psalm', 'psalms', 'prayer', 'covenant', 'john', 'love',
    ];

    // ------------------------------------------------------ it corrects

    public function testItFindsAWordOneLetterAway(): void
    {
        self::assertSame('marriage', TypoTolerance::nearest('marrige', self::VOCAB));
        self::assertSame('romans', TypoTolerance::nearest('romsns', self::VOCAB));
    }

    /** Two letters, but only in a word long enough to spare them. */
    public function testItForgivesTwoLettersInALongWord(): void
    {
        self::assertSame('ecclesiastes', TypoTolerance::nearest('eclesiastes', self::VOCAB));
        self::assertSame('forgiveness', TypoTolerance::nearest('forgivness', self::VOCAB));
    }

    /**
     * And a spelling that sounds right, which edit distance alone refuses.
     *
     * "filip" is two edits from "philip" in a five-letter word, where the
     * threshold is one. Reading them aloud they are the same name, which is
     * exactly how somebody arrives at the wrong spelling in the first place.
     */
    public function testAWordThatSoundsRightIsAcceptedBeyondTheEditThreshold(): void
    {
        self::assertSame('philip', TypoTolerance::nearest('filip', self::VOCAB));
    }

    // -------------------------------------------------------- it refuses

    /**
     * THE RULE THAT MATTERS MOST: a word the library actually contains is
     * never corrected.
     *
     * "grace romans" finding nothing means no video has both words, not that
     * either is misspelled. Correcting one would move the search away from
     * what was asked for while looking like help.
     */
    public function testAWordThatIsAlreadyInTheLibraryIsNeverCorrected(): void
    {
        /*
         * "psalm" and "psalms" are one edit apart and both real, which is the
         * only arrangement that can catch this. Asked about a word with no near
         * neighbour, an implementation that has forgotten the rule still
         * answers null — there is nothing for it to wander to — so the test
         * passes against the bug. It did, until this pair was added.
         */
        self::assertContains('psalms', self::VOCAB);
        self::assertSame(1, levenshtein('psalm', 'psalms'), 'the fixture no longer stages the hazard');

        self::assertNull(
            TypoTolerance::nearest('psalm', self::VOCAB),
            'a word the library contains was "corrected" to its neighbour'
        );

        self::assertNull(TypoTolerance::nearest('grace', self::VOCAB));
        self::assertNull(TypoTolerance::correct(['grace', 'psalm'], self::VOCAB));
    }

    /**
     * Short words are left alone entirely.
     *
     * At three letters nearly everything is one edit from something else, and
     * the words a sermon archive is full of — God, Job, joy, sin — are exactly
     * the ones that would be mangled.
     */
    public function testShortWordsAreNeverCorrected(): void
    {
        /*
         * The behaviour first, the accessor second. Asserting threshold() at
         * the top aborts the test before nearest() is ever asked, so a
         * mutation would be killed by the reading rather than by the refusal —
         * and it is the refusal that is the feature.
         *
         * "joh" is one edit from "john" and "lov" one from "love", so both
         * would be corrected the moment the length rule went.
         */
        self::assertNull(TypoTolerance::nearest('joh', self::VOCAB));
        self::assertNull(TypoTolerance::nearest('lov', self::VOCAB));

        self::assertSame(0, TypoTolerance::threshold('sin'));
    }

    /** A word nothing is close to stays as it is. */
    public function testAWordNothingResemblesIsLeftAlone(): void
    {
        self::assertNull(TypoTolerance::nearest('helicopter', self::VOCAB));
        self::assertNull(TypoTolerance::correct(['helicopter'], self::VOCAB));
    }

    /**
     * A quoted phrase is not corrected.
     *
     * Quotation marks are the one piece of search syntax this product supports,
     * and they mean "these exact words". The honest answer to a phrase nothing
     * contains is that nothing contains it.
     */
    public function testAQuotedPhraseIsLeftExactlyAsTyped(): void
    {
        self::assertNull(TypoTolerance::correct(['sermon on the mout'], self::VOCAB));
    }

    /**
     * Non-ASCII is skipped rather than compared badly.
     *
     * levenshtein() counts bytes, so a term with an accent compares as further
     * away than it is. Refusing is the safe direction: the search behaves as it
     * did before, instead of sending somebody somewhere wrong.
     */
    public function testAnAccentedWordIsSkippedRatherThanMiscompared(): void
    {
        self::assertNull(TypoTolerance::nearest('marriagé', self::VOCAB));
        self::assertNull(TypoTolerance::nearest('романс', self::VOCAB));
    }

    public function testAnEmptyVocabularyCorrectsNothing(): void
    {
        self::assertNull(TypoTolerance::correct(['marrige'], []));
        self::assertNull(TypoTolerance::correct([], self::VOCAB));
    }

    // ------------------------------------------------------ whole queries

    public function testCorrectRewritesOnlyTheWordsThatNeededIt(): void
    {
        self::assertSame(
            ['grace', 'marriage'],
            TypoTolerance::correct(['grace', 'marrige'], self::VOCAB)
        );
    }

    /**
     * Null, not the original terms, when nothing changed.
     *
     * A caller handed back its own input cannot tell "I have a suggestion" from
     * "I have nothing", and the failure is offering somebody their own spelling
     * as an alternative to itself.
     */
    public function testNothingToCorrectAnswersNullRatherThanTheInput(): void
    {
        self::assertNull(TypoTolerance::correct(['grace'], self::VOCAB));
    }

    /**
     * The same query always suggests the same word.
     *
     * Ties are broken by common prefix and then alphabetically rather than by
     * array order, which would otherwise depend on how the vocabulary happened
     * to come back from the database — so a suggestion would change when
     * somebody added an unrelated video.
     */
    public function testTiesAreBrokenTheSameWayWhateverOrderTheVocabularyArrivesIn(): void
    {
        /*
         * A REAL tie, which took two attempts to arrange. The first fixture put
         * one word a clear edit closer than the others, so whichever way the
         * list was ordered the same word won — and a "first one wins"
         * implementation passed it. Both of these are exactly one edit from
         * "grae" and share exactly the same prefix, so nothing but the
         * tiebreaker can separate them.
         */
        $vocab = ['grape', 'grace'];

        self::assertSame(levenshtein('grae', 'grape'), levenshtein('grae', 'grace'));

        // Alphabetical is the rule, once distance and prefix have both tied.
        self::assertSame('grace', TypoTolerance::nearest('grae', $vocab));
        self::assertSame(
            'grace',
            TypoTolerance::nearest('grae', array_reverse($vocab)),
            'the answer depended on the order the vocabulary came back in'
        );
    }

    public function testThresholdScalesWithLength(): void
    {
        self::assertSame(0, TypoTolerance::threshold('joy'));
        self::assertSame(1, TypoTolerance::threshold('love'));
        self::assertSame(1, TypoTolerance::threshold('psalms'));
        self::assertSame(2, TypoTolerance::threshold('covenant'));
    }
}
