<?php

declare(strict_types=1);

namespace Portal\Live;

/**
 * How long somebody has to wait before their next message.
 *
 * SLOW MODE IS PER PERSON, NOT PER CHAT.
 *
 * The distinction is the whole class, so it is worth stating what the other
 * reading would do. A per-CHAT slow mode — one message every five seconds in
 * the room — means the fastest typist in a hundred people holds the floor and
 * everybody else's message is refused by somebody else's timing. It gets worse
 * the more people arrive, which is backwards: the busier the room, the more
 * often an ordinary person is told to wait for a reason they cannot see.
 *
 * Per person, the limit is a fact about your own last message and nobody
 * else's. It throttles the one participant typing continuously and is invisible
 * to everybody having a conversation at a human pace, which is the only
 * behaviour it is meant to change.
 *
 * It is not a defence against a determined abuser — that is what a mute is for.
 * It is a defence against enthusiasm, and against the accidental double-post
 * that happens when a slow connection makes somebody press send twice.
 */
final class SlowMode
{
    /**
     * Seconds between one person's messages, by default.
     *
     * Five, because it is long enough to stop a line of one-word messages
     * scrolling the room and short enough that two people talking to each other
     * never notice it. Configurable per site; nothing here assumes the value.
     */
    public const DEFAULT_SECONDS = 5;

    /**
     * The longest a site may set.
     *
     * A cap, because a slow mode of ten minutes is not a slow chat — it is a
     * closed one that says "send" and then refuses, which is worse than no chat
     * at all. Somebody who wants that wants the room closed, and can close it.
     */
    public const MAX_SECONDS = 120;

    /**
     * Seconds this person must still wait, or 0 if they may post now.
     *
     * @param ?string $lastPostedAt when they last said something; null means never
     * @param ?int    $now          injected by the tests
     */
    public static function waitFor(?string $lastPostedAt, int $seconds, ?int $now = null): int
    {
        $seconds = self::clamp($seconds);

        if ($seconds === 0 || $lastPostedAt === null) {
            return 0;
        }

        $last = strtotime($lastPostedAt);

        if ($last === false) {
            /*
             * An unreadable timestamp means the wait cannot be computed, and
             * this fails OPEN — it lets the message through.
             *
             * Deliberately against this codebase's fail-closed rule for access
             * checks, and for the reason sequential unlock gives: slow mode is
             * pacing layered on a decision already made. The person is signed
             * in, authorized, not muted and inside the window; every boundary
             * that matters has already said yes. Refusing them here on a
             * malformed DATETIME would be silencing somebody over a data
             * glitch, and the worst case of allowing it is one message arriving
             * a few seconds early.
             */
            return 0;
        }

        $ready = $last + $seconds;
        $now ??= time();

        if ($ready <= $now) {
            return 0;
        }

        /*
         * CAPPED AT THE INTERVAL, which is not the obvious arithmetic and is
         * the important line in this class.
         *
         * A stored timestamp LATER than now can only mean the clocks
         * disagree — the database's and PHP's, or the host's and reality's —
         * and that is not hypothetical here: this project already had to widen
         * the OIDC clock-skew allowance to 120 seconds because DreamHost's
         * clock ran behind. Without the cap, ten minutes of skew silences
         * somebody for ten minutes, with a Send button that keeps saying "wait
         * 600 more seconds" and no way for them to find out why.
         *
         * Nobody can legitimately owe more than one interval, so that is the
         * most this ever asks for.
         */
        return min($ready - $now, $seconds);
    }

    /** Whether this person may post right now. */
    public static function allows(?string $lastPostedAt, int $seconds, ?int $now = null): bool
    {
        return self::waitFor($lastPostedAt, $seconds, $now) === 0;
    }

    /**
     * A setting somebody typed, made safe.
     *
     * Zero is meaningful and kept: it turns slow mode off, which is what a small
     * congregation streaming to thirty people should have. Negative is nonsense
     * and becomes zero rather than an error, because there is no useful thing to
     * tell somebody who typed -5 in a seconds box that "off" does not already
     * say.
     */
    public static function clamp(int $seconds): int
    {
        if ($seconds < 0) {
            return 0;
        }

        return min($seconds, self::MAX_SECONDS);
    }

    /** What to tell somebody who has to wait. */
    public static function explain(int $wait): string
    {
        if ($wait <= 0) {
            return '';
        }

        return $wait === 1
            ? 'One more second before your next message.'
            : sprintf('%d more seconds before your next message.', $wait);
    }
}
