<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Groups\GroupAddress;
use Portal\Groups\GroupCard;
use Portal\Groups\GroupRepository;

/**
 * Small groups: who gets the address, and who holds a place.
 *
 * Against a real database because both rules are about which rows are counted
 * and which are not — and because the promotion one produces a wrong answer
 * only on the SECOND run, which no single-step reasoning catches.
 */
final class SmallGroupTest extends DatabaseTestCase
{
    private GroupRepository $groups;
    private int $groupId;

    protected function setUp(): void
    {
        $this->truncate(['small_group_members', 'small_groups', 'users']);

        $this->groups = new GroupRepository($this->db());
        $this->groupId = $this->groups->create('Tuesday night');

        $this->groups->update($this->groupId, [
            'area'     => 'Northside',
            'address'  => '14 Elm Row, Northside',
            'capacity' => 3,
        ]);
    }

    private function person(string $name): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('users', [
            'email'      => strtolower($name) . '@example.test',
            'name'       => $name,
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return (array) $this->groups->find($this->groupId);
    }

    // -------------------------------------------------------- the address

    /**
     * THE RULE. A group that meets in somebody's living room must never publish
     * where they live.
     */
    public function testTheAddressIsNotOnTheTypeEveryPageReceives(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(GroupCard::class))->getProperties()
        );

