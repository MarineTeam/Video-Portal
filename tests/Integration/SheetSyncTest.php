<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Schedules\ScheduleRepository;
use Portal\Schedules\SheetSync;

/**
 * What a spreadsheet sync is allowed to change.
 *
 * Against a real database because every rule here is about rows SURVIVING: a
 * date outside what the sheet covers, a row somebody typed by hand, and the
 * whole rota when the fetch fails. None of that is observable against a mock —
 * a mock would happily agree that nothing was deleted while the delete ran.
 */
final class SheetSyncTest extends DatabaseTestCase
{
    private ScheduleRepository $schedules;
    private SheetSync $sync;
    private int $scheduleId;
    /** @var array<string, mixed> */
    private array $source;

    protected function setUp(): void
    {
        $this->truncate([
            'schedule_entries', 'schedule_person_aliases', 'schedule_people',
            'schedule_sources', 'schedules', 'users',
        ]);

        $this->schedules = new ScheduleRepository($this->db());
        $this->sync = new SheetSync($this->db(), $this->schedules);
        $this->scheduleId = $this->schedules->createSchedule('Welcome');

        $this->schedules->saveSource(
            $this->scheduleId,
            'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrS/edit#gid=7',
            'rows',
            'auto'
        );

        $source = $this->schedules->source($this->scheduleId);
        self::assertIsArray($source);
        $this->source = $source;
    }

