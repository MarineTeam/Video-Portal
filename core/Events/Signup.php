<?php

declare(strict_types=1);

namespace Portal\Events;

/**
 * The three states a sign-up can be in.
 *
 * Cancelled is kept rather than deleted, and that is load-bearing rather than
 * tidiness: the waiting list is walked in the order people joined it, and ids
 * carry that order. Deleting a cancellation would lose nothing visible today
 * and would make "who was next" unanswerable the first time somebody asked.
 */
final class Signup
{
    public const GOING = 'going';
    public const WAITING = 'waiting';
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATES = [self::GOING, self::WAITING, self::CANCELLED];

    /**
     * An unrecognised state reads as WAITING.
     *
     * The safe direction: somebody on the waiting list who should be going gets
     * a place when the queue next moves, where somebody counted as going who is
     * not takes a seat nobody sits in — and the organiser only finds out on the
     * night.
     */
    public static function normalize(string $state): string
    {
        $state = strtolower(trim($state));

        return in_array($state, self::STATES, true) ? $state : self::WAITING;
    }
}