        self::assertNotContains('address', $properties, 'GroupCard carries an address');
        self::assertContains('area', $properties, 'the public half went missing too');
    }

    /**
     * HAVING MERELY ASKED DOES NOT QUALIFY, and neither does waiting.
     *
     * Otherwise anybody with an account learns where a leader lives by pressing
     * a button, which is the whole thing being guarded against.
     */
    public function testOnlyPeopleActuallyInTheGroupGetTheAddress(): void
    {
        $group = $this->row();

        foreach ([
            null,
            GroupRepository::REQUESTED,
            GroupRepository::WAITING,
            GroupRepository::DECLINED,
            GroupRepository::LEFT,
        ] as $state) {
            self::assertNull(
                GroupAddress::for($group, $state),
                sprintf('somebody in state "%s" was given a home address', (string) $state)
            );
        }

        foreach ([GroupRepository::MEMBER, GroupRepository::LEADING] as $state) {
            self::assertSame('14 Elm Row, Northside', GroupAddress::for($group, $state), $state);
        }
    }

    /** A group with no address recorded gives nobody one. */
    public function testAGroupWithNoAddressGivesNobodyOne(): void
    {
        $this->groups->update($this->groupId, ['address' => '']);

        self::assertNull(GroupAddress::for($this->row(), GroupRepository::MEMBER));
    }

    /**
     * The state a leader is reported as is the one the address function looks
     * for, so no caller can check the state and forget the role.
     */
    public function testALeaderIsReportedAsLeadingRatherThanAsAMember(): void
    {
        $sam = $this->person('Sam');
        $this->groups->setLeader($this->groupId, $sam, true);

        self::assertSame(GroupRepository::LEADING, $this->groups->stateOf($this->groupId, $sam));
        self::assertTrue(GroupAddress::mayHaveIt($this->groups->stateOf($this->groupId, $sam)));
    }

    // -------------------------------------------- a request holds a place

    /**
     * THE RULE, and the one the spec says was found by a database test rather
     * than by reading.
     */
    public function testAnUnansweredRequestHoldsAPlace(): void
    {
        $this->groups->ask($this->groupId, $this->person('Ada'));

        self::assertSame(1, $this->groups->taken($this->groupId), 'an unanswered ask held no place');
    }

    /** And a "no" gives it back. */
    public function testADeclineGivesThePlaceBack(): void
    {
        $ada = $this->person('Ada');
        $this->groups->ask($this->groupId, $ada);
        $this->groups->decline($this->groupId, $ada);

        self::assertSame(0, $this->groups->taken($this->groupId));
    }

    public function testLeavingGivesThePlaceBackToo(): void
    {
        $ada = $this->person('Ada');
        $this->groups->ask($this->groupId, $ada);
        $this->groups->accept($this->groupId, $ada);

        self::assertSame(1, $this->groups->taken($this->groupId));

        $this->groups->leave($this->groupId, $ada);

        self::assertSame(0, $this->groups->taken($this->groupId));
    }

    /** Asking when there is no room puts somebody on the list rather than refusing. */
    public function testAFullGroupPutsTheNextPersonOnTheList(): void
    {
        foreach (['Ada', 'Bea', 'Cy'] as $name) {
            self::assertSame(
                GroupRepository::REQUESTED,
                $this->groups->ask($this->groupId, $this->person($name))
            );
        }

        self::assertSame(
            GroupRepository::WAITING,
            $this->groups->ask($this->groupId, $this->person('Dee')),
            'the fourth person was let in past a capacity of three'
        );
    }

    /** Asking twice edits the ask rather than making a second one. */
    public function testAskingTwiceIsOneAsk(): void
    {
        $ada = $this->person('Ada');

        $this->groups->ask($this->groupId, $ada, 'First note');
        $this->groups->ask($this->groupId, $ada, 'Second note');

        self::assertSame(1, $this->groups->taken($this->groupId), 'one person took two places');
        self::assertSame(
            'Second note',
            (string) $this->db()->value(
                'SELECT note FROM {small_group_members} WHERE group_id = ? AND user_id = ?',
                [$this->groupId, $ada]
            )
        );
    }

    // -------------------------------------------------------- promotion

    /**
     * THE OTHER RULE. Promotion moves somebody to a REQUEST, never straight
     * into the group — a place opening is not the leader's yes, and the
     * leader's yes is what the address travels with.
     */
    public function testPromotionOffersAPlaceRatherThanGivingOneAway(): void
    {
        $ada = $this->person('Ada');
        $this->groups->ask($this->groupId, $ada);
        $this->groups->accept($this->groupId, $ada);

        foreach (['Bea', 'Cy'] as $name) {
            $this->groups->accept($this->groupId, $this->askAndReturn($name));
        }

        $dee = $this->person('Dee');
        self::assertSame(GroupRepository::WAITING, $this->groups->ask($this->groupId, $dee));

        $this->groups->leave($this->groupId, $ada);
        $moved = $this->groups->promote($this->groupId);

        self::assertSame([$dee], $moved);
        self::assertSame(
            GroupRepository::REQUESTED,
            $this->groups->stateOf($this->groupId, $dee),
            'PROMOTION PUT SOMEBODY STRAIGHT IN — and handed out a home address on the way'
        );

        // And so they do not have the address yet.
        self::assertNull(GroupAddress::for($this->row(), $this->groups->stateOf($this->groupId, $dee)));
    }

    /**
     * THE BUG THE COUNTING RULE EXISTS FOR, staged.
     *
     * One place frees and there are three people waiting. If a promoted
     * request held no place, the second run would find the same place free and
     * offer it again — and the third, until everybody had been told a place was
     * theirs and all but one of them was wrong.
     *
     * The second promote() is the whole test. A single run cannot see it.
     */
    public function testOnePlaceIsOfferedToOnePersonHoweverOftenPromotionRuns(): void
    {
        $inTheGroup = [];

        foreach (['Ada', 'Bea', 'Cy'] as $name) {
            $id = $this->askAndReturn($name);
            $this->groups->accept($this->groupId, $id);
            $inTheGroup[] = $id;
        }

        $waiting = [];
        foreach (['Dee', 'Eve', 'Fay'] as $name) {
            $id = $this->person($name);
            self::assertSame(GroupRepository::WAITING, $this->groups->ask($this->groupId, $id));
            $waiting[] = $id;
        }

        $this->groups->leave($this->groupId, $inTheGroup[0]);

        $first = $this->groups->promote($this->groupId);
        $second = $this->groups->promote($this->groupId);
        $third = $this->groups->promote($this->groupId);

        self::assertSame([$waiting[0]], $first, 'the first free place went to the wrong number of people');
        self::assertSame([], $second, 'ONE PLACE WAS OFFERED TWICE');
        self::assertSame([], $third, 'ONE PLACE WAS OFFERED THREE TIMES');

        self::assertSame(GroupRepository::WAITING, $this->groups->stateOf($this->groupId, $waiting[1]));
        self::assertSame(GroupRepository::WAITING, $this->groups->stateOf($this->groupId, $waiting[2]));
    }

    /** And a declined promotion hands the place to the next person, not nobody. */
    public function testADeclinedPromotionOffersThePlaceOnwards(): void
    {
        foreach (['Ada', 'Bea', 'Cy'] as $name) {
            $this->groups->accept($this->groupId, $this->askAndReturn($name));
        }

        $dee = $this->person('Dee');
        $eve = $this->person('Eve');
        $this->groups->ask($this->groupId, $dee);
        $this->groups->ask($this->groupId, $eve);

        $this->groups->leave($this->groupId, $this->groups->people($this->groupId)[0]['user_id']);
        self::assertSame([$dee], $this->groups->promote($this->groupId));

        $this->groups->decline($this->groupId, $dee);

        self::assertSame([$eve], $this->groups->promote($this->groupId), 'the place was lost');
    }

    /** The list is answered in order, so waiting means something. */
    public function testTheWaitingListIsAnsweredInOrder(): void
    {
        foreach (['Ada', 'Bea', 'Cy'] as $name) {
            $this->groups->accept($this->groupId, $this->askAndReturn($name));
        }

        $first = $this->person('Dee');
        $this->groups->ask($this->groupId, $first);

        // A second later, so the order is unambiguous rather than decided by
        // whichever row the database happened to return.
        $this->db()->execute(
            'UPDATE {small_group_members} SET requested_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)
              WHERE user_id = ?',
            [$first]
        );

        $second = $this->person('Eve');
        $this->groups->ask($this->groupId, $second);

        $this->groups->leave($this->groupId, $this->groups->people($this->groupId)[0]['user_id']);

        self::assertSame([$first], $this->groups->promote($this->groupId));
    }

    /** A group with no capacity has no waiting list to promote from. */
    public function testAGroupWithNoLimitNeverPutsAnybodyOnAList(): void
    {
        $this->groups->update($this->groupId, ['capacity' => 0]);

        foreach (['Ada', 'Bea', 'Cy', 'Dee', 'Eve'] as $name) {
            self::assertSame(
                GroupRepository::REQUESTED,
                $this->groups->ask($this->groupId, $this->person($name))
            );
        }

        self::assertSame([], $this->groups->promote($this->groupId));
    }

    // ----------------------------------------------------------- leaders

    /**
     * A group with nobody leading it is flagged. It still appears in the
     * directory and still takes requests, and those requests go to nobody.
     */
    public function testAGroupWithNoLeaderIsFlagged(): void
    {
        self::assertCount(1, $this->groups->leaderless());

        $this->groups->setLeader($this->groupId, $this->person('Sam'), true);

        self::assertSame([], $this->groups->leaderless());
    }

    /**
     * Making somebody a leader puts them in the group as well.
     *
     * A leader who is not a member is somebody answering requests to a group
     * they are not in — and, because LEADING is what the address function looks
     * for, somebody holding an address for a house they never visit.
     */
    public function testALeaderIsInTheGroupTheyLead(): void
    {
        $sam = $this->person('Sam');
        $this->groups->setLeader($this->groupId, $sam, true);

        self::assertSame(1, $this->groups->taken($this->groupId));
        self::assertContains('Sam', $this->groups->leaderNames($this->groupId));
    }

    /** Leadership is a row, not a capability — nothing here grants one. */
    public function testLeadingIsARowRatherThanAPermission(): void
    {
        $sam = $this->person('Sam');
        $this->groups->setLeader($this->groupId, $sam, true);

        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {grants} WHERE subject_id = ?', [$sam]),
            'leading a group handed out a site-wide permission'
        );
    }

    private function askAndReturn(string $name): int
    {
        $id = $this->person($name);
        $this->groups->ask($this->groupId, $id);

        return $id;
    }
}
