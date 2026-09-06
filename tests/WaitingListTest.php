<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Events\SignupWindow;
use Portal\Events\WaitingList;

/**
 * Who moves up, and when sign-up is open.
 *
 * The build order says to write the pure pieces first and test them alone, and
 * the waiting-list rule is why: it is one keyword, every happy-path test passes
 * whichever keyword is there, and the difference only shows up in a fixture
 * built specifically to expose it.
 */
final class WaitingListTest extends TestCase
{
    /** @return list<array{id: int, party_size: int}> */
    private static function queue(int ...$partySizes): array
    {
        $queue = [];

        foreach ($partySizes as $i => $size) {
            $queue[] = ['id' => $i + 1, 'party_size' => $size];
        }

        return $queue;
    }

    // ------------------------------------------------------------ THE RULE

    /**
     * THE RULE: the walk stops at the first party that does not fit.
     *
     * Two places free, a family of four at the front, a couple behind them.
     * NOBODY MOVES. Passing over the family to seat the couple is exactly what
     * people notice and resent — they were told they were next, and they watch
     * somebody who joined later get in.
     *
     * This is the fixture that separates `break` from `continue`. With one
     * person per party the two are identical, which is why every ordinary test
     * of this passes either way.
     */
    public function testAPartyThatDoesNotFitStopsTheQueue(): void
    {
        self::assertSame(
            [],
            WaitingList::promote(2, self::queue(4, 2)),
            'THE COUPLE OVERTOOK THE FAMILY — a waiting list that can be overtaken is not one'
        );
    }

    /** And it stops midway, not only at the front. */
    public function testTheQueueStopsWhereverTheBigPartyIs(): void
    {
        // Five free: the single and the pair fit (3), then a party of four does
        // not, and the two behind it must not be pulled forward.
        self::assertSame(
            [1, 2],
            WaitingList::promote(5, self::queue(1, 2, 4, 1, 1))
        );
    }

    /**
     * The compounding case, which is the real damage.
     *
     * Places arrive two at a time on a list of couples. A family of four is not
     * merely slow to move — it never moves at all, however many places come
     * free, because each release is spent on whoever is behind them.
     */
    public function testAFamilyIsNotStrandedForeverByCouplesBehindThem(): void
    {
        $queue = self::queue(4, 2, 2, 2);

        // Three separate releases of two places each. With `continue` the three
        // couples all get in and the family is still waiting.
        self::assertSame([], WaitingList::promote(2, $queue));
        self::assertSame([], WaitingList::promote(2, $queue));
        self::assertSame([], WaitingList::promote(2, $queue));

        // Four free and they go, ahead of everybody behind them.
        self::assertSame([1], WaitingList::promote(4, $queue));
    }

    // ------------------------------------------------------ ordinary cases

    public function testEverybodyWhoFitsMovesUp(): void
    {
        self::assertSame([1, 2, 3], WaitingList::promote(5, self::queue(1, 2, 2)));
    }

    public function testItStopsWhenThePlacesRunOut(): void
    {
        self::assertSame([1, 2], WaitingList::promote(3, self::queue(1, 2, 1)));
    }

    public function testNoFreePlacesMovesNobody(): void
    {
        self::assertSame([], WaitingList::promote(0, self::queue(1, 1)));
        self::assertSame([], WaitingList::promote(-3, self::queue(1)));
    }

    public function testAnEmptyQueueIsFine(): void
    {
        self::assertSame([], WaitingList::promote(10, []));
    }

    /** A missing or absurd party size counts as one person, never as none. */
    public function testAMissingPartySizeCountsAsOnePerson(): void
    {
        self::assertSame([1], WaitingList::promote(1, [['id' => 1, 'party_size' => 0]]));
    }

    // -------------------------------------------------------------- places

    /**
     * Null capacity is unlimited, and zero is a real answer.
     *
     * Conflating them would make a full event and an open one the same value,
     * and the schema could not tell "nobody may sign up" from "no limit".
     */
    public function testUnlimitedIsNotZero(): void
    {
        self::assertSame(PHP_INT_MAX, WaitingList::freePlaces(null, 500));
        self::assertSame(0, WaitingList::freePlaces(0, 0));
    }

    public function testFreePlacesNeverGoesNegative(): void
    {
        // An event can be over capacity — somebody raised it, filled it, then
        // lowered it again — and the answer is nought rather than a negative
        // that would let arithmetic elsewhere seat people.
        self::assertSame(0, WaitingList::freePlaces(10, 14));
    }

    // ------------------------------------------------------------- windows

    public function testAnEventWithNoSignupSaysSoRatherThanRefusing(): void
    {
        self::assertSame(
            SignupWindow::NOT_OFFERED,
            SignupWindow::state(['signup_enabled' => 0], '2026-01-01 10:00:00')
        );
    }

    public function testTheWindowOpensAndCloses(): void
    {
        $event = [
            'signup_enabled'   => 1,
            'signup_opens_at'  => '2026-03-01 09:00:00',
            'signup_closes_at' => '2026-03-20 17:00:00',
            'starts_at'        => '2026-03-25 19:30:00',
        ];

        self::assertSame(SignupWindow::NOT_YET, SignupWindow::state($event, '2026-02-28 09:00:00'));
        self::assertSame(SignupWindow::OPEN, SignupWindow::state($event, '2026-03-10 09:00:00'));
        self::assertSame(SignupWindow::CLOSED, SignupWindow::state($event, '2026-03-21 09:00:00'));
    }

    /**
     * An event that has started closes itself, with no closing time set.
     *
     * Almost nobody sets one, so without this last year's harvest supper takes
     * sign-ups for ever and the list an organiser prints is half people who
     * came in 2019.
     */
    public function testAnEventThatHasStartedTakesNoMoreSignups(): void
    {
        $event = ['signup_enabled' => 1, 'starts_at' => '2026-03-25 19:30:00'];

        self::assertSame(SignupWindow::OPEN, SignupWindow::state($event, '2026-03-25 19:00:00'));
        self::assertSame(SignupWindow::PAST, SignupWindow::state($event, '2026-03-25 20:00:00'));
    }

    /** An unreadable clock is not permission. */
    public function testAnUnreadableNowIsTreatedAsClosed(): void
    {
        self::assertSame(
            SignupWindow::CLOSED,
            SignupWindow::state(['signup_enabled' => 1], 'not a time at all')
        );
    }

    /** Every refusal says what to do next. */
    public function testEveryRefusalIsSomethingSomebodyCanActOn(): void
    {
        self::assertStringContainsString('opens on Monday', SignupWindow::explain(SignupWindow::NOT_YET, 'Monday'));
        self::assertStringContainsString('already happened', SignupWindow::explain(SignupWindow::PAST));
        self::assertStringContainsString('closed', SignupWindow::explain(SignupWindow::CLOSED));
        self::assertStringContainsString('just come', SignupWindow::explain(SignupWindow::NOT_OFFERED));
        self::assertSame('', SignupWindow::explain(SignupWindow::OPEN));
    }
}
