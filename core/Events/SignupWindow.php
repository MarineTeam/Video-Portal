<?php

declare(strict_types=1);

namespace Portal\Events;

/**
 * Whether sign-up is open, and why not when it is not.
 *
 * Pure. Every answer is a REASON rather than a boolean, because every one of
 * them is shown to somebody who wants to come and needs to know what to do
 * next: "not open until Monday" and "the hall is full" and "this event has
 * happened" lead to three different actions, and "you cannot sign up" leads to
 * none of them.
 */
final class SignupWindow
{
    public const OPEN = 'open';
    public const NOT_OFFERED = 'not_offered';
    public const NOT_YET = 'not_yet';
    public const CLOSED = 'closed';
    public const PAST = 'past';

    /**
     * @param array<string, mixed> $event
     * @param string $now wall clock, in the same terms as the stored columns
     */
    public static function state(array $event, string $now): string
    {
        if (empty($event['signup_enabled'])) {
            /*
             * Plenty of events are worth publishing with nothing to fill in —
             * a carol service does not need a list. This is the ordinary case,
             * not an error case.
             */
            return self::NOT_OFFERED;
        }

        $opens = self::stamp($event['signup_opens_at'] ?? null);
        $closes = self::stamp($event['signup_closes_at'] ?? null);
        $starts = self::stamp($event['starts_at'] ?? null);
        $moment = strtotime($now);

        if ($moment === false) {
            // An unreadable clock is not permission. Refusing is recoverable;
            // an overbooked hall is not.
            return self::CLOSED;
        }

        if ($opens !== null && $moment < $opens) {
            return self::NOT_YET;
        }

        if ($closes !== null && $moment > $closes) {
            return self::CLOSED;
        }

        /*
         * An event that has already started closes itself, even with no closing
         * time set — which is the usual arrangement, because almost nobody sets
         * one. Without this, last year's harvest supper keeps taking sign-ups
         * for ever and the list an organiser prints is half people who came in
         * 2019.
         *
         * The START, not the end: signing up while it is happening is not
         * signing up, it is turning up.
         */
        if ($starts !== null && $moment > $starts) {
            return self::PAST;
        }

        return self::OPEN;
    }

    /** What to tell somebody, in words they can act on. */
    public static function explain(string $state, ?string $opensAt = null): string
    {
        return match ($state) {
            self::OPEN => '',
            self::NOT_OFFERED => 'There is nothing to fill in for this one — just come.',
            self::NOT_YET => $opensAt === null || $opensAt === ''
                ? 'Sign-up is not open yet.'
                : 'Sign-up opens on ' . $opensAt . '.',
            self::PAST => 'This event has already happened.',
            default => 'Sign-up has closed for this one.',
        };
    }

    private static function stamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $stamp = strtotime($value);

        return $stamp === false ? null : $stamp;
    }
}
