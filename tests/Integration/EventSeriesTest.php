<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Events\EventRepository;
use Portal\Events\SeriesRepository;
use Portal\Events\Signup;
use Portal\Http\HttpException;

/**
 * Recurring events: real rows, and a cancellation that sticks.
 *
 * Against a real database because both rules are about what survives: what a
 * delete leaves behind, and what the next generator run puts back. Neither is
 * a question a double can answer.
 */
final class EventSeriesTest extends DatabaseTestCase
{
    private SeriesRepository $series;
    private EventRepository $events;

    protected function setUp(): void
    {
        $this->truncate(['event_signups', 'event_series_exclusions', 'events', 'event_series', 'users']);

        $this->events = new EventRepository($this->db());
        $this->series = new SeriesRepository($this->db(), $this->events);
    }

    /** @param array<string, mixed> $overrides */
    private function weekly(array $overrides = []): int
    {
        return $this->series->create($overrides + [
            'title'     => 'Tuesday Prayer',
            'rrule'     => 'FREQ=WEEKLY;BYDAY=TU',
            'starts_at' => '2026-01-06 19:30:00',
        ]);
    }

    /**
     * A series starting next week, for the tests that sign somebody up.
     *
     * The fixed January dates elsewhere are chosen so the expected Tuesdays can
     * be written down and read; they are also in the past, and sign-up on a
     * past event is correctly refused. Two tests failed on that and the
     * application was right — so those use a series that has not happened yet.
     *
     * @param array<string, mixed> $overrides
     * @return array{0: int, 1: string} the series and the horizon to generate to
     */
    private function futureWeekly(array $overrides = []): array
    {
        $first = date('Y-m-d', strtotime('next tuesday +7 days'));

        $id = $this->series->create($overrides + [
            'title'     => 'Tuesday Prayer',
            'rrule'     => 'FREQ=WEEKLY;BYDAY=TU',
            'starts_at' => $first . ' 19:30:00',
        ]);

        return [$id, date('Y-m-d', strtotime($first . ' +14 days'))];
    }

    /** @return list<string> the dates of the meetings, ascending */
    private function madeDates(int $seriesId): array
    {
        return array_map(
            static fn (array $row): string => substr((string) $row['starts_at'], 0, 10),
            $this->series->events($seriesId)
        );
    }

    // ------------------------------------------- THE SERIES IS NOT AN EVENT

    /**
     * Every date is an ordinary event row with its own slug.
     *
     * Not one row carrying a rule. The alternative makes deleting the first
     * meeting an act that deletes the year.
     */
    public function testEveryDateIsARealEventWithItsOwnSlug(): void
    {
        $id = $this->weekly();
        $this->series->generate($id, '2026-02-03');

        $events = $this->series->events($id);

        self::assertCount(5, $events, 'the rule did not become rows');

        $slugs = array_map(static fn (array $e): string => (string) $e['slug'], $events);
        self::assertSame($slugs, array_unique($slugs), 'two meetings share a slug');

        // And each is a whole event: capacity, sign-up, the lot.
        self::assertArrayHasKey('capacity', $events[0]);
    }

    /**
     * THE RULE: deleting the series leaves the meetings standing.
     *
     * The strongest thing stopping a series can mean is "make no more". If it
     * deleted the meetings it would take their sign-up lists with them, which
     * is the year-deleting failure wearing a different hat.
     */
    public function testDeletingTheSeriesDoesNotDeleteTheMeetings(): void
    {
        $id = $this->weekly();
        $this->series->generate($id, '2026-02-03');

        $this->series->delete($id);

        self::assertSame(
            5,
            (int) $this->db()->value('SELECT COUNT(*) FROM {events}'),
            'STOPPING A SERIES DELETED THE MEETINGS'
        );

        self::assertNull(
            $this->db()->value('SELECT series_id FROM {events} LIMIT 1'),
            'the meetings still point at a series that is gone'
        );
    }

