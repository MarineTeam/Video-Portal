<?php

declare(strict_types=1);

namespace Portal\Events;

/**
 * What happened when somebody signed up.
 *
 * A state rather than a boolean, because "you are on the waiting list" is not a
 * failure and must not be reported as one. Somebody told "that did not work"
 * fills the form in again; somebody told they are third in the queue knows
 * where they stand and waits.
 */
final class SignupResult
{
    public function __construct(
        public readonly string $state,
        public readonly int $id,
        public readonly int $partySize,
    ) {
    }

    public function isGoing(): bool
    {
        return $this->state === Signup::GOING;
    }

    /** What to tell them, counting the guests they brought. */
    public function message(): string
    {
        $party = $this->partySize > 1
            ? sprintf(' All %d of you are down.', $this->partySize)
            : '';

        return $this->isGoing()
            ? 'You are down for it.' . $party
            : 'It is full, so you are on the waiting list. We will let you know if a place comes '
                . 'free — you do not need to do anything.';
    }
}
