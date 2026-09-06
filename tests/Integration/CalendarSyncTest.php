<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Schedules\CalendarSync;
use Portal\Schedules\ScheduleRepository;

/**
 * What a device is told, and the two things it has to work out for itself.
 *
 * The rules here are the difference between a rota on a phone and a stale rota
 * on a phone, which is worse than none: somebody turns up for a thing that is
 * not happening. Against a real database because "a deleted row leaves no
 * trace" is a property of rows.
 */
final class CalendarSyncTest extends DatabaseTestCase
{
    private ScheduleRepository $schedules;
    private CalendarSync $sync;
    private int $scheduleId;

    protected function setUp(): void
    {
        $this->truncate([
            'schedule_entry_tombstones', 'schedule_reminders_sent', 'schedule_reminder_prefs',
            'schedule_entries', 'schedule_person_aliases', 'schedule_people',
            'schedule_sources', 'schedules', 'users',
        ]);

        $this->schedules = new ScheduleRepository($this->db());
        $this->sync = new CalendarSync($this->db(), $this->schedules);
        $this->scheduleId = $this->schedules->createSchedule('Welcome');
    }

    private function day(int $daysFromNow): string
    {
        return date('Y-m-d', strtotime("+{$daysFromNow} days"));
    }

    private function put(string $name, int $daysFromNow, ?int $scheduleId = null): int
    {
        $person = $this->schedules->personFor($name);
        $this->schedules->put($scheduleId ?? $this->scheduleId, $person, $this->day($daysFromNow), 'Coffee');

        return (int) $this->db()->value(
            'SELECT id FROM {schedule_entries} WHERE person_id = ? AND on_date = ?',
            [$person, $this->day($daysFromNow)]
        );
    }

    // ---------------------------------------------------------- the payload

    /** A device that has never asked gets everything in the window, and is told so. */
    public function testAFirstSyncIsFullAndSaysSo(): void
    {
        $this->put('Jane Cole', 7);

        $payload = $this->sync->payload();

        self::assertTrue($payload['full']);
        self::assertNull($payload['since']);
        self::assertCount(1, $payload['entries']);
        self::assertSame([], $payload['removed'], 'a full answer has nothing to remove from');
    }

    public function testASecondSyncCarriesOnlyWhatChanged(): void
    {
        $this->put('Jane Cole', 7);

        // A moment after the first entry was written.
        sleep(1);
        $mark = date('c');
        sleep(1);

        $this->put('Sam Ives', 9);

        $payload = $this->sync->payload($mark);

        self::assertFalse($payload['full']);
        self::assertCount(1, $payload['entries'], 'it sent the whole calendar again');
        self::assertSame('Sam Ives', $payload['entries'][0]['person']);
    }

    /**
     * A cancellation IS reportable, but only because something remembers it.
     *
     * A deleted row leaves no trace, so without the tombstone a phone that
     * asked "what has changed" would be told about everything added and
     * nothing cancelled — and somebody turns up for a date that was called off.
     */
    public function testACancelledDateIsReported(): void
    {
        $entryId = $this->put('Jane Cole', 7);

        sleep(1);
        $mark = date('c');
        sleep(1);

        $this->schedules->removeEntry($entryId);

        $payload = $this->sync->payload($mark);

        self::assertSame([$entryId], $payload['removed'], 'A CANCELLED DATE WOULD SIT ON THE PHONE');
        self::assertSame([], $payload['entries']);
    }

    /**
     * A change landing in the same second as an answer is still reported.
     *
     * Both comparisons here are strictly "later than since" against DATETIME
     * columns, which hold whole seconds. So a cursor set to the exact moment of
     * the answer would exclude anything stamped in that second — from this
     * payload AND from every one after it, because the device would keep asking
     * from a point that change is not later than. It would be gone for good.
     *
     * The cursor is therefore wound slightly behind, and this is what says so.
     */
    public function testAChangeInTheSameSecondAsTheAnswerIsNotLostForEver(): void
    {
        $payload = $this->sync->payload();

        // Immediately, in the same second the answer was generated.
        $entryId = $this->put('Jane Cole', 7);
        $this->schedules->removeEntry($entryId);
        $late = $this->put('Sam Ives', 9);

        $next = $this->sync->payload($payload['now']);

        self::assertContains($late, array_column($next['entries'], 'id'), 'AN EDIT WAS LOST FOR EVER');
        self::assertContains($entryId, $next['removed'], 'A CANCELLATION WAS LOST FOR EVER');
    }

    /**
     * Every delete path leaves one. A merge deletes the loser's duplicate rows,
     * and a device holding one has no other way to learn that id is gone — it
     * would sit alongside the winner's as a second person on the same day.
     */
    public function testAMergeAlsoLeavesTombstones(): void
    {
        $dave = $this->schedules->personFor('Dave Smith');
        $david = $this->schedules->personFor('David Smith');

        $this->schedules->put($this->scheduleId, $dave, $this->day(7), 'Coffee');
        $this->schedules->put($this->scheduleId, $david, $this->day(7), 'Coffee');

        $loserEntry = (int) $this->db()->value(
            'SELECT id FROM {schedule_entries} WHERE person_id = ?',
            [$david]
        );

        $this->schedules->merge($dave, $david);

        self::assertSame(
            1,
            (int) $this->db()->value(
                'SELECT COUNT(*) FROM {schedule_entry_tombstones} WHERE entry_id = ?',
                [$loserEntry]
            ),
            'a merge removed a row no device could find out about'
        );
    }

