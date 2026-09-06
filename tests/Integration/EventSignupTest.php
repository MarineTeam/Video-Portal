<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Events\EventRepository;
use Portal\Events\Signup;
use Portal\Http\HttpException;

/**
 * Capacity, and the queue behind it.
 *
 * Against a real database throughout, and for one test against several real
 * processes. The spec is explicit about why: "read capacity outside the lock
 * and write inside it and you have built the version that overbooks" — and that
 * version passes every single-threaded test ever written for it, because the
 * read and the write are never interleaved with anybody else's.
 */
final class EventSignupTest extends DatabaseTestCase
{
    private EventRepository $events;
    private int $eventId;

    protected function setUp(): void
    {
        $this->truncate(['event_signups', 'events', 'users']);

        $this->events = new EventRepository($this->db());
        $this->eventId = $this->event(['capacity' => 3, 'max_guests' => 5]);
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): int
    {
        return $this->events->create($overrides + [
            'title'          => 'Harvest Supper',
            'starts_at'      => date('Y-m-d H:i:s', time() + 604800),
            'signup_enabled' => true,
            'is_published'   => true,
        ]);
    }

    private function stateOf(string $email): string
    {
        return (string) $this->db()->value(
            'SELECT state FROM {event_signups} WHERE event_id = ? AND email = ?',
            [$this->eventId, $email]
        );
    }

    // ------------------------------------------------------------ THE LOCK

    /**
     * THE RULE, under genuine concurrency: no overbooking.
     *
     * Six processes, six connections, three places, one shared start time.
     * Exactly three must be going and three waiting — and the count of places
     * taken must never exceed the capacity, which is the assertion that fails
     * loudly if the lock is ever removed.
     */
    public function testConcurrentSignupsNeverOverbookTheHall(): void
    {
        $php = $this->phpBinary();

        if ($php === null) {
            self::markTestSkipped('No PHP binary available to start a second process.');
        }

        $startAt = microtime(true) + 2.0;

        $procs = [];
        $pipes = [];

        for ($i = 0; $i < 6; $i++) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $command = sprintf(
                '%s %s %s %s %s %d %s 0 %.6f',
                escapeshellarg($php),
                escapeshellarg(__DIR__ . '/support/sign-up-worker.php'),
                escapeshellarg($this->workerDsn()),
                escapeshellarg($this->workerUser()),
                escapeshellarg($this->workerPassword()),
                $this->eventId,
                escapeshellarg("racer{$i}@example.test"),
                $startAt
            );

            $handle = proc_open($command, $descriptors, $procPipes);
            self::assertIsResource($handle, "could not start worker {$i}");

            $procs[$i] = $handle;
            $pipes[$i] = $procPipes;
        }

        $outcomes = [];

        foreach ($procs as $i => $handle) {
            $out = trim((string) stream_get_contents($pipes[$i][1]));
            $err = trim((string) stream_get_contents($pipes[$i][2]));

            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($handle);

            self::assertStringNotContainsString('ERROR', $out, "worker {$i}: {$out} {$err}");
            self::assertNotSame('', $out, "worker {$i} said nothing. stderr: {$err}");

            $outcomes[] = $out;
        }

        $going = array_keys($outcomes, Signup::GOING, true);

        self::assertCount(
            3,
            $going,
            'expected three in and three waiting, got: ' . implode(', ', $outcomes)
        );

        /*
         * And the hall itself. The tally above can be right while the rows are
         * wrong — this is the assertion about the thing that matters, which is
         * how many people turn up.
         */
        self::assertSame(
            3,
            $this->events->taken($this->eventId),
            'MORE PEOPLE ARE GOING THAN THE HALL HOLDS'
        );

