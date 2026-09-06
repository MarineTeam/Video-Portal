<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Schedules\ReminderSchedule;

/**
 * When a rota reminder is due, and what it is allowed to say.
 *
 * Two rules carry this: the hour is wall clock where the SUBSCRIBER is, and a
 * date that has already been and gone is never mentioned.
 */
final class ReminderScheduleTest extends TestCase
{
    private function at(string $wall, string $zone): int
    {
        return (new \DateTimeImmutable($wall, new \DateTimeZone($zone)))->getTimestamp();
    }

    /**
     * "Six in the evening" is six where the person is.
     *
     * A reminder that arrives at two in the morning is one people turn off, so
     * the same hour setting is a different instant for two subscribers.
     */
    public function testTheHourIsWallClockWhereTheSubscriberIs(): void
    {
        $london = ReminderSchedule::dueAt('2026-09-06', ReminderSchedule::BEFORE, 18, 'Europe/London');
        $auckland = ReminderSchedule::dueAt('2026-09-06', ReminderSchedule::BEFORE, 18, 'Pacific/Auckland');

        self::assertSame($this->at('2026-09-05 18:00', 'Europe/London'), $london);
        self::assertSame($this->at('2026-09-05 18:00', 'Pacific/Auckland'), $auckland);
        self::assertNotSame($london, $auckland, 'the zone made no difference');
    }

    /** The day-before slot is the day before; the day-of slot is the day. */
    public function testTheTwoSlotsAreADayApart(): void
    {
        self::assertSame(
            $this->at('2026-09-05 07:00', 'Europe/London'),
            ReminderSchedule::dueAt('2026-09-06', ReminderSchedule::BEFORE, 7, 'Europe/London')
        );

        self::assertSame(
            $this->at('2026-09-06 07:00', 'Europe/London'),
            ReminderSchedule::dueAt('2026-09-06', ReminderSchedule::DAY_OF, 7, 'Europe/London')
        );
    }

    public function testNothingIsSentBeforeTheHourArrives(): void
    {
        $date = '2026-09-06';
        $zone = 'Europe/London';

        self::assertFalse(ReminderSchedule::isDue(
            $date,
            ReminderSchedule::BEFORE,
            18,
            $zone,
            $this->at('2026-09-05 17:59', $zone)
        ));

        self::assertTrue(ReminderSchedule::isDue(
            $date,
            ReminderSchedule::BEFORE,
            18,
            $zone,
            $this->at('2026-09-05 18:00', $zone)
        ));
    }

    /**
     * THE RULE. A site whose cron was broken for a fortnight must not, on the
     * day it is fixed, tell everybody about a rota they have already done.
     */
    public function testADateThatHasPassedIsNeverMentioned(): void
    {
        self::assertFalse(
            ReminderSchedule::isDue(
                '2026-09-06',
                ReminderSchedule::DAY_OF,
                7,
                'Europe/London',
                $this->at('2026-09-20 09:00', 'Europe/London')
            ),
            'it reminded somebody about a fortnight ago'
        );
    }

    /**
     * Late is normal, and still sent.
     *
     * Pseudo-cron only fires when somebody visits, so an evening reminder can
     * genuinely go out the following morning — and it is still worth having.
     */
    public function testALateReminderStillGoesOutWhileTheDayIsStillComing(): void
    {
        self::assertTrue(ReminderSchedule::isDue(
            '2026-09-06',
            ReminderSchedule::BEFORE,
            18,
            'Europe/London',
            $this->at('2026-09-06 08:00', 'Europe/London')
        ));
    }

    /**
     * And when it does, it says the right day.
     *
     * The wording comes from the DATE, not from the slot that fired. Otherwise
     * a late "you are on tomorrow" tells somebody the wrong thing on the
     * morning they are on.
     */
    public function testTheWordsComeFromTheDateAndNotTheSlot(): void
    {
        $zone = 'Europe/London';

        self::assertSame(
            ReminderSchedule::TOMORROW,
            ReminderSchedule::describe('2026-09-06', $zone, $this->at('2026-09-05 18:00', $zone))
        );

        // The same day-before reminder, running late.
        self::assertSame(
            ReminderSchedule::TODAY,
            ReminderSchedule::describe('2026-09-06', $zone, $this->at('2026-09-06 08:00', $zone))
        );

        self::assertSame(
            ReminderSchedule::PAST,
            ReminderSchedule::describe('2026-09-06', $zone, $this->at('2026-09-07 08:00', $zone))
        );
    }

    /**
     * Today is the subscriber's today.
     *
     * At eight in the evening in London it is already the next day in
     * Auckland, and a reminder that calls it the wrong day is worse than none.
     */
    public function testTodayIsTheSubscribersTodayAndNotTheServersDay(): void
    {
        $moment = $this->at('2026-09-05 20:00', 'Europe/London');

        self::assertSame(ReminderSchedule::TOMORROW, ReminderSchedule::describe('2026-09-06', 'Europe/London', $moment));
        self::assertSame(ReminderSchedule::TODAY, ReminderSchedule::describe('2026-09-06', 'Pacific/Auckland', $moment));
    }

    /**
     * An hour nobody could have meant becomes the default rather than
     * midnight, which is what an unchecked (int) cast of a bad field gives.
     */
    public function testAnImpossibleHourBecomesTheDefault(): void
    {
        self::assertSame(18, ReminderSchedule::hour(-1));
        self::assertSame(18, ReminderSchedule::hour(24));
        self::assertSame(0, ReminderSchedule::hour(0), 'midnight is a real choice');
        self::assertSame(23, ReminderSchedule::hour(23));
    }
}
