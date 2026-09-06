<?php

declare(strict_types=1);

namespace Portal\Schedules;

use Portal\Events\WallClock;

/**
 * When a rota reminder is due, and what it is allowed to say.
 *
 * # THE HOUR IS WALL CLOCK IN THE SUBSCRIBER'S OWN ZONE
 *
 * "Six in the evening" is six where the person is, not six where the server is
 * — a rota reminder that arrives at two in the morning is one people turn off.
 * So the moment is built as wall clock and turned into an instant through the
 * same WallClock the events section uses, which is the one place in this
 * codebase that knows what to do with the hour that happens twice in October
 * and the one that does not exist in March.
 *
 * # THE MESSAGE SAYS WHEN IT ACTUALLY IS
 *
 * Not which reminder produced it. Scheduled work on this product's hosts runs
 * late — pseudo-cron only fires when somebody visits — so an evening
 * "you are on tomorrow" can genuinely go out the following morning. Deriving
 * the wording from the slot rather than from the date would then tell somebody
 * they are on tomorrow on the day they are on, which is worse than being late.
 *
 * And a reminder for a date that has ALREADY PASSED is never sent. A site whose
 * cron was broken for a fortnight must not, on the day it is fixed, tell
 * everybody about a rota they have already done or already missed.
 */
final class ReminderSchedule
{
    /** The evening before. */
    public const BEFORE = 'before';

    /** The morning of. */
    public const DAY_OF = 'day';

    public const TODAY = 'today';
    public const TOMORROW = 'tomorrow';
    public const LATER = 'later';
    public const PAST = 'past';

    /**
     * The instant a reminder of this kind, for this date, becomes due.
     *
     * The day-before reminder is at the chosen hour on the day before; the
     * day-of one is at the same hour on the day itself. One hour setting rather
     * than two, because somebody who wants an evening nudge and a morning one
     * is describing two habits and a rota is not worth two forms.
     */
    public static function dueAt(string $date, string $kind, int $hour, string $timezone): ?int
    {
        $day = strtotime($date . ($kind === self::BEFORE ? ' -1 day' : ''));

        if ($day === false) {
            return null;
        }

        $wall = date('Y-m-d', $day) . sprintf(' %02d:00:00', self::hour($hour));

        return WallClock::toInstant($wall, $timezone);
    }

    /**
     * Where this date sits relative to the subscriber's own today.
     *
     * Their today, not the server's: at eight in the evening in London it is
     * already tomorrow in Auckland, and a reminder that calls it the wrong day
     * is worse than no reminder.
     */
    public static function describe(string $date, string $timezone, int $now): string
    {
        $zone = WallClock::zone($timezone);
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d');
        $on = date('Y-m-d', (int) strtotime($date));

        return match (true) {
            $on === $today => self::TODAY,
            $on < $today   => self::PAST,
            $on === date('Y-m-d', (int) strtotime($today . ' +1 day')) => self::TOMORROW,
            default        => self::LATER,
        };
    }

    /**
     * Whether to send now.
     *
     * Two conditions, and both are load-bearing: the moment has arrived, and
     * the date has not been and gone.
     */
    public static function isDue(
        string $date,
        string $kind,
        int $hour,
        string $timezone,
        int $now
    ): bool {
        if (self::describe($date, $timezone, $now) === self::PAST) {
            return false;
        }

        $due = self::dueAt($date, $kind, $hour, $timezone);

        return $due !== null && $now >= $due;
    }

    /** An hour a person could have meant. Anything else is the default. */
    public static function hour(int $hour): int
    {
        return $hour >= 0 && $hour <= 23 ? $hour : 18;
    }
}
