<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Prayer\PrayerName;
use Portal\Prayer\PrayerRepository;
use Portal\Prayer\PrayerRequest;

/**
 * The prayer wall.
 *
 * Two rules carry this and both are promises made to somebody at a bad week of
 * their life: nothing appears until a human has read it, and anonymous means
 * anonymous INCLUDING TO MODERATORS.
 *
 * Against a real database because both are about which rows come back to whom,
 * and the anonymity one is partly enforced by a column never being stored.
 */
final class PrayerTest extends DatabaseTestCase
{
    private PrayerRepository $prayer;

    protected function setUp(): void
    {
        $this->truncate(['prayer_requests', 'users']);

        $this->prayer = new PrayerRepository($this->db());
    }

    private function account(string $email): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('users', [
            'email'      => $email,
            'name'       => 'Jane Cole',
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ------------------------------------------ nothing appears unread

    /**
     * THE RULE. An unmoderated prayer wall on a church website is a liability
     * with a "post" button on it.
     */
    public function testNothingReachesTheWallUntilSomebodyHasReadIt(): void
    {
        $this->prayer->add('Please pray for my mother.', 'Jane', false, PrayerRepository::EVERYONE);

        self::assertSame([], $this->prayer->wall([PrayerRepository::EVERYONE]), 'IT WENT STRAIGHT UP');
        self::assertCount(1, $this->prayer->queue());
        self::assertSame(1, $this->prayer->waitingCount());
    }

    public function testOnceReadItIsOnTheWall(): void
    {
        $id = $this->prayer->add('Please pray for my mother.', 'Jane', false, PrayerRepository::EVERYONE);

        $this->prayer->approve($id, 'sam@example.test');

        self::assertCount(1, $this->prayer->wall([PrayerRepository::EVERYONE]));
        self::assertSame([], $this->prayer->queue());
    }

    /**
     * There is no argument that lets a caller post straight to the wall, and
     * none that lets the wall include pending requests. A rule enforced by the
     * absence of a parameter cannot be switched off by one.
     */
    public function testAddAndWallOfferNoWayRoundModeration(): void
    {
        $add = new \ReflectionMethod(PrayerRepository::class, 'add');
        $wall = new \ReflectionMethod(PrayerRepository::class, 'wall');

        foreach ($add->getParameters() as $parameter) {
            self::assertNotSame('status', $parameter->getName(), 'add() lets a caller choose a status');
        }

        foreach ($wall->getParameters() as $parameter) {
            self::assertNotSame(
                'includePending',
                $parameter->getName(),
                'wall() can be widened to show unread requests'
            );
        }
    }

    // ------------------------------------------- anonymous means anonymous

    /**
     * THE RULE, and the whole reason PrayerRequest is a class rather than an
     * array: a MODERATOR sees "Anonymous" too.
     *
     * "Anonymous except to the people who run the church" is the version people
     * assume they are getting and are not, and it is worse than no anonymity,
     * because they act on the belief.
     */
    public function testAnAnonymousRequestIsAnonymousToTheModeratorToo(): void
    {
        $this->prayer->add('Please pray for me.', 'Jane Cole', true, PrayerRepository::MEMBERS);

        $queued = $this->prayer->queue()[0];

        self::assertSame(PrayerName::ANONYMOUS, $queued->name, 'A MODERATOR CAN SEE WHO ASKED');
        self::assertTrue($queued->isAnonymous);
    }

    /**
     * And the name is not merely hidden — it was never stored.
     *
     * A name kept "just in case" is a name that leaks the first time somebody
     * writes a query by hand, and there is no case: this wall does not follow
     * anybody up.
     */
    public function testAnAnonymousRequestStoresNoNameAndNoAccount(): void
    {
        $userId = $this->account('jane@example.test');
        $id = $this->prayer->add('Please pray.', 'Jane Cole', true, PrayerRepository::MEMBERS, $userId);

        $row = (array) $this->db()->first('SELECT * FROM {prayer_requests} WHERE id = ?', [$id]);

        self::assertNull($row['requester_name'], 'THE NAME WAS STORED ANYWAY');
        self::assertNull($row['user_id'], 'THE ACCOUNT WAS STORED ANYWAY');
        self::assertStringNotContainsString('Jane', (string) json_encode($row));
    }

    /**
     * The visible type cannot carry an identity, so a page that forgets has
     * nothing to leak. This is the structural half of the rule and it is worth
     * asserting directly: a future screen written in a hurry cannot
     * reintroduce the bug by reading the wrong key.
     */
    public function testTheVisibleTypeHasNowhereToPutAnIdentity(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(PrayerRequest::class))->getProperties()
        );

        foreach (['userId', 'user_id', 'email', 'requesterName', 'requester_name'] as $forbidden) {
            self::assertNotContains($forbidden, $properties, "PrayerRequest carries {$forbidden}");
        }
    }

    /** A named request keeps its name, or the feature is just "anonymous". */
    public function testANamedRequestShowsTheName(): void
    {
        $this->prayer->add('Pray for my exams.', 'Sam Ives', false, PrayerRepository::EVERYONE);

        self::assertSame('Sam Ives', $this->prayer->queue()[0]->name);
    }

    /** A blank name is Anonymous rather than a gap that invites guessing. */
    public function testANamelessRequestIsAnonymousRatherThanBlank(): void
    {
        $this->prayer->add('Pray for my exams.', '   ', false, PrayerRepository::EVERYONE);

        self::assertSame(PrayerName::ANONYMOUS, $this->prayer->queue()[0]->name);
    }

