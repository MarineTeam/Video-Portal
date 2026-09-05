<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Rota\Assignment;
use Portal\Rota\RotaRepository;

/**
 * Handing a slot over, including under real concurrency.
 *
 * The section's rule is that two people pressing "I'll take it" in the same
 * second must produce ONE WINNER AND ONE HONEST REFUSAL, not a lost update
 * where the second write silently wins. The spec says to test that with real
 * concurrency rather than mocks, and it is right to insist — a single-threaded
 * test cannot watch the second write lose, because by the time it runs the
 * first has already finished and the second is simply operating on the new
 * state. Every assertion would pass against a read-then-write.
 *
 * So testConcurrentTakersProduceExactlyOneWinner() spawns operating-system
 * processes with their own database connections and a shared start time.
 */
final class RotaCoverTest extends DatabaseTestCase
{
    private RotaRepository $rota;
    private int $teamId;
    private int $serviceId;
    private int $asker;
    private int $assignmentId;

    protected function setUp(): void
    {
        $this->truncate([
            'rota_blockouts', 'rota_assignments', 'rota_team_members',
            'rota_positions', 'rota_services', 'rota_teams', 'users',
        ]);

        $this->rota = new RotaRepository($this->db());

        $this->asker = $this->person('asker@example.test', 'Asker');
        $this->teamId = $this->rota->createTeam('Welcome');
        $this->serviceId = $this->rota->createService('Sunday Morning', '2099-10-04 10:00');
        $this->rota->publishService($this->serviceId, true);

        $this->rota->ask($this->serviceId, $this->teamId, $this->asker);
        $this->assignmentId = $this->rota->forService($this->serviceId)[0]->id;
        $this->rota->answer($this->assignmentId, $this->asker, Assignment::ACCEPTED);
    }

