<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Reactions\ReactionPolicy;

require_once dirname(__DIR__) . '/plugins/reactions/src/ReactionPolicy.php';

/**
 * What a reaction is.
 *
 * The rules here are small, and each one is the difference between this and a
 * second rating system with pictures.
 */
final class ReactionPolicyTest extends TestCase
{
    public function testTheVocabularyIsClosed(): void
    {
        self::assertTrue(ReactionPolicy::isKind('amen'));
        self::assertFalse(ReactionPolicy::isKind('shrug'));
        self::assertFalse(ReactionPolicy::isKind(''));
        self::assertFalse(ReactionPolicy::isKind('AMEN'), 'Kinds are stored keys, not user text.');
    }

    /**
     * Zeroes are kept and the order is the vocabulary's.
     *
     * A reaction with no count still has to render as a button somebody can
     * press, and ordering by count would make the buttons move under the cursor
     * as other people react — which is how somebody presses "Amen" and hits
     * "Helpful".
     */
    public function testFillReturnsEveryKindInOrderIncludingZeroes(): void
    {
        $filled = ReactionPolicy::fill(['helpful' => 3]);

        self::assertSame(array_keys(ReactionPolicy::kinds()), array_keys($filled));
        self::assertSame(3, $filled['helpful']);
        self::assertSame(0, $filled['amen']);
    }

    /** A row left behind by an older vocabulary is inert, not a button. */
    public function testFillDropsKindsThatAreNoLongerInTheVocabulary(): void
    {
        $filled = ReactionPolicy::fill(['amen' => 2, 'retired-kind' => 99]);

        self::assertArrayNotHasKey('retired-kind', $filled);
        self::assertSame(2, $filled['amen']);
    }

    /** A negative count is a broken query, not something to render. */
    public function testFillFloorsAtZero(): void
    {
        self::assertSame(0, ReactionPolicy::fill(['amen' => -5])['amen']);
    }

    /**
     * A row of four zeroes under every video on a quiet site tells a visitor
     * the site is empty rather than that the feature is new. Somebody who can
     * react still sees the buttons — they are the only person who can change
     * the answer.
     */
    public function testAnEmptyRowIsHiddenFromSomebodyWhoCannotReact(): void
    {
        $empty = ReactionPolicy::fill([]);

        self::assertFalse(ReactionPolicy::worthShowing($empty, false));
        self::assertTrue(ReactionPolicy::worthShowing($empty, true));
        self::assertTrue(ReactionPolicy::worthShowing(ReactionPolicy::fill(['amen' => 1]), false));
    }

    /** Every kind has both a word and a picture; the word is what gets read. */
    public function testEveryKindHasALabelAndAnEmoji(): void
    {
        foreach (array_keys(ReactionPolicy::kinds()) as $kind) {
            self::assertNotSame('', ReactionPolicy::label($kind), $kind);
            self::assertNotSame('', ReactionPolicy::emoji($kind), $kind);
        }

        self::assertSame(count(ReactionPolicy::kinds()), ReactionPolicy::maxPerPerson());
    }
}