    /**
     * An anonymous request cannot be withdrawn, because withdrawing it would
     * mean the site knew whose it was. Stated as a cost, not hidden.
     */
    public function testAnAnonymousRequestIsNotEvenListedAsMine(): void
    {
        $userId = $this->account('jane@example.test');

        $this->prayer->add('Anonymous one.', '', true, PrayerRepository::MEMBERS, $userId);
        $named = $this->prayer->add('Named one.', 'Jane', false, PrayerRepository::MEMBERS, $userId);

        $mine = $this->prayer->mine($userId);

        self::assertCount(1, $mine);
        self::assertSame($named, $mine[0]->id);
    }

    /** And ownership is in the WHERE clause, so an id alone is not enough. */
    public function testSomebodyElsesRequestCannotBeWithdrawn(): void
    {
        $mine = $this->account('jane@example.test');
        $theirs = $this->account('sam@example.test');

        $id = $this->prayer->add('Theirs.', 'Sam', false, PrayerRepository::MEMBERS, $theirs);

        self::assertFalse($this->prayer->withdraw($id, $mine), 'ids are sequential and guessable');
        self::assertTrue($this->prayer->withdraw($id, $theirs));
    }

    // ------------------------------------------------------- visibility

    /**
     * Who may read what, decided in one place. Written as a widening list
     * rather than a comparison, so "leaders see members' requests" is a fact
     * about the list rather than something an operator is trusted to imply.
     */
    public function testEachAudienceSeesItsOwnAndNoMore(): void
    {
        foreach ([
            PrayerRepository::EVERYONE,
            PrayerRepository::MEMBERS,
            PrayerRepository::LEADERS,
        ] as $visibility) {
            $id = $this->prayer->add("A {$visibility} request.", 'Jane', false, $visibility);
            $this->prayer->approve($id, 'sam@example.test');
        }

        $seen = fn (bool $member, bool $leader): int => count(
            $this->prayer->wall(PrayerRepository::readable($member, $leader))
        );

        self::assertSame(1, $seen(false, false), 'a stranger saw more than the public ones');
        self::assertSame(2, $seen(true, false), 'a member saw the wrong number');
        self::assertSame(3, $seen(true, true));
    }

    /**
     * An unrecognised visibility becomes the STRICTEST, not the loosest. The
     * safe way to be wrong about a prayer request is to show it to fewer
     * people.
     */
    public function testAnUnknownVisibilityIsTheStrictest(): void
    {
        $id = $this->prayer->add('Pray.', 'Jane', false, 'the-whole-internet');
        $this->prayer->approve($id, 'sam@example.test');

        self::assertSame([], $this->prayer->wall([PrayerRepository::EVERYONE]));
        self::assertCount(1, $this->prayer->wall(PrayerRepository::readable(true, true)));
    }

    // --------------------------------------------------------- answered

    /**
     * An answered request STAYS UP with a note. Taking it down removes the half
     * of the wall worth reading — and the half that makes somebody put the next
     * one up.
     */
    public function testAnAnsweredRequestStaysOnTheWall(): void
    {
        $id = $this->prayer->add('Pray for the operation.', 'Jane', false, PrayerRepository::EVERYONE);
        $this->prayer->approve($id, 'sam@example.test');
        $this->prayer->markAnswered($id, 'It went well — thank you all.');

        $wall = $this->prayer->wall([PrayerRepository::EVERYONE]);

        self::assertCount(1, $wall, 'AN ANSWERED REQUEST DISAPPEARED');
        self::assertTrue($wall[0]->isAnswered());
        self::assertSame('It went well — thank you all.', $wall[0]->answerNote);
    }

    // ------------------------------------------------- I prayed for this

    /**
     * A COUNT, and there is no table of who. The only way to be certain a list
     * cannot leak is not to keep one.
     */
    public function testPrayingIsACountAndNotAList(): void
    {
        $id = $this->prayer->add('Pray.', 'Jane', false, PrayerRepository::EVERYONE);
        $this->prayer->approve($id, 'sam@example.test');

        self::assertTrue($this->prayer->pray($id));
        self::assertTrue($this->prayer->pray($id));

        self::assertSame(2, (int) $this->prayer->find($id)?->prayedCount);

        $tables = array_map(
            static fn (array $row): string => (string) reset($row),
            $this->db()->all('SHOW TABLES')
        );

        foreach ($tables as $table) {
            self::assertStringNotContainsString(
                'prayer_prayed',
                $table,
                'THERE IS A LIST OF WHO PRAYED'
            );
        }
    }

    /**
     * A request that is not on the wall cannot be counted against.
     *
     * Otherwise a crafted request could increment a pending one and learn from
     * the answer that it exists.
     */
    public function testAPendingRequestCannotBePrayedFor(): void
    {
        $id = $this->prayer->add('Pray.', 'Jane', false, PrayerRepository::EVERYONE);

        self::assertFalse($this->prayer->pray($id));
        self::assertSame(0, (int) $this->prayer->find($id)?->prayedCount);
    }

    // --------------------------------------------------------- removing

    /**
     * A removed request is kept as a row rather than deleted, so a moderator
     * can see what they removed — and so a second moderator does not find it
     * waiting in the queue with no sign it was dealt with.
     */
    public function testARemovedRequestIsKeptAndIsOnNoWall(): void
    {
        $id = $this->prayer->add('Pray.', 'Jane', false, PrayerRepository::EVERYONE);
        $this->prayer->approve($id, 'sam@example.test');
        $this->prayer->remove($id);

        self::assertSame([], $this->prayer->wall(PrayerRepository::readable(true, true)));
        self::assertSame([], $this->prayer->queue());
        self::assertCount(1, $this->prayer->removed());
    }
}
