<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\AccessRequests;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\UserRepository;

/**
 * Asking for access.
 *
 * Against a real database because the property that matters is a PRIMARY KEY
 * doing a job that looks like application logic: one request per person, with
 * the *first* one being the only one that may reach an inbox. A fake would
 * assert the code I wrote rather than the constraint that enforces it.
 */
final class AccessRequestsTest extends DatabaseTestCase
{
    private AccessRequests $requests;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->truncate([
            'access_requests', 'grants', 'group_members', 'group_capabilities',
            'permission_groups', 'role_capabilities', 'capabilities', 'roles', 'users',
        ]);

        (new PermissionSeeder($this->db()))->seed();

        $this->requests = new AccessRequests($this->db());
        $this->users = new UserRepository($this->db());
    }

    private function pendingUser(string $email): int
    {
        return $this->users->create($email, null, 'viewer', null, false)->id;
    }

    // -------------------------------------------------------------- fire once

    /**
     * The guard the notification hangs on.
     *
     * The return value is not decoration — it is the only thing standing
     * between a button shown to any stranger who can authenticate and a way to
     * mail the administrators on demand. Tested by asking twice, because a
     * single call can never watch the guard refuse.
     */
    public function testOnlyTheFirstRequestReportsItselfAsNew(): void
    {
        $id = $this->pendingUser('first@example.com');

        self::assertTrue($this->requests->submit($id, 'Let me in please'));
        self::assertFalse($this->requests->submit($id, 'Asking again'));
        self::assertFalse($this->requests->submit($id, 'And again'));
    }

    public function testAskingAgainKeepsOneRowAndUpdatesWhatTheySaid(): void
    {
        $id = $this->pendingUser('second@example.com');

        $this->requests->submit($id, 'First attempt');
        $this->requests->submit($id, 'Actually, I am on the Thursday team');

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {access_requests} WHERE user_id = ?', [$id]),
            'a person who clicks twice must not become two rows'
        );
        self::assertSame('Actually, I am on the Thursday team', $this->requests->noteFor($id));
    }

    /**
     * Two people asking are two requests. A guard that deduplicated across
     * accounts would silence everybody after the first.
     */
    public function testDifferentPeopleEachGetTheirOwnFirstAsk(): void
    {
        self::assertTrue($this->requests->submit($this->pendingUser('a@example.com'), ''));
        self::assertTrue($this->requests->submit($this->pendingUser('b@example.com'), ''));
    }

    // ------------------------------------------------------------- the listing

    /**
     * An answered question is not a pending one.
     *
     * pending() filters on {users}.authorized rather than on anything stored
     * here, so approving somebody through any route — this screen, a direct
     * database edit, a future bulk action — takes them off the list without
     * anything having to remember to.
     */
    public function testApprovingSomeoneTakesThemOffThePendingList(): void
    {
        $id = $this->pendingUser('waiting@example.com');
        $this->requests->submit($id, 'please');

        self::assertCount(1, $this->requests->pending());

        $this->users->setAuthorized($id, true, 'admin@example.com');

        self::assertSame([], $this->requests->pending(), 'the row outlived the question');
    }

    public function testClearingRemovesTheRowEntirely(): void
    {
        $id = $this->pendingUser('cleared@example.com');
        $this->requests->submit($id, 'please');

        $this->requests->clear($id);

        self::assertFalse($this->requests->has($id));
        self::assertNull($this->requests->noteFor($id));
    }

    /**
     * And after clearing, the next ask is a first ask again. Otherwise somebody
     * approved and later revoked could never be heard from again.
     */
    public function testAfterClearingAPersonMayAskAfresh(): void
    {
        $id = $this->pendingUser('again@example.com');

        $this->requests->submit($id, 'please');
        $this->requests->clear($id);

        self::assertTrue($this->requests->submit($id, 'please, again'));
    }

    public function testDeletingTheAccountTakesTheRequestWithIt(): void
    {
        $id = $this->pendingUser('gone@example.com');
        $this->requests->submit($id, 'please');

        $this->db()->execute('DELETE FROM {users} WHERE id = ?', [$id]);

        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {access_requests}'),
            'the foreign key cascade is what stops this table outliving its accounts'
        );
    }

    public function testPendingIsOldestFirst(): void
    {
        $first = $this->pendingUser('older@example.com');
        $second = $this->pendingUser('newer@example.com');

        $this->requests->submit($first, 'one');
        $this->requests->submit($second, 'two');

        // Written explicitly: both rows land inside the same second on a fast
        // machine, so NOW() alone cannot order them and the assertion would
        // pass or fail on timing.
        $this->db()->execute(
            'UPDATE {access_requests} SET created_at = ? WHERE user_id = ?',
            ['2026-01-01 09:00:00', $first]
        );
        $this->db()->execute(
            'UPDATE {access_requests} SET created_at = ? WHERE user_id = ?',
            ['2026-06-01 09:00:00', $second]
        );

        $emails = array_map(static fn (array $r): string => (string) $r['email'], $this->requests->pending());

        self::assertSame(['older@example.com', 'newer@example.com'], $emails);
    }

    // ------------------------------------------------------------------ batch

    public function testNotesForReadsAWholeListingInOneQuery(): void
    {
        $a = $this->pendingUser('one@example.com');
        $b = $this->pendingUser('two@example.com');
        $c = $this->pendingUser('three@example.com');

        $this->requests->submit($a, 'first note');
        $this->requests->submit($b, '');

        $before = $this->db()->queryCount();
        $notes = $this->requests->notesFor([$a, $b, $c]);
        $cost = $this->db()->queryCount() - $before;

        self::assertSame('first note', $notes[$a]);
        self::assertSame('', $notes[$b], 'asking without a message is still asking');
        self::assertArrayNotHasKey($c, $notes, 'somebody who never asked has no entry');
        self::assertSame(1, $cost, 'one query for the listing, not one per row');
    }

    public function testNotesForWithNoAccountsAsksTheDatabaseNothing(): void
    {
        $before = $this->db()->queryCount();

        self::assertSame([], $this->requests->notesFor([]));
        self::assertSame(0, $this->db()->queryCount() - $before);
    }

    // --------------------------------------------------------------- the note

    /**
     * Cleaned on the way in, not only escaped on the way out.
     *
     * The note reaches an HTML page, a plain-text email, and the audit log, and
     * only the first of those has escaping. A NUL or a BEL surviving into an
     * email is a message that renders wrong or trips a spam filter, and neither
     * failure would point back here.
     *
     * Paragraph breaks survive on purpose: somebody writing two sentences about
     * themselves is the point of the field. Whitespace inside a line is left
     * exactly as typed — there is nothing to gain from tidying it and a
     * sanitizer that rewrites what people wrote is one that will eventually
     * rewrite something that mattered.
     */
    public function testTheStoredNoteIsSanitizedNotJustEscapedOnOutput(): void
    {
        $id = $this->pendingUser('messy@example.com');

        $this->requests->submit($id, "  I am on the\x00 Thursday\x07 team\r\n\r\nSam sent me  ");

        self::assertSame("I am on the Thursday team\n\nSam sent me", $this->requests->noteFor($id));
    }

    /**
     * The cap is load-bearing, and only the second ask can prove it.
     *
     * The connection runs with STRICT_ALL_TABLES, so an overlong string is
     * normally an error rather than a silent truncation. But the first ask goes
     * through INSERT IGNORE — chosen for the fire-once guard — and IGNORE
     * downgrades "Data too long" to a warning along with everything else it
     * suppresses. So on that path the database quietly cuts the note and the
     * PHP cap looks redundant.
     *
     * The UPDATE behind a second ask has no IGNORE. Without the cap it raises
     * SQLSTATE 22001 and somebody correcting what they wrote gets an error page
     * instead. Asking twice is what makes the guard observable, which is the
     * only reason this test is shaped the way it is.
     */
    public function testAnOverlongNoteIsCutOnEveryPathIncludingTheSecondAsk(): void
    {
        $id = $this->pendingUser('verbose@example.com');
        $long = str_repeat('a', AccessRequests::MAX_NOTE + 250);

        $this->requests->submit($id, $long);

        self::assertSame(
            AccessRequests::MAX_NOTE,
            mb_strlen((string) $this->requests->noteFor($id))
        );

        // The path with no IGNORE to hide behind.
        $this->requests->submit($id, $long);

        self::assertSame(
            AccessRequests::MAX_NOTE,
            mb_strlen((string) $this->requests->noteFor($id)),
            'correcting an overlong note must not become a database error'
        );
    }

    /**
     * Multibyte-safe, because the cap counts characters and the column does
     * too. Cutting at 500 bytes would split a character in half and leave
     * invalid UTF-8 in a row that MySQL then refuses.
     */
    public function testTheCapCountsCharactersNotBytes(): void
    {
        $id = $this->pendingUser('multibyte@example.com');

        $this->requests->submit($id, str_repeat('é', AccessRequests::MAX_NOTE + 50));

        $stored = (string) $this->requests->noteFor($id);

        self::assertSame(AccessRequests::MAX_NOTE, mb_strlen($stored));
        self::assertSame($stored, mb_convert_encoding($stored, 'UTF-8', 'UTF-8'));
    }
}