    /** And a meeting keeps the people who signed up for it. */
    public function testAMeetingKeepsItsSignupsWhenTheSeriesGoes(): void
    {
        [$id, $horizon] = $this->futureWeekly(['signup_enabled' => true, 'capacity' => 20]);
        $this->series->generate($id, $horizon);

        $first = (int) $this->series->events($id)[0]['id'];
        $this->events->signUp($first, 'Alice', 'alice@example.test');

        $this->series->delete($id);

        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {event_signups}'));
    }

    // ------------------------------------------------ THE EXCLUSION STICKS

    /**
     * THE RULE: a cancelled date does not come back.
     *
     * The rule still names that date, so without the exclusion the next
     * generator run puts the meeting straight back — reported as "the site
     * un-cancelled my event".
     *
     * The second generate() is the whole test. Deleting and checking it is gone
     * would pass against an implementation with no exclusion list at all.
     */
    public function testACancelledDateIsNotPutBackByTheNextRun(): void
    {
        $id = $this->weekly();
        $this->series->generate($id, '2026-02-03');

        $second = (int) $this->series->events($id)[1]['id'];
        $cancelledOn = substr((string) $this->series->events($id)[1]['starts_at'], 0, 10);

        self::assertTrue($this->series->cancelDate($id, $second));
        self::assertNotContains($cancelledOn, $this->madeDates($id));

        // THE ASSERTION THAT MATTERS: run the generator again.
        $this->series->generate($id, '2026-02-03');

        self::assertNotContains(
            $cancelledOn,
            $this->madeDates($id),
            'THE GENERATOR RESTORED A CANCELLED MEETING'
        );

        self::assertCount(4, $this->series->events($id));
    }

    /** The exclusion and the delete are one transaction: both, or neither. */
    public function testTheExclusionIsWrittenWithTheDelete(): void
    {
        $id = $this->weekly();
        $this->series->generate($id, '2026-01-20');

        $event = $this->series->events($id)[0];

        $this->series->cancelDate($id, (int) $event['id']);

        self::assertArrayHasKey(
            (string) $event['series_date'],
            $this->series->exclusions($id),
            'the meeting was deleted with nothing to stop it coming back'
        );
    }

    /** Cancelling something that is not part of this series does nothing. */
    public function testCancellingSomebodyElsesEventDoesNothing(): void
    {
        $id = $this->weekly();
        $this->series->generate($id, '2026-01-20');

        $other = $this->events->create([
            'title'     => 'Unrelated',
            'starts_at' => '2026-01-09 10:00:00',
        ]);

        self::assertFalse($this->series->cancelDate($id, $other));
        self::assertSame([], $this->series->exclusions($id));
        self::assertNotNull($this->events->find($other));
    }

    /** A cancelled date can be put back, and the next run makes it again. */
    public function testUncancellingLetsTheMeetingBeMadeAgain(): void
    {
        $id = $this->weekly();
        $this->series->generate($id, '2026-01-20');

        $event = $this->series->events($id)[0];
        $day = (string) $event['series_date'];

        $this->series->cancelDate($id, (int) $event['id']);
        self::assertTrue($this->series->uncancelDate($id, $day));

        $this->series->generate($id, '2026-01-20');

        self::assertContains($day, $this->madeDates($id));
    }

    // ------------------------------------------------------- generating

    /**
     * Running twice makes nothing twice.
     *
     * A daily job has to be safe to run repeatedly, and the unique key on
     * (series_id, series_date) is the backstop rather than the mechanism.
     */
    public function testGeneratingTwiceMakesNothingTwice(): void
    {
        $id = $this->weekly();

        self::assertSame(5, $this->series->generate($id, '2026-02-03'));
        self::assertSame(0, $this->series->generate($id, '2026-02-03'));
        self::assertCount(5, $this->series->events($id));
    }

