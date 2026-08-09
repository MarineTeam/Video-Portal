<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Watching a course in order.
 *
 * Pure: a running order, a set of finished videos, and a target — locked or
 * not, and if locked, what has to be watched first.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 *
 * It is ORDERING, layered on top of an access decision that has already been
 * made. By the time anything here runs, the members-only rules, the authorized
 * flag and the publication window have all said this person may watch this
 * video. Sequential unlock only decides whether they may watch it YET.
 *
 * That is why it fails OPEN when something goes wrong, against the general
 * rule that access checks in this codebase fail closed. The two failures are
 * not comparable: failing closed on a genuine access check withholds something
 * secret, while failing closed here locks a whole course because a progress
 * query hiccuped, and the thing being withheld is the next episode of a series
 * the person is already entitled to. The boundary is underneath this, and it
 * still holds.
 *
 * It is still a real gate, not a hint — the embed URL is never minted for a
 * locked video, so there is nothing to find with developer tools. The failing
 * open is about ERRORS, not about the rule being advisory.
 */
final class UnlockPolicy
{
    /**
     * Is this video watchable yet?
     *
     * @param list<int> $order      the visible running order, first to last
     * @param list<int> $completed  video ids this viewer has finished
     * @return array{locked: bool, requires: ?int}
     */
    public static function state(array $order, array $completed, int $videoId): array
    {
        $order = array_values(array_filter(array_map('intval', $order)));
        $position = array_search($videoId, $order, true);

        /*
         * Not in the running order at all, so not part of the sequence. That
         * covers a video whose series is not sequential, and a video the viewer
         * cannot see — the order handed in is the order THEY can see, so an
         * episode hidden from them is skipped rather than becoming a wall they
         * can never get past.
         */
        if ($position === false) {
            return self::open();
        }

        // Somebody has to be able to start.
        if ($position === 0) {
            return self::open();
        }

        $finished = array_flip(array_map('intval', $completed));

        // Already watched, so watchable again. Locking a rewatch would mean
        // finishing a course and losing access to it.
        if (isset($finished[$videoId])) {
            return self::open();
        }

        /*
         * Only the IMMEDIATELY preceding episode, not everything before it.
         *
         * "All previous" sounds stricter and behaves worse: inserting an
         * episode into the middle of a running course would re-lock every
         * episode after it for somebody who had already finished them, and the
         * only signal they would get is that the course they completed has
         * closed. With this rule, inserting an episode locks exactly one thing
         * — the one right after it — which is recoverable in a single sitting
         * and is stated on the settings screen.
         */
        $previous = $order[$position - 1];

        return isset($finished[$previous])
            ? self::open()
            : ['locked' => true, 'requires' => $previous];
    }

    /**
     * The lock state of a whole running order at once.
     *
     * For the series page, which needs an answer per episode and must not ask
     * per episode — that is a query per row, the mistake the batched thumbnail
     * modes exist to avoid.
     *
     * @param  list<int> $order
     * @param  list<int> $completed
     * @return array<int, array{locked: bool, requires: ?int}> keyed by video id
     */
    public static function forOrder(array $order, array $completed): array
    {
        $states = [];

        foreach ($order as $videoId) {
            $states[(int) $videoId] = self::state($order, $completed, (int) $videoId);
        }

        return $states;
    }

    /** @return array{locked: bool, requires: ?int} */
    private static function open(): array
    {
        return ['locked' => false, 'requires' => null];
    }
}
