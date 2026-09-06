<?php

declare(strict_types=1);

namespace Portal\Rota;

/**
 * What happens when somebody is asked to serve, decided before any writing.
 *
 * Pure: no database, no request, no clock beyond the dates handed in. It exists
 * because the two rules this section turns on are easy to state and easy to get
 * subtly wrong, and both are about the DIFFERENCE between refusing and warning:
 *
 *   A person already on that service is REFUSED, in words.
 *   A person who marked themselves away is WARNED, and the ask goes ahead.
 *
 * The second is the one that needs defending, because the instinct is to
 * enforce it. A rota that argues with the person offering to help is a rota
 * nobody helps with — and the organiser very often knows something the calendar
 * does not: the blockout was for a holiday that got cancelled, or the person
 * said yes in the corridor an hour ago. Refusing would mean the builder either
 * gives up or goes and deletes somebody else's blockout to get past it, which
 * is worse than a warning nobody reads.
 *
 * The first genuinely is a refusal, and it is said in words rather than left to
 * the unique key. A caught constraint error reaches a person as "something went
 * wrong"; the true answer is "they are already down for this", which tells them
 * what to do next.
 */
final class AskOutcome
{
    public const ALLOWED = 'allowed';
    public const WARNED = 'warned';
    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $state,
        public readonly string $message = '',
    ) {
    }

    /**
     * Decide, given only facts the caller has already looked up.
     *
     * Taking booleans rather than doing the lookups is what makes this
     * testable without a database — and what makes the QUERIES testable
     * separately, which matters because a rule that is right and a query that
     * answers the wrong question look identical when they are the same method.
     *
     * @param bool $alreadyOnService is this person already asked for this service
     * @param list<string> $blockoutReasons why they said they cannot serve that day
     */
    public static function decide(
        bool $alreadyOnService,
        array $blockoutReasons,
        string $name = 'They'
    ): self {
        if ($alreadyOnService) {
            return new self(
                self::REFUSED,
                $name . ' is already on this service. Somebody can only be asked once for one '
                . 'occasion — change the existing ask instead.'
            );
        }

        if ($blockoutReasons === []) {
            return new self(self::ALLOWED);
        }

        /*
         * The reason is repeated back when there is one. "Away" is a fact the
         * builder already half knows; "Away — at my daughter's wedding" is the
         * one that stops them asking anyway.
         *
         * Blank reasons are dropped rather than rendered as empty quotes, and
         * if every reason is blank the warning still fires — the ABSENCE is the
         * warning, not the words.
         */
        $given = array_values(array_filter(
            array_map(static fn (string $r): string => trim($r), $blockoutReasons),
            static fn (string $r): bool => $r !== ''
        ));

        $message = $name . ' said they cannot serve that day';

        if ($given !== []) {
            $message .= ' (' . implode('; ', $given) . ')';
        }

        return new self(
            self::WARNED,
            $message . '. You can still ask — this is a note, not a rule.'
        );
    }

    /** Did anything stop this? Only a refusal does. */
    public function permitted(): bool
    {
        return $this->state !== self::REFUSED;
    }

    public function isWarning(): bool
    {
        return $this->state === self::WARNED;
    }
}
