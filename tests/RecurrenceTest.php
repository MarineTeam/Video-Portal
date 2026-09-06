<?php

declare(strict_types=1);

namespace Portal\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Portal\Events\Recurrence;
use Portal\Events\WallClock;
use Portal\Http\HttpException;

/**
 * Repeating rules, and the two mornings a year when a wall clock is not a
 * simple question.
 *
 * The build order says to write these pure and test them alone before anything
 * depends on them, and it is right: a recurrence bug produces a plausible
 * schedule that is wrong, and the dates it puts out are meetings people either
 * attend or miss.
 */
final class RecurrenceTest extends TestCase
{
    /** @return list<string> the dates only, for readable assertions */
    private static function days(string $rule, string $start, string $horizon): array
    {
        return array_map(
            static fn (string $wall): string => substr($wall, 0, 10),
            Recurrence::parse($rule)->dates($start, $horizon)
        );
    }

    // ------------------------------------------------------- what it refuses

    /**
     * THE RULE: anything outside the subset is refused when it is saved.
     *
     * Not ignored. A rule carrying BYSETPOS that is silently dropped produces a
     * series that looks plausible and is wrong on a schedule nobody checks
     * against what they typed — a year of wrong Sundays, where an error at the
     * moment somebody presses save is a person fixing a rule.
     */
    public function testAPartItDoesNotUnderstandIsRefusedByName(): void
    {
        foreach (['BYSETPOS=-1', 'BYWEEKNO=3', 'BYYEARDAY=100', 'WKST=SU', 'BYHOUR=9', 'BYMONTH=3'] as $part) {
            try {
                Recurrence::parse('FREQ=WEEKLY;' . $part);
                self::fail("accepted {$part}");
            } catch (HttpException $e) {
                $name = explode('=', $part)[0];
                self::assertStringContainsString($name, $e->getMessage(), "did not name {$name}");
            }
        }
    }

