<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Auth\ClaimGate;

/**
 * Membership asserted by the identity provider, and how it combines with the
 * address list.
 *
 * The combining rule is the part worth pinning. `either` means one gate is
 * enough, and the way to get it wrong is to let an UNCONFIGURED gate count as
 * one of the two — an unconfigured gate refuses nobody, so ORing it with a
 * configured one waves everybody past the check that is actually switched on.
 */
final class ClaimGateTest extends TestCase
{
    // ---------------------------------------------------------------- parsing

    public function testAcceptedValuesAreSplitOnTheUsualSeparators(): void
    {
        self::assertSame(
            ['org_a', 'org_b', 'org_c'],
            ClaimGate::parseValues("org_a, org_b\n org_c")
        );
    }

    /**
     * Case is kept. These are opaque identifiers from somebody else's system,
     * and two that differ only in case are two different organizations —
     * folding them would silently accept one nobody named.
     */
    public function testCaseIsNotFolded(): void
    {
        self::assertSame(['org_A1b2', 'org_a1b2'], ClaimGate::parseValues('org_A1b2, org_a1b2'));
    }

    public function testEmptyInputAcceptsNothing(): void
    {
        self::assertSame([], ClaimGate::parseValues('   '));
    }

    // ---------------------------------------------------------------- deciding

    public function testAMemberOfAnAcceptedOrganizationPasses(): void
    {
        self::assertNull(ClaimGate::decide(true, 'org_a', ['org_a', 'org_b']));
    }

    public function testAMemberOfSomethingElseIsRefused(): void
    {
        self::assertSame(
            ClaimGate::NOT_A_MEMBER,
            ClaimGate::decide(true, 'org_z', ['org_a'])
        );
    }

    /**
     * A missing claim is its own answer. It is usually a scope or a setting at
     * the provider rather than the person being in the wrong place, and those
     * are two different afternoons.
     */
    public function testAnAbsentClaimIsNotTheSameAsTheWrongOne(): void
    {
        self::assertSame(ClaimGate::NO_CLAIM, ClaimGate::decide(true, null, ['org_a']));
        self::assertSame(ClaimGate::NO_CLAIM, ClaimGate::decide(true, '', ['org_a']));

        self::assertNotSame(
            ClaimGate::explain(ClaimGate::NO_CLAIM),
            ClaimGate::explain(ClaimGate::NOT_A_MEMBER)
        );
    }

    /**
     * No accepted values means the check is off, not that nothing is accepted.
     *
     * The alternative is that clearing the field refuses the entire site, from
     * a screen whose "save" looks like it did nothing.
     */
    public function testClearingTheValuesTurnsTheCheckOffRatherThanRefusingEveryone(): void
    {
        self::assertNull(ClaimGate::decide(true, 'org_a', []));
        self::assertNull(ClaimGate::decide(true, null, []));
    }

    public function testNothingIsRefusedWhileTheCheckIsOff(): void
    {
        self::assertNull(ClaimGate::decide(false, 'org_z', ['org_a']));
    }

    // --------------------------------------------------------------- combining

    public function testUnderAllBothMustPass(): void
    {
        self::assertNull(ClaimGate::combine(ClaimGate::ALL, null, null));
        self::assertSame('x', ClaimGate::combine(ClaimGate::ALL, 'x', null));
        self::assertSame('y', ClaimGate::combine(ClaimGate::ALL, null, 'y'));
    }

    public function testUnderEitherOnePassIsEnough(): void
    {
        self::assertNull(ClaimGate::combine(ClaimGate::EITHER, 'x', null));
        self::assertNull(ClaimGate::combine(ClaimGate::EITHER, null, 'y'));
        self::assertNull(ClaimGate::combine(ClaimGate::EITHER, null, null));
    }

    /**
     * Refused under `either` means BOTH said no, and it says so — because there
     * are then two ways to fix it and naming one sends somebody down half the
     * path.
     */
    public function testUnderEitherARefusalNamesBothRoutes(): void
    {
        $reason = ClaimGate::combine(ClaimGate::EITHER, 'x', 'y');

        self::assertSame(ClaimGate::NEITHER, $reason);
        self::assertStringContainsString('nor', ClaimGate::explain($reason));
        self::assertStringContainsString('Either would be enough', ClaimGate::explain($reason));
    }

    /**
     * A typo must not loosen a boundary.
     *
     * The failure this chooses is somebody being refused and asking why, rather
     * than somebody getting in and nobody asking anything.
     */
    public function testAnUnrecognisedModeIsTheStrictOne(): void
    {
        self::assertSame(ClaimGate::ALL, ClaimGate::normalizeMode('EITHER'));
        self::assertSame(ClaimGate::ALL, ClaimGate::normalizeMode('any'));
        self::assertSame(ClaimGate::ALL, ClaimGate::normalizeMode(''));
        self::assertSame('x', ClaimGate::combine('nonsense', 'x', 'y'));
    }

    /** And there is no mode that switches both checks off. */
    public function testNoModeDisablesBothChecks(): void
    {
        foreach ([ClaimGate::ALL, ClaimGate::EITHER, 'off', 'none', ''] as $mode) {
            self::assertNotNull(
                ClaimGate::combine($mode, 'x', 'y'),
                "mode '{$mode}' let somebody through who failed both checks"
            );
        }
    }

    // ------------------------------------------------------ authorize parameter

    /** One organization: send it, and the provider renders that login directly. */
    public function testTheParameterIsSentForExactlyOneOrganization(): void
    {
        self::assertSame('org_a', ClaimGate::authorizeValue(ClaimGate::ALL, ['org_a']));
    }

    /**
     * Several: withheld, so the provider shows its own picker rather than this
     * site choosing one of them on the person's behalf.
     */
    public function testTheParameterIsWithheldWhenSeveralAreAccepted(): void
    {
        self::assertNull(ClaimGate::authorizeValue(ClaimGate::ALL, ['org_a', 'org_b']));
    }

    /**
     * And withheld under `either`, whatever the count.
     *
     * Sending it makes the provider refuse a non-member at its own door, before
     * this site can check its allowlist — so the personal-account route that
     * mode exists to provide would be unreachable.
     */
    public function testTheParameterIsWithheldUnderEither(): void
    {
        self::assertNull(ClaimGate::authorizeValue(ClaimGate::EITHER, ['org_a']));
    }

    public function testNothingIsSentWhenNothingIsAccepted(): void
    {
        self::assertNull(ClaimGate::authorizeValue(ClaimGate::ALL, []));
    }
}
