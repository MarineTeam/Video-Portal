<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Schedules\ScheduleRepository;

/**
 * The schedules calendar: names rather than accounts.
 *
 * Against a real database because the rules are about rows surviving — what a
 * merge keeps, what disabling does NOT touch, and a unique key that has to make
 * a re-read idempotent rather than doubling every row.
 */
final class ScheduleTest extends DatabaseTestCase
{
    private ScheduleRepository $schedules;
    private int $scheduleId;

    protected function setUp(): void
    {
        $this->truncate([
            'schedule_entries', 'schedule_person_aliases', 'schedule_people', 'schedules', 'users',
        ]);

        $this->schedules = new ScheduleRepository($this->db());
        $this->scheduleId = $this->schedules->createSchedule('Welcome', '👋', '#38bdf8');
    }

    private function day(int $daysFromNow): string
    {
        return date('Y-m-d', strtotime("+{$daysFromNow} days"));
    }

    // ------------------------------------------------------------ matching

    /**
     * Four spellings on four spreadsheet rows are one person.
     *
     * A sync that made four would have somebody on the rota four times,
     * recognising none of the entries as theirs.
     */
    public function testFourSpellingsOfOneNameAreOnePerson(): void
    {
        $ids = [];

        foreach (['José Ángel', 'JOSE ANGEL', ' jose  angel ', 'Jose Angel'] as $spelling) {
            $ids[] = $this->schedules->personFor($spelling);
        }

        self::assertCount(1, array_unique($ids), 'one person became several');
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_people}'));
    }

    /** And two different people stay two. */
    public function testTwoPeopleStayTwo(): void
    {
        $a = $this->schedules->personFor('John Smith');
        $b = $this->schedules->personFor('Jane Smith');

        self::assertNotSame($a, $b);
    }

    /** An alias is another way in to the same person. */
    public function testAnAliasFindsTheSamePerson(): void
    {
        $robert = $this->schedules->personFor('Robert Jones');

        $this->db()->insert('schedule_person_aliases', [
            'person_id' => $robert,
            'name' => 'Bob Jones',
            'match_key' => \Portal\Schedules\PersonKey::for('Bob Jones'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::assertSame(
            $robert,
            $this->schedules->personFor('Bob Jones'),
            'a spreadsheet that switched to the other name made a second person'
        );
    }

    // ------------------------------------------------------------- merging

    /**
     * THE RULE: near-duplicates are suggested, never merged automatically.
     *
     * "Dave Smith" and "David Smith" are usually one person and occasionally a
     * father and son. An automatic merge is unpickable afterwards — the two
     * histories become one and nothing records which entries came from which.
     */
    public function testNearDuplicatesAreSuggestedAndNotMerged(): void
    {
        $dave = $this->schedules->personFor('Dave Smith');
        $david = $this->schedules->personFor('David Smith');

        self::assertNotSame($dave, $david, 'THE SITE MERGED TWO PEOPLE ON ITS OWN');

        $suggestions = $this->schedules->duplicateSuggestions();

        self::assertCount(1, $suggestions, 'the pair was not offered for a person to decide about');
        self::assertGreaterThanOrEqual(82, $suggestions[0]['score']);
    }

    /** Two genuinely different people are not offered. */
    public function testUnrelatedPeopleAreNotOffered(): void
    {
        $this->schedules->personFor('John Smith');
        $this->schedules->personFor('Priya Nair');

        self::assertSame([], $this->schedules->duplicateSuggestions());
    }

    /**
     * A merge moves the entries and keeps the old spelling as an alias.
     *
     * Without the alias, the next sync would recreate the person that was just
     * merged away — and it would happen again every night.
     */
    public function testMergingMovesTheDaysAndKeepsTheOldSpellingWorking(): void
    {
        $dave = $this->schedules->personFor('Dave Smith');
        $david = $this->schedules->personFor('David Smith');

        $this->schedules->put($this->scheduleId, $dave, $this->day(7), 'Coffee');
        $this->schedules->put($this->scheduleId, $david, $this->day(14), 'Coffee');

        $this->schedules->merge($david, $dave);

        self::assertSame(
            2,
            (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_entries} WHERE person_id = ?', [$david]),
            'the days did not move'
        );
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_people}'));

        self::assertSame(
            $david,
            $this->schedules->personFor('Dave Smith'),
            'THE OLD SPELLING WOULD RECREATE THE MERGED PERSON ON THE NEXT SYNC'
        );
    }

    /**
     * The same person on the same day under two spellings collapses to one
     * entry rather than failing the merge.
     */
    public function testAMergeSurvivesTheSameDayUnderBothNames(): void
    {
        $dave = $this->schedules->personFor('Dave Smith');
        $david = $this->schedules->personFor('David Smith');

        $this->schedules->put($this->scheduleId, $dave, $this->day(7), 'Coffee');
        $this->schedules->put($this->scheduleId, $david, $this->day(7), 'Coffee');

        $this->schedules->merge($david, $dave);

        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_entries}'));
    }

    // ------------------------------------------------------------- entries

    /** Re-reading a spreadsheet does not double every row. */
    public function testPuttingSomebodyOnTheSameDayTwiceIsOneEntry(): void
    {
        $person = $this->schedules->personFor('Alice');

        $this->schedules->put($this->scheduleId, $person, $this->day(7), 'Reading');
        $this->schedules->put($this->scheduleId, $person, $this->day(7), 'Reading');

        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_entries}'));
    }

    /** Two different jobs on one day are two entries, which is right. */
    public function testTwoJobsOnOneDayAreTwoEntries(): void
    {
        $person = $this->schedules->personFor('Alice');

        $this->schedules->put($this->scheduleId, $person, $this->day(7), 'Reading');
        $this->schedules->put($this->scheduleId, $person, $this->day(7), 'Coffee');

        self::assertSame(2, (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_entries}'));
    }

    // ----------------------------------------------------------- disabling

    /**
     * THE RULE: disabling a schedule takes its dates off the calendar WITHOUT
     * touching the entries.
     *
     * Both halves matter. The dates have to go, or a reader sees a rota that is
     * not running. The rows have to stay, or re-enabling loses a year of rota
     * somebody typed in — and this is exactly the case the device sync cannot
     * report, because nothing about those entries changed.
     */
    public function testDisablingRemovesTheDatesFromTheCalendarAndKeepsTheRows(): void
    {
        $person = $this->schedules->personFor('Alice');
        $this->schedules->put($this->scheduleId, $person, $this->day(7), 'Reading');

        self::assertCount(1, $this->schedules->calendar());

        $this->schedules->enableSchedule($this->scheduleId, false);

        self::assertSame([], $this->schedules->calendar(), 'a disabled schedule is still on the calendar');

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_entries}'),
            'DISABLING DESTROYED THE ROTA — re-enabling would lose a year of it'
        );

        $this->schedules->enableSchedule($this->scheduleId, true);

        self::assertCount(1, $this->schedules->calendar(), 'it did not come back');
    }

    /** The calendar is a window, and days outside it are not in the payload. */
    public function testTheCalendarIsAWindow(): void
    {
        $person = $this->schedules->personFor('Alice');

        $this->schedules->put($this->scheduleId, $person, $this->day(3), 'Soon');
        $this->schedules->put($this->scheduleId, $person, $this->day(400), 'Far off');

        $names = array_map(
            static fn (array $row): string => (string) $row['role'],
            $this->schedules->calendar()
        );

        self::assertContains('Soon', $names);
        self::assertNotContains('Far off', $names, 'the window did not bound the calendar');
    }

    // ------------------------------------------------------------ the look

    /**
     * A colour is validated rather than escaped, because it goes into CSS and
     * escaping is not a defence there.
     */
    public function testOnlyARealColourIsKept(): void
    {
        self::assertSame('#38bdf8', ScheduleRepository::colour('#38BDF8'));
        self::assertNull(ScheduleRepository::colour('red; background: url(evil)'));
        self::assertNull(ScheduleRepository::colour('javascript:alert(1)'));
        self::assertNull(ScheduleRepository::colour(''));
    }

    public function testAnIconCannotBecomeASentence(): void
    {
        self::assertSame('👋', ScheduleRepository::icon('👋'));
        self::assertSame('ab', ScheduleRepository::icon('abcdefgh'));
        self::assertNull(ScheduleRepository::icon(''));
    }
}
