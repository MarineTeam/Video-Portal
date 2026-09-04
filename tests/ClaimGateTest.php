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
        self::assertNull(ClaimGate::combine(ClaimGate::BOTH, null, null));
        self::assertSame('x', ClaimGate::combine(ClaimGate::BOTH, 'x', null));
        self::assertSame('y', ClaimGate::combine(ClaimGate::BOTH, null, 'y'));
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
        self::assertSame(ClaimGate::BOTH, ClaimGate::normalizeMode('any'));
        self::assertSame(ClaimGate::BOTH, ClaimGate::normalizeMode(''));
        self::assertSame('x', ClaimGate::combine('nonsense', 'x', 'y'));
    }

    /**
     * No mode switches both checks off.
     *
     * Asserted over the four legal values AND over things somebody might type
     * instead, because the point is that there is no reachable value — legal or
     * not — which consults nothing. The way to have no gate is to configure no
     * gate.
     */
    public function testEveryModeCountsAtLeastOneCheck(): void
    {
        $candidates = [
            ClaimGate::BOTH, ClaimGate::ORGANIZATION, ClaimGate::ALLOWLIST, ClaimGate::EITHER,
            'off', 'none', 'neither', 'disabled', '', '   ', 'BOTH ', 'nonsense',
        ];

        foreach ($candidates as $mode) {
            self::assertTrue(
                ClaimGate::countsOrganisation($mode) || ClaimGate::countsAllowlist($mode),
                "mode '{$mode}' consults neither check"
            );
        }
    }

    /**
     * Which checks each mode consults. This is the matrix the spec names, and
     * it is separate from whether a check is CONFIGURED — a mode that counts a
     * check the site has not set up skips it rather than failing it.
     */
    public function testTheModeMatrix(): void
    {
        $expected = [
            //                          organisation, allowlist
            ClaimGate::BOTH         => [true,  true],
            ClaimGate::ORGANIZATION => [true,  false],
            ClaimGate::ALLOWLIST    => [false, true],
            ClaimGate::EITHER       => [true,  true],
        ];

        foreach ($expected as $mode => [$org, $list]) {
            self::assertSame($org, ClaimGate::countsOrganisation($mode), "{$mode}: organisation");
            self::assertSame($list, ClaimGate::countsAllowlist($mode), "{$mode}: allowlist");
        }
    }

    /** Case and stray space are the same intention, not a typo. */
    public function testModesAreReadForgivingly(): void
    {
        self::assertSame(ClaimGate::EITHER, ClaimGate::normalizeMode('either'));
        self::assertSame(ClaimGate::EITHER, ClaimGate::normalizeMode('  EITHER '));
        self::assertSame(ClaimGate::ALLOWLIST, ClaimGate::normalizeMode('allowlist'));
        self::assertSame(ClaimGate::ORGANIZATION, ClaimGate::normalizeMode('Organization'));
    }

    /**
     * The organisation parameter is withheld under ALLOWLIST too.
     *
     * Membership is not being checked at all in that mode, so asking the
     * provider to enforce it at its own door refuses people this site would
     * have admitted — the same shape as the EITHER case, one step further on.
     */
    public function testTheParameterIsWithheldWhenMembershipIsNotBeingChecked(): void
    {
        self::assertNull(ClaimGate::authorizeValue(ClaimGate::ALLOWLIST, ['org_a']));
        self::assertSame('org_a', ClaimGate::authorizeValue(ClaimGate::ORGANIZATION, ['org_a']));
    }

    // ------------------------------------------------------ authorize parameter

    /** One organization: send it, and the provider renders that login directly. */
    public function testTheParameterIsSentForExactlyOneOrganization(): void
    {
        self::assertSame('org_a', ClaimGate::authorizeValue(ClaimGate::BOTH, ['org_a']));
    }

    /**
     * Several: withheld, so the provider shows its own picker rather than this
     * site choosing one of them on the person's behalf.
     */
    public function testTheParameterIsWithheldWhenSeveralAreAccepted(): void
    {
        self::assertNull(ClaimGate::authorizeValue(ClaimGate::BOTH, ['org_a', 'org_b']));
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
        self::assertNull(ClaimGate::authorizeValue(ClaimGate::BOTH, []));
    }
}