        self::assertSame(6, (int) $this->db()->value('SELECT COUNT(*) FROM {event_signups}'));
    }

    /** The same race with parties, where a lost update costs more than one seat. */
    public function testConcurrentPartiesNeverOverbookEither(): void
    {
        $php = $this->phpBinary();

        if ($php === null) {
            self::markTestSkipped('No PHP binary available to start a second process.');
        }

        $this->events->setCapacity($this->eventId, 6);

        $startAt = microtime(true) + 2.0;
        $procs = [];
        $pipes = [];

        // Four parties of three into six places: two fit, two must not.
        for ($i = 0; $i < 4; $i++) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $command = sprintf(
                '%s %s %s %s %s %d %s 2 %.6f',
                escapeshellarg($php),
                escapeshellarg(__DIR__ . '/support/sign-up-worker.php'),
                escapeshellarg($this->workerDsn()),
                escapeshellarg($this->workerUser()),
                escapeshellarg($this->workerPassword()),
                $this->eventId,
                escapeshellarg("party{$i}@example.test"),
                $startAt
            );

            $handle = proc_open($command, $descriptors, $procPipes);
            self::assertIsResource($handle);

            $procs[$i] = $handle;
            $pipes[$i] = $procPipes;
        }

        $outcomes = [];

        foreach ($procs as $i => $handle) {
            $outcomes[] = trim((string) stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($handle);
        }

        self::assertCount(2, array_keys($outcomes, Signup::GOING, true), implode(', ', $outcomes));
        self::assertSame(6, $this->events->taken($this->eventId));
    }

    // ------------------------------------------------------ ordinary paths

    public function testSigningUpTakesAPlace(): void
    {
        $result = $this->events->signUp($this->eventId, 'Alice', 'alice@example.test');

        self::assertTrue($result->isGoing());
        self::assertSame(1, $this->events->taken($this->eventId));
    }

    /** A guest counts as a place, because a guest sits somewhere. */
    public function testAGuestTakesAPlaceToo(): void
    {
        $this->events->signUp($this->eventId, 'Alice', 'alice@example.test', 2);

        self::assertSame(3, $this->events->taken($this->eventId), 'guests were not counted');
    }

    public function testTheHallFillsAndTheRestWait(): void
    {
        $this->events->signUp($this->eventId, 'Alice', 'alice@example.test', 2);

        $second = $this->events->signUp($this->eventId, 'Bob', 'bob@example.test');

        self::assertFalse($second->isGoing());
        self::assertSame(Signup::WAITING, $second->state);
        self::assertSame(3, $this->events->taken($this->eventId), 'a waiting place was counted as a seat');
    }

    /** More guests than the event allows is refused, in words. */
    public function testTooManyGuestsIsRefusedWithTheNumber(): void
    {
        $small = $this->event(['max_guests' => 1, 'capacity' => 50]);

        try {
            $this->events->signUp($small, 'Alice', 'alice@example.test', 4);
            self::fail('an unlimited party was accepted');
        } catch (HttpException $e) {
            self::assertStringContainsString('up to 1', $e->getMessage());
        }
    }

    /** Signing up twice edits the one row rather than making a second. */
    public function testSigningUpAgainUpdatesTheSamePerson(): void
    {
        $this->events->signUp($this->eventId, 'Alice', 'alice@example.test');
        $this->events->signUp($this->eventId, 'Alice Smith', 'alice@example.test', 1);

        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {event_signups}'));
        self::assertSame(2, $this->events->taken($this->eventId), 'the party change was not counted');
        self::assertSame(
            'Alice Smith',
            $this->db()->value('SELECT name FROM {event_signups} WHERE email = ?', ['alice@example.test'])
        );
    }

    /**
     * Their own place is not counted against them when they change their party.
     *
     * Without this, somebody already going who adds a guest is refused for want
     * of the place they are sitting in.
     */
    public function testChangingYourPartyDoesNotCompeteWithYourself(): void
    {
        $this->events->signUp($this->eventId, 'Alice', 'alice@example.test', 2);

        $again = $this->events->signUp($this->eventId, 'Alice', 'alice@example.test', 2);

        self::assertTrue($again->isGoing(), 'somebody was bumped by their own booking');
    }

    // -------------------------------------------------------- the queue

    /**
     * A cancellation moves the queue, and the ids that moved come back so they
     * can be told.
     */
    public function testCancellingMovesTheQueueUp(): void
    {
        $this->events->signUp($this->eventId, 'A', 'a@example.test', 2);
        $this->events->signUp($this->eventId, 'B', 'b@example.test');

        self::assertSame(Signup::WAITING, $this->stateOf('b@example.test'));

        $moved = $this->events->cancel($this->eventId, 'a@example.test');

        self::assertCount(1, $moved, 'nobody was moved up');
        self::assertSame(Signup::GOING, $this->stateOf('b@example.test'));
    }

    /** THE RULE again, this time through the database. */
    public function testTheQueueDoesNotOvertakeAPartyThatDoesNotFit(): void
    {
        $this->events->setCapacity($this->eventId, 4);

        $this->events->signUp($this->eventId, 'Full', 'full@example.test', 3);
        $this->events->signUp($this->eventId, 'Family', 'family@example.test', 3);
        $this->events->signUp($this->eventId, 'Couple', 'couple@example.test', 1);

        self::assertSame(Signup::WAITING, $this->stateOf('family@example.test'));
        self::assertSame(Signup::WAITING, $this->stateOf('couple@example.test'));

        // Two of the four places come free. The family of four still does not
        // fit, so nobody moves — including the couple, who do.
        $this->events->setCapacity($this->eventId, 6);
        $moved = $this->events->promote($this->eventId);

        self::assertSame([], $moved, 'THE COUPLE OVERTOOK THE FAMILY');
        self::assertSame(Signup::WAITING, $this->stateOf('couple@example.test'));
    }

    /** A capacity rise moves the queue, which the spec names alongside cancelling. */
    public function testRaisingTheCapacityMovesTheQueue(): void
    {
        $this->events->signUp($this->eventId, 'A', 'a@example.test', 2);
        $this->events->signUp($this->eventId, 'B', 'b@example.test');

        self::assertSame(Signup::WAITING, $this->stateOf('b@example.test'));

        $this->events->setCapacity($this->eventId, 4);

        self::assertCount(1, $this->events->promote($this->eventId));
        self::assertSame(Signup::GOING, $this->stateOf('b@example.test'));
    }

    /** Removing the limit altogether lets everybody in. */
    public function testRemovingTheLimitAdmitsTheWholeQueue(): void
    {
        $this->events->signUp($this->eventId, 'A', 'a@example.test', 2);
        $this->events->signUp($this->eventId, 'B', 'b@example.test');
        $this->events->signUp($this->eventId, 'C', 'c@example.test', 4);

        $this->events->setCapacity($this->eventId, null);

        self::assertCount(2, $this->events->promote($this->eventId));
        self::assertSame(Signup::GOING, $this->stateOf('c@example.test'));
    }

    /** Cancelling something already cancelled moves nobody. */
    public function testCancellingTwiceDoesNothingTheSecondTime(): void
    {
        $this->events->signUp($this->eventId, 'A', 'a@example.test');
        $this->events->cancel($this->eventId, 'a@example.test');

        self::assertSame([], $this->events->cancel($this->eventId, 'a@example.test'));
    }

    // ---------------------------------------------------------- visibility

    /**
     * A members-only event is absent from a stranger's list rather than
     * refused. Leaving it in and rejecting the click would tell them there is
     * an event and what it is called.
     */
    public function testAMembersOnlyEventIsAbsentForAStranger(): void
    {
        $this->event(['title' => 'Members Evening', 'member_only' => true]);

        $titles = array_map(
            static fn (array $row): string => (string) $row['title'],
            $this->events->upcoming(false)
        );

        self::assertNotContains('Members Evening', $titles);
        self::assertContains('Harvest Supper', $titles);

        $memberTitles = array_map(
            static fn (array $row): string => (string) $row['title'],
            $this->events->upcoming(true)
        );

        self::assertContains('Members Evening', $memberTitles);
    }

    public function testADraftEventIsAbsentUntilItIsPublished(): void
    {
        $id = $this->event(['title' => 'Not Announced', 'is_published' => false]);

        self::assertNotContains(
            'Not Announced',
            array_map(static fn (array $r): string => (string) $r['title'], $this->events->upcoming(true))
        );

        $this->events->publish($id, true);

        self::assertContains(
            'Not Announced',
            array_map(static fn (array $r): string => (string) $r['title'], $this->events->upcoming(true))
        );
    }

    // ------------------------------------------------------------ helpers

    private function phpBinary(): ?string
    {
        $binary = PHP_BINARY !== '' && is_file(PHP_BINARY) ? PHP_BINARY : (getenv('PORTAL_PHP') ?: '');

        return $binary !== '' && is_file($binary) ? $binary : null;
    }

    private function workerDsn(): string
    {
        $parts = parse_url((string) getenv('PORTAL_TEST_DB'));

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $parts['host'] ?? '127.0.0.1',
            $parts['port'] ?? 3306,
            (string) $this->db()->value('SELECT DATABASE()')
        );
    }

    private function workerUser(): string
    {
        $parts = parse_url((string) getenv('PORTAL_TEST_DB'));

        return (string) ($parts['user'] ?? 'root');
    }

    private function workerPassword(): string
    {
        $parts = parse_url((string) getenv('PORTAL_TEST_DB'));

        return (string) ($parts['pass'] ?? '');
    }
}