    /**
     * @param list<array{0: string, 1: string, 2?: string}> $rows
     * @return list<array{date: string, name: string, role: string, note: string}>
     */
    private function rows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'date' => $row[0],
                'name' => $row[1],
                'role' => $row[2] ?? '',
                'note' => '',
            ],
            $rows
        );
    }

    /** @return list<array<string, mixed>> */
    private function stored(): array
    {
        return $this->db()->all(
            'SELECT e.on_date, e.role, e.source, p.name
               FROM {schedule_entries} e
               INNER JOIN {schedule_people} p ON p.id = e.person_id
              ORDER BY e.on_date, p.name'
        );
    }

    // ------------------------------------------------------------- writing

    public function testASheetPutsItsPeopleOnTheirDays(): void
    {
        $written = $this->sync->apply($this->source, $this->rows([
            ['2026-09-06', 'Jane Cole', 'Coffee'],
            ['2026-09-13', 'Sam Ives', 'Reading'],
        ]));

        self::assertSame(2, $written['added']);
        self::assertCount(2, $this->stored());
    }

    /**
     * Reading the same sheet again changes nothing.
     *
     * A sync that re-added everything every quarter of an hour would double
     * the rota on the first run and churn the table for ever after.
     */
    public function testReadingTheSameSheetTwiceChangesNothing(): void
    {
        $rows = $this->rows([['2026-09-06', 'Jane Cole', 'Coffee']]);

        $this->sync->apply($this->source, $rows);
        $second = $this->sync->apply($this->source, $rows);

        self::assertSame(0, $second['added']);
        self::assertSame(1, $second['kept']);
        self::assertSame(0, $second['removed']);
        self::assertCount(1, $this->stored());
    }

    /** Somebody taken off the sheet comes off the calendar. */
    public function testARowRemovedFromTheSheetIsRemoved(): void
    {
        $this->sync->apply($this->source, $this->rows([
            ['2026-09-06', 'Jane Cole', 'Coffee'],
            ['2026-09-13', 'Sam Ives', 'Coffee'],
        ]));

        $after = $this->sync->apply($this->source, $this->rows([
            ['2026-09-06', 'Jane Cole', 'Coffee'],
            ['2026-09-13', 'Ade Okafor', 'Coffee'],
        ]));

        self::assertSame(1, $after['removed']);
        self::assertSame(
            ['Jane Cole', 'Ade Okafor'],
            array_column($this->stored(), 'name')
        );
    }

    // ---------------------------------------------------- what it may not touch

    /**
     * THE RULE. A sheet covering September must not sweep away January.
     *
     * "Not in this document" and "no longer happening" are different claims,
     * and a sync only ever knows the first. Another sheet, an earlier import
     * or somebody's hands put those dates there.
     */
    public function testASheetDoesNotTouchDatesOutsideWhatItCovers(): void
    {
        $this->sync->apply($this->source, $this->rows([
            ['2026-01-04', 'Ade Okafor', 'Coffee'],
            ['2026-09-06', 'Jane Cole', 'Coffee'],
        ]));

        // A later sheet that only holds September.
        $this->sync->apply($this->source, $this->rows([['2026-09-06', 'Jane Cole', 'Coffee']]));

        self::assertSame(
            ['2026-01-04', '2026-09-06'],
            array_column($this->stored(), 'on_date'),
            'the sync deleted a date its sheet said nothing about'
        );
    }

    /**
     * And a row somebody TYPED is never the sync's to remove.
     *
     * It sits inside the span, so only the source column keeps it — which is
     * what that column is for.
     */
    public function testARowTypedByHandSurvivesASyncThatDoesNotMentionIt(): void
    {
        $this->schedules->put(
            $this->scheduleId,
            $this->schedules->personFor('Ade Okafor'),
            '2026-09-20',
            'Sound'
        );

        $this->sync->apply($this->source, $this->rows([
            ['2026-09-06', 'Jane Cole', 'Coffee'],
            ['2026-09-27', 'Sam Ives', 'Coffee'],
        ]));

        $names = array_column($this->stored(), 'name');

        self::assertContains('Ade Okafor', $names, 'THE SYNC DELETED SOMETHING SOMEBODY TYPED');
    }

    /**
     * A hand-typed row the sheet also names stays hand-typed.
     *
     * Otherwise the sheet agreeing with somebody once would hand ownership of
     * their row over, and it would disappear the first week the sheet stopped
     * mentioning it.
     */
    public function testTheSheetAgreeingDoesNotTakeOwnershipOfATypedRow(): void
    {
        $this->schedules->put(
            $this->scheduleId,
            $this->schedules->personFor('Jane Cole'),
            '2026-09-06',
            'Coffee'
        );

        $this->sync->apply($this->source, $this->rows([['2026-09-06', 'Jane Cole', 'Coffee']]));

        self::assertSame('manual', $this->stored()[0]['source']);

        // And so it still survives a sheet that drops it.
        $this->sync->apply($this->source, $this->rows([['2026-09-13', 'Sam Ives', 'Coffee']]));

        self::assertContains('Jane Cole', array_column($this->stored(), 'name'));
    }

    // ------------------------------------------------------------ the preview

    /**
     * A preview writes NOTHING — not even the people it would create.
     *
     * personFor() makes somebody when it cannot find them, so a preview that
     * used it would answer "how many names here are new" by making all of them,
     * and the second look would say none were.
     */
    public function testAPreviewCreatesNobody(): void
    {
        $plan = $this->sync->plan($this->source, $this->rows([
            ['2026-09-06', 'Jane Cole', 'Coffee'],
            ['2026-09-13', 'Sam Ives', 'Coffee'],
        ]));

        self::assertSame(['Jane Cole', 'Sam Ives'], $plan['newPeople']);
        self::assertSame(2, count($plan['add']));
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {schedule_people}'),
            'the preview made the people it was only supposed to count'
        );
    }

    /** And it names each new person once, however many days they are on. */
    public function testAPreviewNamesEachNewPersonOnce(): void
    {
        $plan = $this->sync->plan($this->source, $this->rows([
            ['2026-09-06', 'José Ángel', 'Coffee'],
            ['2026-09-13', 'JOSE ANGEL', 'Coffee'],
        ]));

        self::assertCount(1, $plan['newPeople']);
    }

    // ------------------------------------------------ what a failure looks like

    /**
     * An unshared sheet answers 200 with a sign-in page, not 403.
     *
     * Taken as CSV that parses as a sheet with no headings, and the screen
     * would tell somebody whose headings are perfectly good to go and fix them.
     */
    public function testASignInPageIsRecognisedRatherThanParsed(): void
    {
        self::assertStringContainsString(
            'Anyone with the link',
            (string) SheetSync::explain(200, 'text/html; charset=utf-8', '<!DOCTYPE html><html>')
        );

        // Even when the far end forgets to say what it sent.
        self::assertStringContainsString(
            'Anyone with the link',
            (string) SheetSync::explain(200, null, "\n<html><head>")
        );
    }

    public function testARealCsvIsNotMistakenForAProblem(): void
    {
        self::assertNull(SheetSync::explain(200, 'text/csv', "Date,Name\n2026-09-06,Jane Cole\n"));
    }

    public function testEachFailureSaysSomethingDifferent(): void
    {
        self::assertStringContainsString('no sheet at that address', (string) SheetSync::explain(404, null, ''));
        self::assertStringContainsString('Anyone with the link', (string) SheetSync::explain(403, null, ''));
        self::assertStringContainsString('HTTP 500', (string) SheetSync::explain(500, null, ''));
        self::assertStringContainsString('came back empty', (string) SheetSync::explain(200, 'text/csv', '  '));
    }

    // ------------------------------------------- a failed sync deletes nothing

    /**
     * THE RULE THIS CLASS EXISTS FOR.
     *
     * This runs unattended. A sync that deleted on failure would empty a rota
     * at three in the morning because somebody's Google account was being
     * upgraded, and the first sign of it would be an empty page on Sunday.
     */
    public function testAFailedFetchChangesNothingAtAll(): void
    {
        $this->sync->apply($this->source, $this->rows([
            ['2026-09-06', 'Jane Cole', 'Coffee'],
            ['2026-09-13', 'Sam Ives', 'Coffee'],
        ]));

        $before = $this->stored();

        $result = $this->sync->runWith($this->source, [
            'status'   => SheetSync::FAILED,
            'message'  => 'This site could not reach docs.google.com.',
            'csv'      => '',
            'etag'     => null,
            'modified' => null,
            'hash'     => null,
        ]);

        self::assertSame(SheetSync::FAILED, $result['status']);
        self::assertSame($before, $this->stored(), 'A FAILED SYNC DESTROYED THE ROTA');
    }

    /**
     * And a sheet that reads as nothing changes nothing either.
     *
     * The tempting reading — "the sheet is empty, so the rota is empty" — is
     * wrong far more often than it is right: a renamed tab, a sharing change
     * and a sign-in page served as CSV all look like this. Each is recoverable
     * in seconds; a year of rota deleted overnight is not.
     */
    public function testASheetThatReadsAsNothingDeletesNothing(): void
    {
        $this->sync->apply($this->source, $this->rows([['2026-09-06', 'Jane Cole', 'Coffee']]));

        $result = $this->sync->runWith($this->source, [
            'status'   => SheetSync::OK,
            'message'  => 'Read the sheet.',
            'csv'      => "\n\n",
            'etag'     => null,
            'modified' => null,
            'hash'     => str_repeat('b', 64),
        ]);

        self::assertSame(SheetSync::OK, $result['status']);
        self::assertStringContainsString('nothing was changed', $result['message']);
        self::assertCount(1, $this->stored(), 'AN EMPTY SHEET EMPTIED THE ROTA');
    }

    /** An unchanged sheet is not parsed and writes nothing. */
    public function testAnUnchangedSheetIsLeftAlone(): void
    {
        $this->sync->apply($this->source, $this->rows([['2026-09-06', 'Jane Cole', 'Coffee']]));

        $result = $this->sync->runWith($this->source, [
            'status'   => SheetSync::UNCHANGED,
            'message'  => 'The sheet has not changed since the last run.',
            'csv'      => '',
            'etag'     => 'W/"abc"',
            'modified' => null,
            'hash'     => str_repeat('c', 64),
        ]);

        self::assertSame(SheetSync::UNCHANGED, $result['status']);
        self::assertCount(1, $this->stored());

        // And it did remember the fingerprint, which is what makes the NEXT
        // run cheap as well.
        self::assertSame('W/"abc"', ((array) $this->schedules->source($this->scheduleId))['etag']);
    }

    // --------------------------------------------------------- the source row

    /**
     * A new address forgets what was remembered about the old one.
     *
     * Keeping the hash would make the next run decide nothing had changed and
     * do nothing at all, which reads as the new address being ignored.
     */
    public function testChangingTheAddressForgetsTheOldSheetsFingerprint(): void
    {
        $this->schedules->recordRun((int) $this->source['id'], 'ok', 'read', 3, 'W/"abc"', 'Mon', str_repeat('a', 64));

        $this->schedules->saveSource(
            $this->scheduleId,
            'https://docs.google.com/spreadsheets/d/2ZzYyXxWwVvUuTtSsRr/edit',
            'grid',
            'dmy'
        );

        $source = (array) $this->schedules->source($this->scheduleId);

        self::assertNull($source['content_hash']);
        self::assertNull($source['etag']);
        self::assertSame('grid', $source['layout']);
        self::assertSame('dmy', $source['date_order']);
    }

    /**
     * A failed run leaves the fingerprint alone.
     *
     * Clearing it would make the run after a blip fetch and reparse a sheet
     * that had not changed — the cost falls on exactly the sites already having
     * a bad day.
     */
    public function testAFailedRunKeepsWhatMakesTheNextOneCheap(): void
    {
        $id = (int) $this->source['id'];
        $this->schedules->recordRun($id, 'ok', 'read', 3, 'W/"abc"', 'Mon', str_repeat('a', 64));
        $this->schedules->recordRun($id, 'failed', 'could not reach it');

        $source = (array) $this->schedules->source($this->scheduleId);

        self::assertSame('W/"abc"', $source['etag']);
        self::assertSame(str_repeat('a', 64), $source['content_hash']);
        self::assertSame('failed', $source['last_status']);
    }

    /**
     * A source on a schedule that is off the calendar is not fetched.
     *
     * Its dates are hidden, so calling somebody else's server every quarter of
     * an hour to update rows nobody can see is a request nobody asked for.
     */
    public function testASourceOnAWithdrawnScheduleIsNotFetched(): void
    {
        self::assertCount(1, $this->schedules->dueSources());

        $this->schedules->enableSchedule($this->scheduleId, false);

        self::assertSame([], $this->schedules->dueSources());
    }

    /** And a URL that is not a sheet is refused where somebody can see it. */
    public function testAnAddressThatIsNotASheetIsRefusedAtTheForm(): void
    {
        $this->expectExceptionMessage('not a Google Sheets address');

        $this->schedules->saveSource($this->scheduleId, 'https://example.com/rota.csv', 'rows', 'auto');
    }
}
