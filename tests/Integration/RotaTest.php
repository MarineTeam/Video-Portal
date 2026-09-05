<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Http\HttpException;
use Portal\Rota\Assignment;
use Portal\Rota\AskOutcome;
use Portal\Rota\RotaRepository;

/**
 * Asking somebody to serve.
 *
 * Against a real database because the rules are about rows that already exist —
 * a person already asked, a blockout spanning a date — and because the unique
 * key is the backstop the code is deliberately not relying on. A double has no
 * unique key, so a mock would report the "already on this service" rule passing
 * whether the code checked or not.
 */
final class RotaTest extends DatabaseTestCase
{
    private RotaRepository $rota;
    private int $teamId;
    private int $serviceId;
    private int $alice;
    private int $bob;

    protected function setUp(): void
    {
        $this->truncate([
            'rota_blockouts', 'rota_assignments', 'rota_team_members',
            'rota_positions', 'rota_services', 'rota_teams', 'users',
        ]);

        $this->rota = new RotaRepository($this->db());

        $this->alice = $this->person('alice@example.test', 'Alice');
        $this->bob = $this->person('bob@example.test', 'Bob');

        $this->teamId = $this->rota->createTeam('Welcome');
        $this->serviceId = $this->rota->createService('Sunday Morning', '2026-10-04 10:00');
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

    // ------------------------------------------------ THE REFUSAL RULE

    /**
     * THE RULE: already on that service is a refusal, said in words.
     *
     * The message is asserted, not just the throw. A caught constraint error
     * would also throw — and would reach the organiser as "something went
     * wrong", where the true answer tells them what to do next.
     */
    public function testAskingSomebodyAlreadyOnTheServiceIsRefusedInWords(): void
    {
        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);

        try {
            $this->rota->ask($this->serviceId, $this->teamId, $this->alice);
            self::fail('the same person was asked twice for one service');
        } catch (HttpException $e) {
            self::assertStringContainsString('already on this service', $e->getMessage());
            // Their name, so the organiser knows which of the six they just
            // ticked was the problem.
            self::assertStringContainsString('Alice', $e->getMessage());
        }

        self::assertSame(1, $this->countAsks());
    }

    /** Even for a different team — a person is on a service once, not once per team. */
    public function testTheRefusalHoldsAcrossTeams(): void
    {
        $other = $this->rota->createTeam('Sound');

        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);

