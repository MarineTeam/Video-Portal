<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Support\SecretGuard;
use Portal\Tv\Pairing;
use Portal\Tv\PairingCode;
use Portal\Tv\PairingRepository;

/**
 * Television pairings, against real rows.
 *
 * What can only be seen from here: the device code is nowhere in the database,
 * a pairing is good for exactly one sign-in, and the conditional UPDATEs decide
 * rather than a read followed by a write.
 */
final class TvPairingTest extends DatabaseTestCase
{
    private PairingRepository $pairings;
    private int $userId;

    protected function setUp(): void
    {
        $this->truncate(['tv_pairings', 'users']);

        $this->pairings = new PairingRepository($this->db());

        $now = date('Y-m-d H:i:s');
        $this->userId = $this->db()->insert('users', [
            'email' => 'viewer@example.test', 'name' => 'Viewer', 'authorized' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // ------------------------------------------------- the long one is hashed

    /**
     * THE RULE. The device code is nowhere in the database.
     *
     * It is what turns into a session and the television keeps it in a config
     * file for years, so a pairing table readable from a backup would otherwise
     * be a list of ways to become somebody.
     */
    public function testTheDeviceCodeIsNowhereInTheDatabase(): void
    {
        $made = $this->pairings->start('Living room');

        $everything = (string) json_encode($this->db()->all('SELECT * FROM {tv_pairings}'));

        self::assertStringNotContainsString(
            $made['device_code'],
            $everything,
            'THE DEVICE CODE IS STORED — it leaks with any backup'
        );

        // And the hash that IS stored is not the code.
        self::assertNotSame(
            $made['device_code'],
            (string) $this->db()->value('SELECT device_hash FROM {tv_pairings}')
        );
    }

    /** It still authenticates, which is the point of the hash. */
    public function testADeviceCodeFindsItsOwnPairing(): void
    {
        $made = $this->pairings->start();

        $row = $this->pairings->byDeviceCode($made['device_code']);

        self::assertNotNull($row);
        self::assertSame(Pairing::PENDING, Pairing::state($row));
    }

    public function testSomethingThatIsNotADeviceCodeFindsNothing(): void
    {
        $this->pairings->start();

        foreach (['', 'nope', str_repeat('z', 64), str_repeat('a', 63), 'Bearer x'] as $notOne) {
            self::assertNull($this->pairings->byDeviceCode($notOne), $notOne);
        }
    }

    /**
     * The short one IS in the clear, and that is deliberate.
     *
     * It is on a screen in a room — hashing it would protect it from a reader
     * of the database while it is being broadcast to everybody present. What
     * limits it is a ten-minute life and five attempts, and storing it plainly
     * is what lets the approval screen say "that code has expired" rather than
     * just "no".
     */
    public function testTheCodeOnTheScreenIsStoredPlainlyOnPurpose(): void
    {
        $made = $this->pairings->start();

        self::assertSame(
            $made['user_code'],
            (string) $this->db()->value('SELECT user_code FROM {tv_pairings}')
        );
    }

    /** A pairing row carries nothing the guard forbids handing out. */
    public function testAPairingListingIsSafeToHandToAScreen(): void
    {
        $made = $this->pairings->start('Living room');
        $this->pairings->approve(
            (int) $this->pairings->byDeviceCode($made['device_code'])['id'],
            $this->userId
        );
        $this->pairings->claim(
            (int) $this->pairings->byDeviceCode($made['device_code'])['id']
        );

        self::assertTrue(
            SecretGuard::isClean($this->pairings->forUser($this->userId)),
            'the television listing carries something the guard forbids'
        );
    }

    // -------------------------------------------------------- typing the code

    /** THE OTHER RULE, end to end: a misread code still finds its pairing. */
    public function testACodeReadWronglyStillFindsItsPairing(): void
    {
        $made = $this->pairings->start();
        $code = $made['user_code'];

        /*
         * Read the way somebody across a room reads it — every 0 as an O and
         * every 1 as an I — and then typed in lower case with the separator.
         * If the mapping is missing, this finds nothing and the television
         * never comes on.
         */
        $misread = strtolower(strtr($code, ['0' => 'O', '1' => 'I']));
        $misread = implode('-', str_split($misread, 4));

        $row = $this->pairings->byUserCode($misread);

        self::assertNotNull(
            $row,
            'A MISREAD CODE FOUND NOTHING — which is the commonest thing that happens'
        );
        self::assertSame($code, (string) $row['user_code']);
    }

    public function testNonsenseFindsNothingAndAsksNothing(): void
    {
        $this->pairings->start();

        foreach (['', 'ABC', '!!!!!!!!', 'ABCD23456789'] as $nonsense) {
            self::assertNull($this->pairings->byUserCode($nonsense), $nonsense);
        }
    }

    // ------------------------------------------------- approving and claiming

    public function testApprovingThenClaimingSignsInTheRightPerson(): void
    {
        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        self::assertTrue($this->pairings->approve($id, $this->userId));
        self::assertSame($this->userId, $this->pairings->claim($id));
    }

    /**
     * A PAIRING IS GOOD FOR ONE SIGN-IN.
     *
     * The television keeps its device code in a config file where it stays, so
     * a pairing that could be claimed twice is a replayable session. Claimed in
     * the same statement that reads the approver, which is what makes it hold
     * when two polls arrive together.
     */
    public function testAPairingCanOnlyBeClaimedOnce(): void
    {
        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        $this->pairings->approve($id, $this->userId);

        self::assertSame($this->userId, $this->pairings->claim($id));
        self::assertNull(
            $this->pairings->claim($id),
            'A PAIRING WAS CLAIMED TWICE — the device code is a permanent session'
        );
    }

    /**
     * AND A MINUTE LATER, which is the version that can actually see the guard.
     *
     * The check above passes with `AND claimed_at IS NULL` DELETED, and the
     * reason is MySQL rather than the application: PDO reports rows CHANGED,
     * not matched, so a second claim in the same second writes the same NOW()
     * over itself, changes nothing, and reports zero. The test was passing on a
     * timing coincidence.
     *
     * The real threat is not two claims in one second. The television keeps its
     * device code in a config file for YEARS, so the claim that matters is the
     * one that arrives later — after a reboot, or on a stored code somebody
     * copied. Backdating claimed_at stages exactly that, deterministically, and
     * the mutation that removes the guard fails it.
     *
     * This is the third time in this project a guard's whole purpose has been a
     * condition the obvious test cannot stage — after Notifier::claim() and
     * VisitTracker::roll() — and the first time the thing hiding it was the
     * database's own affected-rows semantics.
     */
    public function testAPairingClaimedEarlierCannotBeClaimedAgain(): void
    {
        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        $this->pairings->approve($id, $this->userId);
        self::assertSame($this->userId, $this->pairings->claim($id));

        // Claimed a minute ago rather than this instant, so a second claim
        // would genuinely CHANGE the row if nothing stopped it.
        $this->db()->execute(
            'UPDATE {tv_pairings} SET claimed_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?',
            [$id]
        );

        self::assertNull(
            $this->pairings->claim($id),
            'A PAIRING CLAIMED EARLIER WAS CLAIMED AGAIN — the device code in a television\'s '
            . 'config file is a session that never ends'
        );
    }

    /** And a claimed one reads CLAIMED, so the poll stops asking. */
    public function testAClaimedPairingReportsItself(): void
    {
        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        $this->pairings->approve($id, $this->userId);
        $this->pairings->claim($id);

        self::assertSame(
            Pairing::CLAIMED,
            Pairing::state($this->pairings->byDeviceCode($made['device_code']))
        );
    }

    /** Nothing unapproved is claimable, whatever a caller believes. */
    public function testAnUnapprovedPairingIsNotClaimable(): void
    {
        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        self::assertNull(
            $this->pairings->claim($id),
            'A TELEVISION SIGNED ITSELF IN WITHOUT ANYBODY SAYING YES'
        );
    }

    /**
     * THE RULE, at the database.
     *
     * An expired pairing cannot be claimed even though it was approved — which
     * is enforced twice on purpose: Pairing::state() stops the controller
     * getting there, and the UPDATE's own WHERE stops anything else. The state
     * check is the one a reader sees; this is the one that holds when a second
     * caller arrives.
     */
    public function testAnExpiredPairingCannotBeClaimedEvenThoughItWasApproved(): void
    {
        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        $this->pairings->approve($id, $this->userId);

        // Wind it back past its life, as the clock would.
        $this->db()->execute(
            'UPDATE {tv_pairings} SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?',
            [$id]
        );

        self::assertNull(
            $this->pairings->claim($id),
            'AN EXPIRED PAIRING WAS STILL GOOD — a session from a credential that died'
        );

        self::assertSame(
            Pairing::EXPIRED,
            Pairing::state($this->pairings->byDeviceCode($made['device_code']))
        );
    }

    /** Nor can an expired one be approved. */
    public function testAnExpiredPairingCannotBeApproved(): void
    {
        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        $this->db()->execute(
            'UPDATE {tv_pairings} SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?',
            [$id]
        );

        self::assertFalse($this->pairings->approve($id, $this->userId));
    }

    /**
     * Two people pressing approve: the first one's decision stands.
     *
     * The ordinary case when two people are looking at the same television, and
     * a plain UPDATE would let the second overwrite who did it.
     */
    public function testASecondApprovalDoesNotRewriteTheFirst(): void
    {
        $now = date('Y-m-d H:i:s');
        $other = $this->db()->insert('users', [
            'email' => 'other@example.test', 'name' => 'Other', 'authorized' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        self::assertTrue($this->pairings->approve($id, $this->userId));
        self::assertFalse(
            $this->pairings->approve($id, $other),
            'the second approval reported itself as the one that did it'
        );

        self::assertSame(
            $this->userId,
            $this->pairings->claim($id),
            'THE SECOND PERSON\'S ACCOUNT WAS THE ONE SIGNED IN'
        );
    }

    /** Too many wrong codes and the pairing stops being approvable. */
    public function testAPairingStopsAcceptingCodesAfterTooManyTries(): void
    {
        $made = $this->pairings->start();
        $id = (int) $this->pairings->byUserCode($made['user_code'])['id'];

        for ($i = 0; $i < Pairing::MAX_ATTEMPTS; $i++) {
            $this->pairings->noteAttempt($id);
        }

        self::assertSame(
            Pairing::BLOCKED,
            Pairing::state($this->pairings->byUserCode($made['user_code']))
        );

        self::assertFalse(
            $this->pairings->approve($id, $this->userId),
            'A BLOCKED PAIRING COULD STILL BE APPROVED'
        );
    }

    // -------------------------------------------------------------- the codes

    /** Two live pairings never show the same code. */
    public function testTwoLivePairingsNeverShowTheSameCode(): void
    {
        $codes = [];

        for ($i = 0; $i < 30; $i++) {
            $codes[] = $this->pairings->start()['user_code'];
        }

        self::assertCount(
            30,
            array_unique($codes),
            'TWO TELEVISIONS SHOWED THE SAME CODE — one person would approve the other\'s set'
        );
    }

    /**
     * A code repeating one from an expired pairing resolves to the LIVE one.
     *
     * The user code is unique among live rows only, which MySQL cannot express
     * — so the lookup takes the newest and Pairing::state() then reports the
     * old one expired anyway. Staged by hand, because waiting for a genuine
     * collision would take a very long time.
     */
    public function testACodeThatRepeatsAnExpiredOneResolvesToTheLiveOne(): void
    {
        /*
         * BOTH rows are written by hand, in order, rather than one through
         * start().
         *
         * Two earlier attempts got the ordering wrong in two different ways: an
         * explicit low id collided with the live row's on a truncated table, and
         * moving the live row to 9999 pushed AUTO_INCREMENT past it so the
         * "older" row was inserted with a higher id and was genuinely the
         * newest. What the test needs is a defined order, and the only way to
         * have one is to choose both ids.
         */
        $code = 'ABCD2345';

        $this->db()->insert('tv_pairings', [
            'id'          => 100,
            'user_code'   => $code,
            'device_hash' => hash('sha256', 'the old one'),
            'expires_at'  => date('Y-m-d H:i:s', time() - 86400),
            'created_at'  => date('Y-m-d H:i:s', time() - 90000),
        ]);

        $live = 200;

        $this->db()->insert('tv_pairings', [
            'id'          => $live,
            'user_code'   => $code,
            'device_hash' => hash('sha256', 'the live one'),
            'expires_at'  => date('Y-m-d H:i:s', time() + 600),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $found = $this->pairings->byUserCode($code);

        self::assertNotNull($found);
        self::assertSame(
            $live,
            (int) $found['id'],
            'AN EXPIRED PAIRING SHADOWED A LIVE ONE — the television would never come on'
        );
    }

    // ------------------------------------------------------------ the cleanup

    /**
     * Dead pairings are cleared, and live ones are not.
     *
     * Both directions, because a purge that deleted everything would pass a
     * check that only counted what went.
     */
    public function testThePurgeClearsTheDeadAndLeavesTheLiving(): void
    {
        $live = $this->pairings->start();

        $this->db()->insert('tv_pairings', [
            'user_code'   => 'DEADDEAD',
            'device_hash' => hash('sha256', 'dead'),
            'expires_at'  => date('Y-m-d H:i:s', time() - (3 * 86400)),
            'created_at'  => date('Y-m-d H:i:s', time() - (3 * 86400)),
        ]);

        self::assertSame(1, $this->pairings->purge());

        self::assertNotNull(
            $this->pairings->byDeviceCode($live['device_code']),
            'THE PURGE TOOK A LIVE PAIRING — a television mid-sign-in'
        );
    }

    /**
     * And an expired one survives the day after, so "already used" is still
     * answerable to somebody who typed it twice.
     */
    public function testAJustExpiredPairingIsKeptLongEnoughToExplainItself(): void
    {
        $this->db()->insert('tv_pairings', [
            /*
             * Every character is IN the alphabet, which matters: a fixture
             * code containing a letter the alphabet excludes — FRESHISH, as
             * this was first written — normalises to something that was never
             * stored, and the lookup then reports UNKNOWN. The test failed
             * and the code was right.
             */
            'user_code'   => 'FRESHXSH',
            'device_hash' => hash('sha256', 'freshish'),
            'expires_at'  => date('Y-m-d H:i:s', time() - 3600),
            'created_at'  => date('Y-m-d H:i:s', time() - 7200),
        ]);

        self::assertSame(0, $this->pairings->purge());

        self::assertSame(
            Pairing::EXPIRED,
            Pairing::state($this->pairings->byUserCode('FRESHXSH')),
            'somebody typing it again would be told it is not one of ours'
        );
    }

    /** Only televisions this person signed in, and only claimed ones. */
    public function testTheListingIsThisPersonsClaimedTelevisions(): void
    {
        $now = date('Y-m-d H:i:s');
        $other = $this->db()->insert('users', [
            'email' => 'other@example.test', 'name' => 'Other', 'authorized' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Theirs, claimed.
        $mine = $this->pairings->start('Mine');
        $mineId = (int) $this->pairings->byDeviceCode($mine['device_code'])['id'];
        $this->pairings->approve($mineId, $this->userId);
        $this->pairings->claim($mineId);

        // Somebody else's, claimed.
        $theirs = $this->pairings->start('Theirs');
        $theirsId = (int) $this->pairings->byDeviceCode($theirs['device_code'])['id'];
        $this->pairings->approve($theirsId, $other);
        $this->pairings->claim($theirsId);

        // Theirs, approved but never collected — not a television that is
        // signed in, so not on the list.
        $pending = $this->pairings->start('Never Collected');
        $pendingId = (int) $this->pairings->byDeviceCode($pending['device_code'])['id'];
        $this->pairings->approve($pendingId, $this->userId);

        $labels = array_map(
            static fn (array $row): string => (string) $row['device_label'],
            $this->pairings->forUser($this->userId)
        );

        self::assertSame(['Mine'], $labels);
    }
}
