<?php

declare(strict_types=1);

/**
 * One person pressing "sign me up", in its own process.
 *
 * The spec's rule for this section: four people pressing the button in the same
 * second get ONE YES AND THREE WAITING-LIST PLACES, not four yeses and an
 * overbooked hall. It then says exactly where the bug lives — "read capacity
 * outside the lock and write inside it and you have built the version that
 * overbooks" — which is a defect no single-threaded test can see, because the
 * read and the write are never interleaved with anybody else's.
 *
 * So: real processes, real connections, one shared start time.
 *
 * Not named *Test.php, so PHPUnit does not collect it.
 *
 * Usage: php sign-up-worker.php <dsn> <user> <pass> <eventId> <email> <guests> <startAtMicros>
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Portal\Db;
use Portal\Events\EventRepository;

[$self, $dsn, $user, $pass, $eventId, $email, $guests, $startAt] = array_pad($argv, 8, '');

$db = new Db($dsn, $user, $pass, '');

// Connect before the start line, so the race is between the transactions and
// not between two TCP handshakes.
$db->value('SELECT 1');

$startAt = (float) $startAt;

while (microtime(true) < $startAt) {
    if ($startAt - microtime(true) > 0.05) {
        usleep(5_000);
    }
}

try {
    $result = (new EventRepository($db))->signUp(
        (int) $eventId,
        'Racer ' . $email,
        $email,
        (int) $guests
    );
} catch (Throwable $e) {
    echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}

echo $result->state . "\n";
