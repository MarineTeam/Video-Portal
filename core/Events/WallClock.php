<?php

declare(strict_types=1);

namespace Portal\Events;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * A wall clock and a zone, turned into an instant — the two mornings a year
 * when that is not a simple question.
 *
 * # WHY THE STORED VALUE IS A WALL CLOCK
 *
 * 19:30 is 19:30 in January and in July, and those are two different offsets
 * from UTC. A column holding an instant would read 19:30 in one and 18:30 in
 * the other, and the week the clocks change is the week somebody turns up an
 * hour early to a service they have attended for twenty years.
 *
 * So the wall clock is what is stored and this is where it becomes an instant,
 * at the point where one is genuinely needed: a calendar feed, a reminder, a
 * comparison against now.
 *
 * # THE TWO AWKWARD MORNINGS
 *
 * SPRING FORWARD. 01:30 does not exist on the morning the clocks go forward.
 * The rule is that it resolves FORWARD — to 02:30 — because an event written
 * for the small hours of that morning was written before anybody thought about
 * it, and moving it later is the answer that keeps it on the right day.
 *
 * AUTUMN. 01:30 happens twice on the morning the clocks go back. The rule is
 * that it resolves to the FIRST — and PHP does not do this. Measured rather
 * than assumed: `new DateTimeImmutable('2026-10-25 01:30', 'Europe/London')`
 * returns the SECOND occurrence, at +00:00, an hour after the first. A calendar
 * feed built on that would put the service an hour later than the one people
 * attend, once a year, on the one morning when everybody is already unsure what
 * time it is.
 *
 * The fix is not a special case for one zone or a one-hour assumption. Both
 * candidate offsets in play near the moment are tried, the ones that round-trip
 * back to the same wall clock are kept, and the earliest is taken. That is
 * correct for the half-hour shift Lord Howe Island uses as well as the ordinary
 * hour, and it needs no list of zones.
 */
final class WallClock
{
    /**
     * The instant a wall clock names in a zone.
     *
     * @param string $wall  "2026-10-25 01:30:00", as stored
     * @param string $zone  an IANA name; an unknown one falls back to the site's
     * @return int a Unix timestamp
     */
    public static function toInstant(string $wall, string $zone, string $fallbackZone = 'UTC'): int
    {
        $tz = self::zone($zone, $fallbackZone);
        $wall = self::normalize($wall);

        $phpAnswer = self::construct($wall, $tz);

        /*
         * What the wall clock would be if it were UTC. Not an answer — it is
         * the arithmetic base for trying each offset.
         */
        $asUtc = self::construct($wall, new DateTimeZone('UTC'));

        if ($asUtc === null) {
            return $phpAnswer?->getTimestamp() ?? time();
        }

        $valid = [];

        foreach (self::offsetsNear($tz, $asUtc->getTimestamp()) as $offset) {
            $instant = $asUtc->getTimestamp() - $offset;

            $check = (new DateTimeImmutable('@' . $instant))->setTimezone($tz);

            if ($check->format('Y-m-d H:i:s') === $wall) {
                $valid[$instant] = $instant;
            }
        }

        if ($valid !== []) {
            /*
             * One match is the ordinary case. Two means the wall clock happens
             * twice, and THE FIRST IS THE ANSWER — the earlier instant, which
             * is the one still on the pre-transition offset.
             */
            return min($valid);
        }

        /*
         * No match at all: the wall clock does not exist, which is the
         * spring-forward gap. PHP's own constructor shifts it forward, which is
         * the rule, so its answer is taken here rather than recomputed.
         */
        return $phpAnswer?->getTimestamp() ?? $asUtc->getTimestamp();
    }

    /**
     * Whether a wall clock is one of the awkward ones, for a screen that wants
     * to say so.
     *
     * @return 'ok'|'skipped'|'twice'
     */
    public static function oddity(string $wall, string $zone, string $fallbackZone = 'UTC'): string
    {
        $tz = self::zone($zone, $fallbackZone);
        $wall = self::normalize($wall);
        $asUtc = self::construct($wall, new DateTimeZone('UTC'));

        if ($asUtc === null) {
            return 'ok';
        }

        $matches = 0;

        foreach (self::offsetsNear($tz, $asUtc->getTimestamp()) as $offset) {
            $instant = $asUtc->getTimestamp() - $offset;
            $check = (new DateTimeImmutable('@' . $instant))->setTimezone($tz);

            if ($check->format('Y-m-d H:i:s') === $wall) {
                $matches++;
            }
        }

        return match (true) {
            $matches === 0 => 'skipped',
            $matches > 1   => 'twice',
            default        => 'ok',
        };
    }

    /**
     * The offsets in play around a moment.
     *
     * Every transition within a day either side, plus the offset the zone is
     * using at the moment itself. A day is generous — transitions are at most
     * one per few months — and being generous here costs one array and removes
     * any question about which side of midnight the change falls on.
     *
     * @return list<int> seconds, deduplicated
     */
    private static function offsetsNear(DateTimeZone $tz, int $around): array
    {
        $offsets = [];

        try {
            $transitions = $tz->getTransitions($around - 172800, $around + 172800);
        } catch (Throwable) {
            $transitions = [];
        }

        foreach ($transitions as $transition) {
            $offsets[] = (int) $transition['offset'];
        }

        $offsets[] = $tz->getOffset(new DateTimeImmutable('@' . $around));

        return array_values(array_unique($offsets));
    }

    private static function construct(string $wall, DateTimeZone $tz): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($wall, $tz);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A zone, or the site's, or UTC.
     *
     * Falls back rather than throwing. A stored zone that a later PHP no longer
     * recognises — they are renamed occasionally — must not make an event
     * unopenable; showing it in the site's own zone is wrong by an hour at
     * worst, where an exception is a page nobody can see.
     */
    public static function zone(string $zone, string $fallbackZone = 'UTC'): DateTimeZone
    {
        foreach ([trim($zone), trim($fallbackZone), 'UTC'] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            try {
                return new DateTimeZone($candidate);
            } catch (Throwable) {
                continue;
            }
        }

        return new DateTimeZone('UTC');
    }

    private static function normalize(string $wall): string
    {
        $wall = trim(str_replace('T', ' ', $wall));
        $stamp = strtotime($wall);

        return $stamp === false ? $wall : date('Y-m-d H:i:s', $stamp);
    }
}