    private function person(string $email, string $name): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('users', [
            'email'      => $email,
            'name'       => $name,
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function slot(): array
    {
        return (array) $this->db()->first(
            'SELECT * FROM {rota_assignments} WHERE id = ?',
            [$this->assignmentId]
        );
    }

    // ---------------------------------------------------- THE RACE

    /**
     * THE RULE, under genuine concurrency.
     *
     * Six processes, six connections, one slot, one shared start time. Exactly
     * one must come back TAKEN and the rest GONE — and the row must show the
     * winner, not the last writer.
     *
     * The count is asserted from the PROCESSES and from the DATABASE, because
     * those are different claims: a read-then-write can produce five refusals
     * and still leave the wrong person in the row if the reports and the writes
     * disagree.
     */
    public function testConcurrentTakersProduceExactlyOneWinnerAndHonestRefusals(): void
    {
        $php = $this->phpBinary();

        if ($php === null) {
            self::markTestSkipped('PORTAL_PHP is not set, so no second process can be started.');
        }

        $takers = [];
        for ($i = 0; $i < 6; $i++) {
            $takers[] = $this->person("taker{$i}@example.test", "Taker {$i}");
        }

        $this->rota->requestCover($this->assignmentId, $this->asker, 'Sorry — away that weekend.');

        // Far enough ahead that every process is spun up and waiting. A start
        // line the last worker misses is a queue, not a race.
        $startAt = microtime(true) + 2.0;

        $procs = [];
        $pipes = [];

        foreach ($takers as $index => $takerId) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $command = sprintf(
                '%s %s %s %s %s %d %d %.6f',
                escapeshellarg($php),
                escapeshellarg(__DIR__ . '/support/take-cover-worker.php'),
                escapeshellarg($this->workerDsn()),
                escapeshellarg($this->workerUser()),
                escapeshellarg($this->workerPassword()),
                $this->assignmentId,
                $takerId,
                $startAt
            );

            $handle = proc_open($command, $descriptors, $procPipes);

            self::assertIsResource($handle, "could not start worker {$index}");

            $procs[$index] = $handle;
            $pipes[$index] = $procPipes;
        }

        $outcomes = [];

        foreach ($procs as $index => $handle) {
            $out = trim((string) stream_get_contents($pipes[$index][1]));
            $err = trim((string) stream_get_contents($pipes[$index][2]));

            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            proc_close($handle);

            self::assertNotSame('', $out, "worker {$index} said nothing. stderr: {$err}");
            self::assertStringNotContainsString('ERROR', $out, "worker {$index}: {$out} {$err}");

            $outcomes[] = $out;
        }

        $taken = array_keys($outcomes, RotaRepository::TAKEN, true);

        /*
         * EXACTLY ONE WINNER. This is the assertion the whole test exists for,
         * and it is the one that fails loudly if the conditional WHERE is ever
         * removed: without it every process matches the row and all six come
         * back "taken", which is the lost update stated as a number.
         */
        self::assertCount(
            1,
            $taken,
            'expected exactly one winner, got: ' . implode(', ', $outcomes)
        );

        /*
         * And every loser was told honestly. Which of the two refusals they get
         * depends on when their read landed relative to the winner's write —
         * "somebody took it" and "nothing is open" are both true and neither is
         * a silent failure. The first version of this test demanded they all be
         * GONE and failed against correct code, which is over-specification:
         * the rule is one winner and honest refusals, not which words.
         */
        foreach ($outcomes as $index => $outcome) {
            self::assertContains(
                $outcome,
                [RotaRepository::TAKEN, RotaRepository::GONE, RotaRepository::NOT_OPEN],
                "worker {$index} answered something that is neither a win nor a refusal: {$outcome}"
            );
        }

        // And the row agrees with the reports. A lost update can produce the
        // right tally and still leave the wrong person serving.
        $slot = $this->slot();
        $winner = $takers[$taken[0]];

        self::assertSame($winner, (int) $slot['user_id'], 'the row holds somebody who was told they lost');
        self::assertSame($this->asker, (int) $slot['covering_for_user_id']);
        self::assertNull($slot['cover_requested_at'], 'the slot is still advertised as needing cover');
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {rota_assignments}'));
    }

    // ------------------------------------------------ the ordinary paths

    public function testTakingCoverMovesTheSlotAndRecordsWhoItIsFor(): void
    {
        $taker = $this->person('taker@example.test', 'Taker');

        $this->rota->requestCover($this->assignmentId, $this->asker);

        self::assertSame(RotaRepository::TAKEN, $this->rota->takeCover($this->assignmentId, $taker));

        $slot = $this->slot();
        self::assertSame($taker, (int) $slot['user_id']);
        self::assertSame($this->asker, (int) $slot['covering_for_user_id']);
        self::assertSame(Assignment::ACCEPTED, (string) $slot['state']);
    }

    /**
     * THE RULE: the old note does not follow the slot.
     *
     * It was the previous person's aside to the organiser. Carrying it onto the
     * new holder's row would attribute one person's words to another — and the
     * words are usually personal, which is what makes this more than tidiness.
     */
    public function testThePreviousPersonsNoteDoesNotFollowTheSlot(): void
    {
        $taker = $this->person('taker@example.test', 'Taker');

        $this->rota->requestCover($this->assignmentId, $this->asker, 'My sister is getting married.');

        self::assertSame(
            'My sister is getting married.',
            (string) $this->slot()['cover_note'],
            'the fixture did not store a note, so the next assertion proves nothing'
        );

        $this->rota->takeCover($this->assignmentId, $taker);

        self::assertNull($this->slot()['cover_note'], 'ONE PERSON\'S PRIVATE REASON MOVED TO ANOTHER');
        self::assertNull($this->slot()['reason'], 'the previous answer stayed on somebody else\'s row');
    }

    /** A slot nobody asked to be covered cannot be taken. */
    public function testASlotWithNoCoverRequestCannotBeTaken(): void
    {
        $taker = $this->person('taker@example.test', 'Taker');

        self::assertSame(
            RotaRepository::NOT_OPEN,
            $this->rota->takeCover($this->assignmentId, $taker)
        );

        self::assertSame($this->asker, (int) $this->slot()['user_id']);
    }

    /** Somebody already on the service is refused, in words rather than as a key error. */
    public function testSomebodyAlreadyOnTheServiceCannotCover(): void
    {
        $other = $this->person('other@example.test', 'Other');
        $this->rota->ask($this->serviceId, $this->teamId, $other);

        $this->rota->requestCover($this->assignmentId, $this->asker);

        self::assertSame(
            RotaRepository::ALREADY_ON,
            $this->rota->takeCover($this->assignmentId, $other)
        );

        self::assertSame($this->asker, (int) $this->slot()['user_id']);
    }

    /** Only the holder may ask for cover, and only once they have accepted. */
    public function testOnlyTheHolderWhoAcceptedCanAskForCover(): void
    {
        $other = $this->person('other@example.test', 'Other');

        self::assertFalse($this->rota->requestCover($this->assignmentId, $other));

        $second = $this->rota->createService('Another', '2099-11-01 10:00');
        $this->rota->ask($second, $this->teamId, $this->asker);
        $unanswered = $this->rota->forService($second)[0]->id;

        self::assertFalse(
            $this->rota->requestCover($unanswered, $this->asker),
            'an unanswered ask was handed on rather than declined'
        );
    }

    /** Asking and then changing your mind puts it back. */
    public function testACoverRequestCanBeWithdrawn(): void
    {
        $this->rota->requestCover($this->assignmentId, $this->asker, 'Might be away');

        self::assertTrue($this->rota->cancelCoverRequest($this->assignmentId, $this->asker));
        self::assertNull($this->slot()['cover_requested_at']);
        self::assertNull($this->slot()['cover_note']);

        $taker = $this->person('taker@example.test', 'Taker');
        self::assertSame(RotaRepository::NOT_OPEN, $this->rota->takeCover($this->assignmentId, $taker));
    }

    /** The list of slots going spare is what the team acts on. */
    public function testASlotAppearsOnTheCoverListUntilItIsTaken(): void
    {
        self::assertSame([], $this->rota->coverWanted());

        $this->rota->requestCover($this->assignmentId, $this->asker);
        self::assertCount(1, $this->rota->coverWanted());

        $taker = $this->person('taker@example.test', 'Taker');
        $this->rota->takeCover($this->assignmentId, $taker);

        self::assertSame([], $this->rota->coverWanted(), 'a taken slot is still being advertised');
    }

    /** A draft service's slots are not offered — nobody has been asked yet. */
    public function testADraftServiceDoesNotAdvertiseCover(): void
    {
        $this->rota->requestCover($this->assignmentId, $this->asker);
        $this->rota->publishService($this->serviceId, false);

        self::assertSame([], $this->rota->coverWanted());
    }

    // ------------------------------------------------------------ helpers

    private function phpBinary(): ?string
    {
        /*
         * PHP_BINARY is the running interpreter and is the right answer
         * whenever it is available — PORTAL_PHP is the fallback for a runner
         * that does not expose one.
         */
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
            // The database this class is actually using, which the base class
            // creates fresh per class — not the one named in the DSN.
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
