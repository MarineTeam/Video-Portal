<?php

declare(strict_types=1);

namespace Portal\Events;

/**
 * Who moves up when places come free.
 *
 * Pure: a number of free places and a queue in, a list of ids out. No database,
 * no clock. It is separated from the repository because it is one rule that is
 * easy to state, easy to get wrong in a way nobody notices, and impossible to
 * test convincingly while it is tangled up in a transaction.
 *
 * # THE RULE: STOP AT THE FIRST PARTY THAT DOES NOT FIT
 *
 * Two places come free and a family of four is at the front of the queue.
 * Nobody moves.
 *
 * The tempting version walks past them to the couple behind, who do fit, and
 * seats them — and it is wrong for a reason that has nothing to do with
 * arithmetic. Passing over that family is exactly what people notice and
 * resent: they were told they were next, they watched somebody who joined after
 * them get in, and no explanation covers it. A waiting list that can be
 * overtaken is not a waiting list.
 *
 * It also compounds. A family of four on a list that keeps seating couples
 * never moves at all, however many places come free, because places arrive two
 * at a time. The queue is not slow for them; it is closed.
 *
 * So the walk stops. Two free places and a four sitting at the front means two
 * places stay empty until either the four cancels or four come free — which is
 * the honest outcome, and the organiser can see it and ring somebody.
 */
final class WaitingList
{
    /**
     * The ids to promote, in order, stopping at the first that does not fit.
     *
     * @param int $freePlaces how many places are going spare
     * @param list<array{id: int, party_size: int}> $queue in the order they joined
     * @return list<int>
     */
    public static function promote(int $freePlaces, array $queue): array
    {
        if ($freePlaces < 1) {
            return [];
        }

        $moving = [];

        foreach ($queue as $entry) {
            $party = max(1, (int) ($entry['party_size'] ?? 1));

            if ($party > $freePlaces) {
                /*
                 * BREAK, NOT CONTINUE. This one keyword is the rule. `continue`
                 * would skip this party and look at the next, which is the
                 * overtaking the whole class exists to prevent — and every test
                 * of the happy path passes either way, because with one person
                 * per party the two behave identically.
                 */
                break;
            }

            $moving[] = (int) $entry['id'];
            $freePlaces -= $party;

            if ($freePlaces < 1) {
                break;
            }
        }

        return $moving;
    }

    /**
     * How many places are left, given a capacity and what is taken.
     *
     * Null capacity means no limit, which is not the same as zero: zero is a
     * real answer meaning nobody may sign up, and a caller that conflated them
     * could not tell a full event from an open one. Callers asking about an
     * unlimited event get PHP_INT_MAX rather than null, so the arithmetic above
     * has no special case to forget.
     */
    public static function freePlaces(?int $capacity, int $taken): int
    {
        if ($capacity === null) {
            return PHP_INT_MAX;
        }

        return max(0, $capacity - $taken);
    }
}
