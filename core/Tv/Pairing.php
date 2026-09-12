<?php

declare(strict_types=1);

namespace Portal\Tv;

/**
 * What state a television's pairing is in.
 *
 * # EXPIRY IS CHECKED BEFORE APPROVAL, AND THAT ORDER IS THE RULE
 *
 * Write it the other way round — approved first, then expiry — and a pairing
 * that was approved and has since expired answers APPROVED, because the first
 * branch matches and returns. The television then collects a session from a
 * ten-minute credential that expired last Tuesday.
 *
 * That is not a hypothetical ordering: approved-then-expired is the NORMAL
 * shape of a row. Somebody approves a pairing, the television is switched off
 * before it polls, and the row sits there approved and stale. Every row that
 * matters to this rule is in exactly the state the wrong order gets wrong.
 *
 * The two are also genuinely independent facts: `approved_at` is about what a
 * person did and `expires_at` is about the clock, so neither implies anything
 * about the other and nothing in the schema stops them disagreeing.
 *
 * # AND CLAIMED IS CHECKED BEFORE APPROVED TOO
 *
 * For the same reason, one step along. A pairing is good for ONE sign-in; the
 * television keeps its device code in a config file where it stays for years.
 * A claimed pairing that still answered APPROVED would be a replayable session
 * for as long as the row existed.
 */
final class Pairing
{
    /** How long a person has to walk to their phone and type the code. */
    public const LIFETIME_SECONDS = 600;

    /**
     * How often the television may ask.
     *
     * RFC 8628 calls this `interval` and the television is expected to honour
     * it. Five seconds, which is a pairing completing within five seconds of
     * somebody pressing approve — fast enough to feel immediate in a room where
     * two people are watching the screen.
     */
    public const POLL_SECONDS = 5;

    /**
     * How many wrong codes one pairing tolerates.
     *
     * Low, because a pairing is not guessed by typing at it — it is found. Five
     * is enough for somebody misreading a character twice and still getting
     * there.
     */
    public const MAX_ATTEMPTS = 5;

    /** Waiting for somebody to type the code. */
    public const PENDING = 'pending';

    /** Somebody approved it and the television has not collected yet. */
    public const APPROVED = 'approved';

    /** Ten minutes went by. */
    public const EXPIRED = 'expired';

    /** Already used. A pairing is good for one sign-in. */
    public const CLAIMED = 'claimed';

    /** Too many wrong codes typed at it. */
    public const BLOCKED = 'blocked';

    /** No such pairing. */
    public const UNKNOWN = 'unknown';

    /**
     * The state of a pairing row.
     *
     * @param array<string, mixed>|null $row a {tv_pairings} row
     * @param ?int                      $now injected by the tests
     */
    public static function state(?array $row, ?int $now = null): string
    {
        if ($row === null) {
            return self::UNKNOWN;
        }

        $now ??= time();

        /*
         * CLAIMED FIRST. Already used outranks everything, including a fresh
         * expiry: a pairing that has been spent is spent whatever the clock
         * says.
         */
        if (($row['claimed_at'] ?? null) !== null) {
            return self::CLAIMED;
        }

        /*
         * EXPIRED SECOND, BEFORE APPROVAL. See the note on the class — this
         * single line is the rule, and putting the approval check above it is
         * a working feature with a permanent session in it.
         */
        $expires = self::timestamp($row['expires_at'] ?? null);

        if ($expires === null || $expires <= $now) {
            /*
             * An unreadable or absent expiry counts as EXPIRED, not as live.
             * The column is NOT NULL so this should not happen; fail closed
             * anyway, because the alternative reading of a broken timestamp is
             * a pairing that never expires.
             */
            return self::EXPIRED;
        }

        if ((int) ($row['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            return self::BLOCKED;
        }

        if (($row['approved_at'] ?? null) !== null && (int) ($row['approved_by'] ?? 0) > 0) {
            /*
             * Both, not either. An approved_at with no approved_by is a row
             * half-written by something that failed between two statements, and
             * honouring it would mean signing the television in as nobody —
             * which, depending on what reads user_id next, is either an error
             * or a session with no owner.
             */
            return self::APPROVED;
        }

        return self::PENDING;
    }

    /**
     * What to tell the television, in RFC 8628's words.
     *
     * The device is a program, so the strings are the ones the specification
     * names — `authorization_pending`, `expired_token`, `access_denied` — and
     * not prose. A television that got "Please wait a moment" would have to
     * string-match English to know whether to keep polling.
     */
    public static function deviceAnswer(string $state): string
    {
        return match ($state) {
            self::APPROVED => 'ok',
            self::PENDING  => 'authorization_pending',
            self::EXPIRED  => 'expired_token',
            // Claimed and blocked are both "stop asking, start again", which is
            // what access_denied means to a device. Distinct states here
            // because the APPROVAL screen tells a person which.
            default        => 'access_denied',
        };
    }

    /**
     * What to tell the person at the approval screen.
     *
     * Each answer names what they do next, because "that code did not work" is
     * true of five different situations and useful in none of them. The one
     * they will actually hit is EXPIRED — somebody walks away from the
     * television, comes back ten minutes later, and types a code that was
     * valid when they read it.
     */
    public static function explain(string $state): string
    {
        return match ($state) {
            self::PENDING  => '',
            self::APPROVED => 'That television has already been signed in.',
            self::EXPIRED  => 'That code has expired. Ask the television for a new one — '
                . 'they last ten minutes.',
            self::CLAIMED  => 'That code has already been used. The television will show a '
                . 'new one.',
            self::BLOCKED  => 'That code has been typed wrongly too many times. Ask the '
                . 'television for a new one.',
            default        => 'That code is not one of ours. Check the screen again — there '
                . 'are no letter Os or Is in it, so a round one is a zero and a tall one is a one.',
        };
    }

    /** Whether a pairing in this state can still be approved by somebody. */
    public static function isApprovable(string $state): bool
    {
        return $state === self::PENDING;
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
