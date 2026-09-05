<?php

declare(strict_types=1);

/**
 * One person pressing "I'll take it", in its own process.
 *
 * The spec for this section says the conditional write must be tested with real
 * concurrency rather than mocks, and it is right to insist: a single-threaded
 * test can never watch the second write lose. The query has already been
 * satisfied by the time it runs again, so every assertion passes against a
 * read-then-write that would corrupt the rota the moment two people were
 * willing to help — which is the whole failure this rule exists to prevent.
 *
 * This project has the general form of that lesson on record from
 * `Notifier::claim()`: when a guard's whole purpose is a condition the test
 * cannot stage, expose the guard and test it directly. Here "directly" means
 * several operating-system processes, separate database connections, and a
 * shared start time they all wait for.
 *
 * Not named *Test.php, so PHPUnit does not collect it.
 *
 * Usage: php take-cover-worker.php <dsn> <user> <pass> <assignmentId> <takerId> <startAtMicros>
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Portal\Db;
use Portal\Rota\RotaRepository;

[$self, $dsn, $user, $pass, $assignmentId, $takerId, $startAt] = array_pad($argv, 7, '');

$db = new Db($dsn, $user, $pass, '');

/*
 * Connect and prepare BEFORE the start line, so the race is between the
 * UPDATEs and not between two connection handshakes. Without this the first
 * process to finish its TCP setup wins every time and the test would pass
 * against any implementation at all.
 */
$db->value('SELECT 1');

$startAt = (float) $startAt;

// Spin rather than sleep for the last stretch: usleep resolution is coarse
// enough on Windows that a 10ms grid would stagger the workers into a queue.
while (microtime(true) < $startAt) {
    if ($startAt - microtime(true) > 0.05) {
        usleep(5_000);
    }
}

$rota = new RotaRepository($db);

try {
    $outcome = $rota->takeCover((int) $assignmentId, (int) $takerId);
} catch (Throwable $e) {
    // Reported rather than thrown, so the parent can tell "the write raced
    // badly" from "the process died" — two very different findings.
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}

echo $outcome . "\n";
