<?php

declare(strict_types=1);

namespace Portal\Live;

use Portal\Content\LiveStreamPolicy;

/**
 * When a stream's chat is open.
 *
 * Thirty minutes before it starts and sixty minutes after it ends, and both
 * numbers are doing work.
 *
 * BEFORE, because people arrive early and a room that opens at the same second
 * as the stream is a room nobody is in when it begins. Thirty minutes is enough
 * for the "can you hear it?" conversation that otherwise happens by text
 * message to somebody who is busy.
 *
 * AFTER, because the conversation a service produces does not stop when the
 * stream does, and cutting it off mid-sentence is the rudest thing a room can
 * do. Sixty rather than indefinitely for a reason that is about moderation, not
 * storage: a room open a week after the stream is one nobody is watching, which
 * is exactly where the thing a moderator would have removed sits unremoved.
 *
 * Derived from the schedule, never from a flag a job flips. Same reasoning as
 * LiveStreamPolicy and scheduled publishing: pseudo-cron fires only on traffic,
 * so a job-driven open would open late on a quiet morning or not at all, and a
 * comparison cannot be late.
 */
final class ChatWindow
{
    /** Open this long before the stream is due to start. */
    public const OPENS_MINUTES_BEFORE = 30;

    /** And this long after it ends. */
    public const CLOSES_MINUTES_AFTER = 60;

    public const OPEN = 'open';

    /** Not yet — there is a stream coming and the room is not open for it. */
    public const EARLY = 'early';

    /** Over. The stream finished more than an hour ago. */
    public const LATE = 'late';

    /**
     * Never, because there is no schedule.
     *
     * A stream made and never given a start time has no window that can be
     * computed, and guessing "now" would open a room for a stream that may be
     * weeks away. Distinct from EARLY so the page can say which — "chat opens
     * half an hour before" is wrong and confusing on a stream with no time.
     */
    public const UNSCHEDULED = 'unscheduled';

    /**
     * The state of a stream's chat.
     *
     * @param array<string, mixed> $stream a {live_streams} row
     * @param ?int                 $now    injected by the tests
     */
    public static function state(array $stream, ?int $now = null): string
    {
        $now ??= time();

        $opens = self::opensAt($stream);

        if ($opens === null) {
            return self::UNSCHEDULED;
        }

        if ($now < $opens) {
            return self::EARLY;
        }

        $closes = self::closesAt($stream);

        // A closing time is always computable once there is a start, because the
        // twelve-hour safety net below gives every stream an end. Guarded
        // anyway: a null here must not read as "open forever".
        if ($closes === null || $now >= $closes) {
            return self::LATE;
        }

        return self::OPEN;
    }

    /** @param array<string, mixed> $stream */
    public static function isOpen(array $stream, ?int $now = null): bool
    {
        return self::state($stream, $now) === self::OPEN;
    }

    /**
     * When the room opens, or null if the stream has no start time.
     *
     * @param array<string, mixed> $stream
     */
    public static function opensAt(array $stream): ?int
    {
        $start = self::timestamp($stream['starts_at'] ?? null);

        return $start === null
            ? null
            : $start - (self::OPENS_MINUTES_BEFORE * 60);
    }

    /**
     * When the room closes.
     *
     * An hour after the stream ENDS, and "ends" has three possible answers in a
     * deliberate order:
     *
     *   1. Somebody pressed the button that says it is over. That beats every
     *      schedule — a service that finished twenty minutes early should not
     *      leave the room open for the eighty minutes its plan implied.
     *   2. The planned end.
     *   3. Neither, so LiveStreamPolicy's twelve-hour safety net, which is the
     *      same number that stops a LIVE badge staying up for three weeks. The
     *      alternative for a stream with no end time is a room that never
     *      closes, which is the one state nobody is moderating.
     *
     * @param array<string, mixed> $stream
     */
    public static function closesAt(array $stream): ?int
    {
        $ended = self::timestamp($stream['ended_at'] ?? null);

        if ($ended !== null) {
            return $ended + (self::CLOSES_MINUTES_AFTER * 60);
        }

        $end = self::timestamp($stream['ends_at'] ?? null);

        if ($end !== null) {
            return $end + (self::CLOSES_MINUTES_AFTER * 60);
        }

        $start = self::timestamp($stream['starts_at'] ?? null);

        if ($start === null) {
            return null;
        }

        return $start
            + (LiveStreamPolicy::MAX_UNENDED_HOURS * 3600)
            + (self::CLOSES_MINUTES_AFTER * 60);
    }

    /**
     * What to tell somebody who cannot post, in their own terms.
     *
     * Each answer names the thing they would do next. "The chat is closed" is
     * true of three different situations and useful in none of them: one is
     * "come back later", one is "you have missed it", and one is "there is
     * nothing to wait for".
     */
    public static function explain(string $state): string
    {
        return match ($state) {
            self::OPEN  => '',
            self::EARLY => sprintf(
                'The chat opens %d minutes before the stream starts.',
                self::OPENS_MINUTES_BEFORE
            ),
            self::LATE => sprintf(
                'The chat closed %d minutes after the stream ended. You can still read it.',
                self::CLOSES_MINUTES_AFTER
            ),
            default => 'There is no time set for this stream yet, so the chat is not open.',
        };
    }

    private static function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }
}
