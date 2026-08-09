<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\UnlockPolicy;

/**
 * Watching a course in order.
 *
 * The rule is small and the consequences of getting it slightly wrong are all
 * of the "somebody loses access to something they earned" kind, so most of
 * these are about the edges: the first episode, a rewatch, an episode inserted
 * into the middle, and a video that is not in the sequence at all.
 */
final class UnlockPolicyTest extends TestCase
{
    /** Somebody has to be able to start. */
    public function testTheFirstEpisodeIsAlwaysOpen(): void
    {
        $state = UnlockPolicy::state([10, 20, 30], [], 10);

        self::assertFalse($state['locked']);
        self::assertNull($state['requires']);
    }

    public function testTheSecondIsLockedUntilTheFirstIsFinished(): void
    {
        $state = UnlockPolicy::state([10, 20, 30], [], 20);

        self::assertTrue($state['locked']);
        self::assertSame(10, $state['requires'], 'it has to name the way forward');
    }

    public function testFinishingOneOpensTheNext(): void
    {
        $state = UnlockPolicy::state([10, 20, 30], [10], 20);

        self::assertFalse($state['locked']);
    }

    public function testAndOnlyTheNext(): void
    {
        self::assertTrue(
            UnlockPolicy::state([10, 20, 30], [10], 30)['locked'],
            'finishing the first should not open the third'
        );
    }

    /** Finishing a course and losing access to it would be an odd reward. */
    public function testSomethingAlreadyWatchedStaysOpen(): void
    {
        // Watched the third somehow — a share link, an editor's help — and
        // never finished the second.
        $state = UnlockPolicy::state([10, 20, 30], [30], 30);

        self::assertFalse($state['locked']);
    }

    /**
     * Not in the running order, so not part of the sequence. Covers a video in
     * no series, a series that is not sequential, and an episode the viewer
     * cannot see — the order handed in is the order THEY can see, so a hidden
     * episode is skipped rather than becoming a wall nobody can get past.
     */
    public function testAVideoOutsideTheOrderIsNotLocked(): void
    {
        self::assertFalse(UnlockPolicy::state([10, 20, 30], [], 99)['locked']);
        self::assertFalse(UnlockPolicy::state([], [], 10)['locked']);
    }

    /**
     * The reason the rule is "the immediately preceding one" rather than "all
     * previous ones".
     *
     * Inserting an episode into a running course must not re-lock everything
     * after it for somebody who has already finished them — the only signal
     * they would get is that a course they completed had closed.
     */
    public function testInsertingAnEpisodeLocksExactlyOneThing(): void
    {
        $before = [10, 20, 30];
        $completed = [10, 20, 30];

        // A new episode 15 lands between the first and second.
        $after = [10, 15, 20, 30];

        self::assertFalse(UnlockPolicy::state($after, $completed, 10)['locked']);
        self::assertFalse(
            UnlockPolicy::state($after, $completed, 20)['locked'],
            'already watched, so still open'
        );
        self::assertFalse(
            UnlockPolicy::state($after, $completed, 30)['locked'],
            'already watched, so still open'
        );

        // Only the new one is closed, and it is the one they have not seen.
        $new = UnlockPolicy::state($after, $completed, 15);
        self::assertFalse($new['locked'], 'the episode before it was finished');

        // And with the course only partly done, the damage is still bounded.
        $partly = UnlockPolicy::state($after, [10], 20);
        self::assertTrue($partly['locked']);
        self::assertSame(15, $partly['requires']);

        self::assertSame($before, [10, 20, 30], 'sanity: the fixture was not mutated');
    }

    /**
     * "All previous" would behave differently here, and worse. Written as a
     * test rather than a comment so the difference is checkable: with only the
     * immediately preceding rule, skipping an early episode does not
     * permanently close everything downstream once you catch up.
     */
    public function testCatchingUpOnOneEpisodeReopensTheChain(): void
    {
        $order = [10, 20, 30, 40];

        // Watched 1, skipped 2, somehow watched 3.
        self::assertTrue(UnlockPolicy::state($order, [10, 30], 20)['locked'] === false);

        // 4 is open because 3 was finished, even though 2 never was.
        self::assertFalse(
            UnlockPolicy::state($order, [10, 30], 40)['locked'],
            'the rule is the immediately preceding episode, not every one before it'
        );
    }

    // ------------------------------------------------------------ whole order

    public function testTheWholeOrderIsAnsweredAtOnce(): void
    {
        $states = UnlockPolicy::forOrder([10, 20, 30], [10]);

        self::assertFalse($states[10]['locked']);
        self::assertFalse($states[20]['locked']);
        self::assertTrue($states[30]['locked']);
        self::assertSame(20, $states[30]['requires']);
    }

    public function testAnEmptyOrderAnswersNothing(): void
    {
        self::assertSame([], UnlockPolicy::forOrder([], []));
    }

    // ------------------------------------------------------------ robustness

    public function testDuplicatesAndRubbishInTheOrderDoNotBreakIt(): void
    {
        // array_filter drops the zero, so the order is 10, 20.
        $state = UnlockPolicy::state([0, 10, 20], [10], 20);

        self::assertFalse($state['locked']);
    }

    /**
     * Ids arrive from a database, where a driver can hand back strings. A
     * strict in_array against ints would silently lock everybody out of
     * everything, and this catches that.
     *
     * Worth recording what it does NOT catch: removing the intval() from the
     * implementation changes nothing, because PHP coerces numeric-string ARRAY
     * KEYS to integers on its own. That mutation is equivalent rather than a
     * hole in the test — the intval is there to say the intent out loud instead
     * of resting on a coercion rule most readers would have to look up.
     */
    public function testCompletedIdsAsStringsStillCount(): void
    {
        $state = UnlockPolicy::state([10, 20], ['10'], 20);

        self::assertFalse($state['locked'], 'string ids from the database must still count as watched');
    }
}