    /**
     * A meeting somebody MOVED is not made a second time.
     *
     * series_date is the date the rule produced; starts_at is where the meeting
     * actually is. Without the two being separate, moving a meeting to the
     * Wednesday leaves the Tuesday looking un-made and the next run creates it.
     */
    public function testMovingAMeetingDoesNotMakeADuplicate(): void
    {
        $id = $this->weekly();
        $this->series->generate($id, '2026-01-20');

        $first = (int) $this->series->events($id)[0]['id'];

        $this->db()->execute(
            'UPDATE {events} SET starts_at = ? WHERE id = ?',
            ['2026-01-07 19:30:00', $first]
        );

        self::assertSame(0, $this->series->generate($id, '2026-01-20'), 'a moved meeting was remade');
        self::assertCount(3, $this->series->events($id));
    }

    /** An edited meeting is left alone by the nightly run. */
    public function testAnEditedMeetingIsNotRewrittenNightly(): void
    {
        $id = $this->weekly(['capacity' => 10]);
        $this->series->generate($id, '2026-01-20');

        $first = (int) $this->series->events($id)[0]['id'];
        $this->events->setCapacity($first, 99);

        $this->series->generate($id, '2026-01-20');

        self::assertSame(
            99,
            (int) $this->db()->value('SELECT capacity FROM {events} WHERE id = ?', [$first]),
            'the nightly run undid an organiser\'s edit'
        );
    }

    /** The horizon moves forward and the next run makes what is now in range. */
    public function testMovingTheHorizonMakesMore(): void
    {
        $id = $this->weekly();

        self::assertSame(2, $this->series->generate($id, '2026-01-13'));
        self::assertSame(3, $this->series->generate($id, '2026-02-03'));
        self::assertCount(5, $this->series->events($id));
    }

    /** The meetings carry the template's settings. */
    public function testTheMeetingsAreStampedFromTheSeries(): void
    {
        $id = $this->weekly([
            'capacity'       => 12,
            'signup_enabled' => true,
            'member_only'    => true,
            'is_published'   => true,
            'location'       => 'The vestry',
            'duration_minutes' => 90,
        ]);

        $this->series->generate($id, '2026-01-13');
        $event = $this->series->events($id)[0];

        self::assertSame(12, (int) $event['capacity']);
        self::assertSame(1, (int) $event['signup_enabled']);
        self::assertSame(1, (int) $event['member_only']);
        self::assertSame('The vestry', $event['location']);
        self::assertSame('2026-01-06 21:00:00', $event['ends_at'], 'the duration was not applied');
    }

    /** Each meeting has its own capacity and its own list. */
    public function testEachMeetingFillsSeparately(): void
    {
        [$id, $horizon] = $this->futureWeekly(['capacity' => 1, 'signup_enabled' => true]);
        $this->series->generate($id, $horizon);

        $events = $this->series->events($id);

        $this->events->signUp((int) $events[0]['id'], 'Alice', 'alice@example.test');
        $second = $this->events->signUp((int) $events[1]['id'], 'Alice', 'alice@example.test');

        self::assertSame(
            Signup::GOING,
            $second->state,
            'filling one meeting closed another'
        );
    }

    // ------------------------------------------------------------ refusals

    /** A rule outside the subset is refused before a series row exists. */
    public function testABadRuleMakesNoSeries(): void
    {
        try {
            $this->series->create([
                'title'     => 'Nope',
                'rrule'     => 'FREQ=WEEKLY;BYSETPOS=-1',
                'starts_at' => '2026-01-06 19:30:00',
            ]);
            self::fail('a rule outside the subset was stored');
        } catch (HttpException $e) {
            self::assertStringContainsString('BYSETPOS', $e->getMessage());
        }

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {event_series}'));
    }

    /**
     * A rule this build can no longer read makes nothing and destroys nothing.
     *
     * The meetings already made stand, so a site upgraded to a build that
     * understands less does not lose its calendar — it stops adding to it.
     */
    public function testAnUnreadableRuleGeneratesNothingAndBreaksNothing(): void
    {
        $id = $this->weekly();
        $this->series->generate($id, '2026-01-20');

        $this->db()->execute(
            'UPDATE {event_series} SET rrule = ? WHERE id = ?',
            ['FREQ=FORTNIGHTLY', $id]
        );

        self::assertSame(0, $this->series->generate($id, '2026-03-01'));
        self::assertCount(3, $this->series->events($id), 'the meetings were destroyed');
    }
}