    // ------------------------------ the two rules the payload cannot state

    /**
     * RULE 1, from the server's side: a withdrawn schedule's dates are NOT
     * reported as changed or removed, and the schedule is still listed with its
     * flag so the device can act.
     *
     * Sending the dates as changes would put them back on the phone; sending
     * only the live schedules would leave the device unable to tell "withdrawn"
     * from "unchanged".
     */
    public function testAWithdrawnScheduleIsListedButItsDatesAreNot(): void
    {
        $this->put('Jane Cole', 7);

        sleep(1);
        $mark = date('c');
        sleep(1);

        $this->schedules->enableSchedule($this->scheduleId, false);

        $payload = $this->sync->payload($mark);

        self::assertSame([], $payload['entries'], 'the withdrawn dates were sent back to the device');
        self::assertSame([], $payload['removed'], 'disabling wrote a deletion it was not supposed to');

        self::assertCount(1, $payload['schedules']);
        self::assertFalse(
            $payload['schedules'][0]['enabled'],
            'THE DEVICE CANNOT TELL A WITHDRAWN SCHEDULE FROM AN UNCHANGED ONE'
        );

        /*
         * And on a FULL answer either — which is the only place the server's
         * own filter can be seen at all.
         *
         * The incremental assertions above pass whether or not that filter
         * exists: disabling a schedule does not touch `updated_at`, so those
         * entries were never going to be newer than the mark. A device doing
         * its first sync, or one told to start again, is the case where
         * sending them would put a withdrawn rota straight back on the phone.
         */
        self::assertSame(
            [],
            $this->sync->payload()['entries'],
            'a full sync handed back the dates of a schedule that is not running'
        );
    }

    /**
     * RULE 2, from the server's side: the window is in every payload, so the
     * device knows what to discard. Yesterday's entry is not deleted and will
     * never be mentioned again.
     */
    public function testEveryPayloadCarriesTheWindow(): void
    {
        $payload = $this->sync->payload();

        self::assertSame(date('Y-m-d'), $payload['window']['from']);
        self::assertSame(
            date('Y-m-d', strtotime('+' . ScheduleRepository::WINDOW_DAYS . ' days')),
            $payload['window']['to']
        );
    }

    public function testDatesOutsideTheWindowAreNotSent(): void
    {
        $this->put('Jane Cole', 7);
        $this->put('Sam Ives', 400);
        $this->put('Ade Okafor', -3);

        $payload = $this->sync->payload();

        self::assertSame(['Jane Cole'], array_column($payload['entries'], 'person'));
    }

    // ------------------------------------------- when incremental is a lie

    /**
     * A device away longer than the tombstones are kept gets everything.
     *
     * Answering incrementally would be claiming to know what it missed, and
     * this project has already paid once for treating "not in what we fetched"
     * as "gone" — this is the same mistake pointing the other way.
     */
    public function testADeviceAwayTooLongIsAnsweredInFull(): void
    {
        self::assertTrue($this->sync->mustBeFull(null));
        self::assertTrue($this->sync->mustBeFull(''));
        self::assertTrue($this->sync->mustBeFull('not a date'));
        self::assertTrue(
            $this->sync->mustBeFull(date('c', time() - (CalendarSync::TOMBSTONE_DAYS + 1) * 86400)),
            'it promised to know about cancellations it had already forgotten'
        );

        self::assertFalse($this->sync->mustBeFull(date('c', time() - 86400)));
    }

    /** A `since` in the future is a broken clock, not a device that is up to date. */
    public function testAFutureSinceIsAnsweredInFull(): void
    {
        self::assertTrue($this->sync->mustBeFull(date('c', time() + 3600)));
    }

    /** And what is forgotten is forgotten on a schedule, not for ever. */
    public function testOldCancellationsAreForgotten(): void
    {
        $entryId = $this->put('Jane Cole', 7);
        $this->schedules->removeEntry($entryId);

        $this->db()->execute(
            'UPDATE {schedule_entry_tombstones} SET deleted_at = DATE_SUB(NOW(), INTERVAL ? DAY)',
            [CalendarSync::TOMBSTONE_DAYS + 5]
        );

        self::assertSame(1, $this->sync->prune());
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_entry_tombstones}')
        );
    }

    /** A recent one is not. */
    public function testRecentCancellationsSurviveAPrune(): void
    {
        $this->schedules->removeEntry($this->put('Jane Cole', 7));

        self::assertSame(0, $this->sync->prune());
        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_entry_tombstones}')
        );
    }
}