    public function testARuleWithoutAUsableFreqIsRefused(): void
    {
        foreach (['', 'INTERVAL=2', 'FREQ=HOURLY', 'FREQ=SECONDLY', 'nonsense'] as $rule) {

            try {
                Recurrence::parse($rule);
                self::fail("accepted “{$rule}”");
            } catch (HttpException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAnAbsurdIntervalIsRefused(): void
    {
        foreach (['FREQ=DAILY;INTERVAL=0', 'FREQ=DAILY;INTERVAL=-2', 'FREQ=DAILY;INTERVAL=5000'] as $rule) {
            try {
                Recurrence::parse($rule);
                self::fail("accepted {$rule}");
            } catch (HttpException $e) {
                self::assertStringContainsString('INTERVAL', $e->getMessage());
            }
        }
    }

    /**
     * COUNT and UNTIL together is refused, as RFC 5545 also says.
     *
     * Not a stricter rule but two rules that can disagree — and nobody reading
     * the series afterwards could say which one ended it.
     */
    public function testCountAndUntilTogetherIsRefused(): void
    {
        $this->expectException(HttpException::class);
        Recurrence::parse('FREQ=WEEKLY;COUNT=5;UNTIL=20261231');
    }

    /** An ordinal day only means something monthly. */
    public function testAnOrdinalDayIsRefusedWithWeekly(): void
    {
        try {
            Recurrence::parse('FREQ=WEEKLY;BYDAY=2TU');
            self::fail('accepted "the second Tuesday, weekly"');
        } catch (HttpException $e) {
            self::assertStringContainsString('MONTHLY', $e->getMessage());
        }
    }

    /**
     * BYDAY and BYMONTHDAY are refused with YEARLY rather than approximated.
     *
     * "The second Tuesday of the month, yearly" is a question this does not
     * answer, and answering it approximately would be worse than saying so.
     */
    public function testTheYearlyCombinationsItCannotDoAreRefused(): void
    {
        $this->expectException(HttpException::class);
        Recurrence::parse('FREQ=YEARLY;BYDAY=1SU');
    }

    public function testBadDaysAndMonthDaysAreRefused(): void
    {
        foreach (['FREQ=WEEKLY;BYDAY=XX', 'FREQ=MONTHLY;BYMONTHDAY=0', 'FREQ=MONTHLY;BYMONTHDAY=45'] as $rule) {

            try {
                Recurrence::parse($rule);
                self::fail("accepted {$rule}");
            } catch (HttpException) {
                self::assertTrue(true);
            }
        }
    }

    /** The RRULE: prefix a calendar file writes is accepted and stripped. */
    public function testTheCalendarFilePrefixIsAccepted(): void
    {
        self::assertSame(Recurrence::WEEKLY, Recurrence::parse('RRULE:FREQ=WEEKLY')->freq);
    }

    // ---------------------------------------------------------- what it does

    public function testWeeklyRepeatsTheStartsOwnDay(): void
    {
        self::assertSame(
            ['2026-01-04', '2026-01-11', '2026-01-18', '2026-01-25'],
            self::days('FREQ=WEEKLY', '2026-01-04 10:30:00', '2026-01-31')
        );
    }

    public function testByDayNamesTheDaysOfTheWeek(): void
    {
        self::assertSame(
            ['2026-01-06', '2026-01-08', '2026-01-13', '2026-01-15'],
            self::days('FREQ=WEEKLY;BYDAY=TU,TH', '2026-01-05 19:30:00', '2026-01-17')
        );
    }

    public function testAnIntervalSkipsWeeks(): void
    {
        self::assertSame(
            ['2026-01-04', '2026-01-18', '2026-02-01'],
            self::days('FREQ=WEEKLY;INTERVAL=2;BYDAY=SU', '2026-01-04 10:30:00', '2026-02-07')
        );
    }

    /** Sunday belongs to the end of its week, not the start of the next one. */
    public function testSundayIsNotPulledIntoTheWrongWeek(): void
    {
        self::assertSame(
            ['2026-01-04', '2026-01-11'],
            self::days('FREQ=WEEKLY;BYDAY=SU', '2026-01-04 10:30:00', '2026-01-14')
        );
    }

    public function testTheFirstSundayOfEachMonth(): void
    {
        self::assertSame(
            ['2026-01-04', '2026-02-01', '2026-03-01', '2026-04-05'],
            self::days('FREQ=MONTHLY;BYDAY=1SU', '2026-01-01 10:00:00', '2026-04-30')
        );
    }

    public function testTheLastSaturdayOfEachMonth(): void
    {
        self::assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-28', '2026-04-25'],
            self::days('FREQ=MONTHLY;BYDAY=-1SA', '2026-01-01 10:00:00', '2026-04-30')
        );
    }

    /**
     * A month without a 31st has no meeting, rather than one on the 1st.
     *
     * RFC behaviour and the right one: "the 31st, monthly" means seven meetings
     * a year. Rolling into the following month would put a meeting on a day
     * nobody chose, and it would be a different day of the week every time.
     */
    public function testAMonthWithoutTheDayIsSkippedRatherThanRolledForward(): void
    {
        self::assertSame(
            ['2026-01-31', '2026-03-31', '2026-05-31', '2026-07-31'],
            self::days('FREQ=MONTHLY;BYMONTHDAY=31', '2026-01-31 10:00:00', '2026-08-01')
        );
    }

    public function testANegativeMonthDayCountsFromTheEnd(): void
    {
        self::assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31'],
            self::days('FREQ=MONTHLY;BYMONTHDAY=-1', '2026-01-31 10:00:00', '2026-04-01')
        );
    }

    public function testMonthlyWithNothingElseKeepsTheDayOfTheMonth(): void
    {
        self::assertSame(
            ['2026-01-15', '2026-02-15', '2026-03-15'],
            self::days('FREQ=MONTHLY', '2026-01-15 10:00:00', '2026-03-31')
        );
    }

    public function testCountStopsIt(): void
    {
        self::assertCount(3, self::days('FREQ=MONTHLY;COUNT=3', '2026-01-15 10:00:00', '2030-12-31'));
    }

    public function testUntilStopsIt(): void
    {
        self::assertSame(
            ['2026-01-01', '2026-01-04', '2026-01-07', '2026-01-10', '2026-01-13'],
            self::days('FREQ=DAILY;INTERVAL=3;UNTIL=20260115', '2026-01-01 08:00:00', '2026-12-31')
        );
    }

    public function testTheHorizonStopsItWhenNothingElseDoes(): void
    {
        self::assertSame(
            ['2026-01-01', '2026-01-02', '2026-01-03'],
            self::days('FREQ=DAILY', '2026-01-01 08:00:00', '2026-01-03')
        );
    }

    /**
     * The time of day is carried and never changed.
     *
     * A rule cannot move an event to a different hour — which is what people
     * expect, and what stops a zone conversion feeding back into the stored
     * value.
     */
    public function testTheTimeOfDayIsCarriedUnchanged(): void
    {
        foreach (Recurrence::parse('FREQ=WEEKLY')->dates('2026-01-04 19:45:00', '2026-02-01') as $wall) {
            self::assertStringEndsWith(' 19:45:00', $wall);
        }
    }

    /** The first date is the start itself. */
    public function testTheSeriesBeginsAtTheStart(): void
    {
        $dates = Recurrence::parse('FREQ=WEEKLY')->dates('2026-01-04 10:30:00', '2026-02-01');

        self::assertSame('2026-01-04 10:30:00', $dates[0]);
    }

    /** An unbounded rule is still bounded, so nothing can spin. */
    public function testAnEndlessRuleIsStillCapped(): void
    {
        $dates = Recurrence::parse('FREQ=DAILY')->dates('2026-01-01 08:00:00', '2099-12-31');

        self::assertLessThanOrEqual(Recurrence::MAX_DATES, count($dates));
        self::assertNotSame([], $dates);
    }

    public function testItWritesItselfBackOut(): void
    {
        self::assertSame(
            'FREQ=MONTHLY;INTERVAL=2;BYDAY=1SU',
            (string) Recurrence::parse('FREQ=MONTHLY;INTERVAL=2;BYDAY=1SU')
        );
    }

    // ------------------------------------------------- the awkward mornings

    /**
     * THE RULE: the hour that happens twice resolves to the FIRST.
     *
     * PHP does not do this on its own — measured, not assumed. Its constructor
     * returns the SECOND occurrence, an hour later, so a calendar feed built on
     * it puts the service an hour after the one people attend, once a year, on
     * the morning when everybody is already unsure what time it is.
     */
    public function testTheHourThatHappensTwiceResolvesToTheFirst(): void
    {
        $instant = WallClock::toInstant('2026-10-25 01:30:00', 'Europe/London');

        // 00:30 UTC is the first 01:30; 01:30 UTC is the second.
        self::assertSame('2026-10-25 00:30', gmdate('Y-m-d H:i', $instant));

        $shown = (new DateTimeImmutable('@' . $instant))->setTimezone(new DateTimeZone('Europe/London'));
        self::assertSame('01:30', $shown->format('H:i'), 'it stopped being the wall clock that was asked for');
        self::assertSame('BST', $shown->format('T'), 'THE SECOND OCCURRENCE WAS CHOSEN');

        // And PHP's own answer really is the other one, so this test is about a
        // difference rather than about agreeing with the language.
        $raw = new DateTimeImmutable('2026-10-25 01:30:00', new DateTimeZone('Europe/London'));
        self::assertSame('GMT', $raw->format('T'), 'PHP changed; the reason for this class may have gone');
    }

    /** THE RULE: the hour that never happens resolves forward. */
    public function testTheHourThatNeverHappensResolvesForward(): void
    {
        $instant = WallClock::toInstant('2026-03-29 01:30:00', 'Europe/London');

        $shown = (new DateTimeImmutable('@' . $instant))->setTimezone(new DateTimeZone('Europe/London'));

        self::assertSame('2026-03-29', $shown->format('Y-m-d'), 'it moved to a different day');
        self::assertSame('02:30', $shown->format('H:i'), 'it did not resolve forward');
    }

    /** The same rules, in a zone that changes on different dates. */
    public function testTheSameRulesHoldElsewhere(): void
    {
        $twice = WallClock::toInstant('2026-11-01 01:30:00', 'America/New_York');
        $shown = (new DateTimeImmutable('@' . $twice))->setTimezone(new DateTimeZone('America/New_York'));

        self::assertSame('EDT', $shown->format('T'), 'the second occurrence was chosen');
    }

    /**
     * And the ordinary case: 19:30 is 19:30 in January and in July, at two
     * different offsets from UTC. That is the whole reason the stored value is
     * a wall clock.
     */
    public function testTheSameWallClockIsTheSameHourAllYear(): void
    {
        foreach (['2026-01-15 19:30:00', '2026-07-15 19:30:00'] as $wall) {
            $instant = WallClock::toInstant($wall, 'Europe/London');
            $shown = (new DateTimeImmutable('@' . $instant))->setTimezone(new DateTimeZone('Europe/London'));

            self::assertSame('19:30', $shown->format('H:i'), $wall);
        }

        // Different instants, though — which is the point.
        self::assertNotSame(
            gmdate('H:i', WallClock::toInstant('2026-01-15 19:30:00', 'Europe/London')),
            gmdate('H:i', WallClock::toInstant('2026-07-15 19:30:00', 'Europe/London'))
        );
    }

    public function testTheOddMorningsCanBeNamed(): void
    {
        self::assertSame('twice', WallClock::oddity('2026-10-25 01:30:00', 'Europe/London'));
        self::assertSame('skipped', WallClock::oddity('2026-03-29 01:30:00', 'Europe/London'));
        self::assertSame('ok', WallClock::oddity('2026-06-01 19:30:00', 'Europe/London'));
    }

    /**
     * An unknown zone falls back rather than throwing.
     *
     * Zones get renamed, and a stored one a later PHP no longer knows must not
     * make an event unopenable: wrong by an hour at worst beats a page nobody
     * can see.
     */
    public function testAnUnknownZoneFallsBack(): void
    {
        $instant = WallClock::toInstant('2026-06-01 12:00:00', 'Mars/Olympus_Mons', 'Europe/London');

        $shown = (new DateTimeImmutable('@' . $instant))->setTimezone(new DateTimeZone('Europe/London'));

        self::assertSame('12:00', $shown->format('H:i'));
    }
}