        $this->expectException(HttpException::class);
        $this->rota->ask($this->serviceId, $other, $this->alice);
    }

    /** And the same person on a DIFFERENT service is fine, which is the point. */
    public function testTheSamePersonCanServeAnotherService(): void
    {
        $second = $this->rota->createService('Sunday Evening', '2026-10-04 18:30');

        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);
        $this->rota->ask($second, $this->teamId, $this->alice);

        self::assertSame(2, $this->countAsks());
    }

    // ------------------------------------------------ THE WARNING RULE

    /**
     * THE RULE: a blockout WARNS and does not refuse.
     *
     * The instinct is to enforce it, and enforcing it is wrong: the organiser
     * often knows something the calendar does not, and a builder who cannot get
     * past a blockout will delete somebody else's instead.
     *
     * Both halves in one test, because a check that only asserts the warning
     * cannot tell "warned and allowed" from "warned and blocked".
     */
    public function testABlockoutWarnsAndTheAskStillHappens(): void
    {
        $this->rota->addBlockout($this->alice, '2026-10-01', '2026-10-07', 'At a wedding');

        $outcome = $this->rota->ask($this->serviceId, $this->teamId, $this->alice);

        self::assertTrue($outcome->isWarning(), 'no warning was raised');
        self::assertStringContainsString('cannot serve that day', $outcome->message);
        self::assertStringContainsString('At a wedding', $outcome->message, 'the reason was dropped');
        self::assertStringContainsString('still ask', $outcome->message);

        // THE HALF THAT MATTERS: it went ahead.
        self::assertSame(1, $this->countAsks(), 'the warning refused the ask');
    }

    /** A blockout that does not span the day says nothing at all. */
    public function testABlockoutOnOtherDaysIsNotAWarning(): void
    {
        $this->rota->addBlockout($this->alice, '2026-11-01', '2026-11-07', 'Away');

        $outcome = $this->rota->ask($this->serviceId, $this->teamId, $this->alice);

        self::assertFalse($outcome->isWarning());
        self::assertSame(AskOutcome::ALLOWED, $outcome->state);
    }

    /** Inclusive at both ends — "the 3rd to the 10th" includes the 10th. */
    public function testABlockoutIncludesItsLastDay(): void
    {
        $lastDay = $this->rota->createService('On the last day', '2026-10-07 10:00');
        $firstDay = $this->rota->createService('On the first day', '2026-10-01 10:00');

        $this->rota->addBlockout($this->alice, '2026-10-01', '2026-10-07');

        self::assertTrue($this->rota->wouldAsk($lastDay, $this->alice)->isWarning());
        self::assertTrue($this->rota->wouldAsk($firstDay, $this->alice)->isWarning());
    }

    /**
     * The warning is available BEFORE the ask, which is the only arrangement
     * in which it is any use.
     */
    public function testTheWarningCanBeSeenBeforeAnythingIsWritten(): void
    {
        $this->rota->addBlockout($this->alice, '2026-10-04', '2026-10-04', 'Away');

        self::assertTrue($this->rota->wouldAsk($this->serviceId, $this->alice)->isWarning());
        self::assertSame(0, $this->countAsks(), 'asking what would happen made it happen');
    }

    /** A blockout with no reason still warns — the absence is the warning. */
    public function testABlockoutWithNoReasonStillWarns(): void
    {
        $this->rota->addBlockout($this->alice, '2026-10-04', '2026-10-04');

        $outcome = $this->rota->wouldAsk($this->serviceId, $this->alice);

        self::assertTrue($outcome->isWarning());
        self::assertStringNotContainsString('()', $outcome->message, 'empty brackets for no reason');
    }

    /** Blockouts belong to one person and warn about nobody else. */
    public function testOnePersonsBlockoutDoesNotWarnAboutAnother(): void
    {
        $this->rota->addBlockout($this->alice, '2026-10-04', '2026-10-04', 'Away');

        self::assertFalse($this->rota->wouldAsk($this->serviceId, $this->bob)->isWarning());
    }

    public function testRemovingABlockoutIsKeyedToItsOwner(): void
    {
        $id = $this->rota->addBlockout($this->alice, '2026-10-04', '2026-10-04');

        self::assertFalse($this->rota->removeBlockout($id, $this->bob), 'somebody else deleted it');
        self::assertCount(1, $this->rota->blockouts($this->alice));

        self::assertTrue($this->rota->removeBlockout($id, $this->alice));
        self::assertCount(0, $this->rota->blockouts($this->alice));
    }

    /** Backwards dates are swapped rather than refused. */
    public function testBackwardsDatesAreReadTheWayTheyWereMeant(): void
    {
        $this->rota->addBlockout($this->alice, '2026-10-07', '2026-10-01');

        self::assertTrue($this->rota->wouldAsk($this->serviceId, $this->alice)->isWarning());
    }

    // ---------------------------------------------------------- answering

    public function testOnlyThePersonAskedCanAnswer(): void
    {
        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);
        $id = $this->rota->forService($this->serviceId)[0]->id;

        self::assertFalse(
            $this->rota->answer($id, $this->bob, Assignment::ACCEPTED),
            'somebody answered for another person'
        );
        self::assertSame(Assignment::INVITED, $this->rota->forService($this->serviceId)[0]->state);

        self::assertTrue($this->rota->answer($id, $this->alice, Assignment::ACCEPTED, 'Glad to'));

        $answered = $this->rota->forService($this->serviceId)[0];
        self::assertSame(Assignment::ACCEPTED, $answered->state);
        self::assertSame('Glad to', $answered->reason);
        self::assertNotNull($answered->answeredAt);
    }

    /** No is an answer with a reason too, which is what lets the builder move on. */
    public function testDecliningKeepsTheReason(): void
    {
        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);
        $id = $this->rota->forService($this->serviceId)[0]->id;

        $this->rota->answer($id, $this->alice, Assignment::DECLINED, 'Away that weekend');

        $answered = $this->rota->forService($this->serviceId)[0];
        self::assertSame(Assignment::DECLINED, $answered->state);
        self::assertSame('Away that weekend', $answered->reason);
    }

    /** There is no way back to "not asked". */
    public function testAnAnswerCannotBeInvited(): void
    {
        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);
        $id = $this->rota->forService($this->serviceId)[0]->id;

        $this->expectException(HttpException::class);
        $this->rota->answer($id, $this->alice, Assignment::INVITED);
    }

    /** An unrecognised state reads as invited, never as accepted. */
    public function testAnUnknownStateIsNotTakenAsAcceptance(): void
    {
        self::assertSame(Assignment::INVITED, Assignment::normalizeState('confirmed'));
        self::assertSame(Assignment::INVITED, Assignment::normalizeState(''));
        self::assertSame(Assignment::ACCEPTED, Assignment::normalizeState('ACCEPTED'));
    }

    // ----------------------------------------------------------- listings

    /**
     * The chasing list is unanswered asks on PUBLISHED, future services.
     *
     * A draft service full of unanswered asks is a rota that has not been sent
     * out, not people being slow — listing it would have the organiser chasing
     * themselves.
     */
    public function testTheChasingListIgnoresDraftServices(): void
    {
        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);

        self::assertSame([], $this->rota->unanswered(), 'a draft service was on the chasing list');

        $this->rota->publishService($this->serviceId, true);

        self::assertCount(1, $this->rota->unanswered());
    }

    public function testAnAnsweredAskLeavesTheChasingList(): void
    {
        $this->rota->publishService($this->serviceId, true);
        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);
        $id = $this->rota->forService($this->serviceId)[0]->id;

        self::assertCount(1, $this->rota->unanswered());

        $this->rota->answer($id, $this->alice, Assignment::DECLINED, 'Sorry');

        // Declining is answering. The builder needs somebody else, and the
        // chasing list is about silence rather than about who is coming.
        self::assertSame([], $this->rota->unanswered());
    }

    /** A person's own list carries unanswered asks — that is what it is for. */
    public function testAPersonSeesWhatTheyHaveNotAnswered(): void
    {
        $this->rota->publishService($this->serviceId, true);
        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);

        $mine = $this->rota->forPerson($this->alice, false);

        self::assertCount(1, $mine);
        self::assertSame('Sunday Morning', $mine[0]['service_title']);
        self::assertSame(Assignment::INVITED, $mine[0]['state']);
    }

    // -------------------------------------------------------------- teams

    /**
     * Leaving a team does not erase what somebody already agreed to.
     *
     * A rota that rewrote its own past would be lying about who was there.
     */
    public function testLeavingATeamKeepsTheAsksAlreadyMade(): void
    {
        $this->rota->addMember($this->teamId, $this->alice);
        $this->rota->ask($this->serviceId, $this->teamId, $this->alice);

        $this->rota->removeMember($this->teamId, $this->alice);

        self::assertSame(1, $this->countAsks(), 'removing somebody from a team erased their service');
        self::assertSame([], $this->rota->members($this->teamId));
    }

    /** Adding somebody twice sets their usual job rather than failing. */
    public function testAddingAMemberAgainUpdatesTheirUsualPosition(): void
    {
        $sound = $this->rota->addPosition($this->teamId, 'Sound desk');

        $this->rota->addMember($this->teamId, $this->alice);
        $this->rota->addMember($this->teamId, $this->alice, $sound);

        $members = $this->rota->members($this->teamId);

        self::assertCount(1, $members);
        self::assertSame('Sound desk', $members[0]['position_name']);
    }

    private function countAsks(): int
    {
        return (int) $this->db()->value('SELECT COUNT(*) FROM {rota_assignments}');
    }
}
