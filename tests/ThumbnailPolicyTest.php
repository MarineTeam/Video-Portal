<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\ThumbnailPolicy;

/**
 * The thumbnail inheritance rule.
 *
 * Three levels with a tri-state at each is easy to get subtly wrong, and the
 * wrong answer is invisible in one direction: artwork that should have been
 * withheld looks exactly like artwork that was meant to be public. Only the
 * other direction — a library gone grey — ever gets reported.
 */
final class ThumbnailPolicyTest extends TestCase
{
    private const INHERIT = ThumbnailPolicy::INHERIT;
    private const PUBLIC_ART = ThumbnailPolicy::PUBLIC_ART;
    private const MEMBERS = ThumbnailPolicy::MEMBERS;

    // --------------------------------------------------------- the hierarchy

    public function testAVideoSettingBeatsItsCategories(): void
    {
        self::assertSame(self::PUBLIC_ART, ThumbnailPolicy::resolve(self::PUBLIC_ART, [self::MEMBERS], true));
        self::assertSame(self::MEMBERS, ThumbnailPolicy::resolve(self::MEMBERS, [self::PUBLIC_ART], false));
    }

    public function testACategoryBeatsTheSiteDefaultWhenTheVideoDefers(): void
    {
        self::assertSame(self::MEMBERS, ThumbnailPolicy::resolve(self::INHERIT, [self::MEMBERS], false));
        self::assertSame(self::PUBLIC_ART, ThumbnailPolicy::resolve(self::INHERIT, [self::PUBLIC_ART], true));
    }

    public function testTheSiteDefaultAppliesWhenNothingElseHasAnOpinion(): void
    {
        self::assertSame(self::MEMBERS, ThumbnailPolicy::resolve(self::INHERIT, [], true));
        self::assertSame(self::PUBLIC_ART, ThumbnailPolicy::resolve(self::INHERIT, [], false));
    }

    /** A video in no category at all is normal, not an error. */
    public function testAnEmptyChainFallsStraightToTheSiteDefault(): void
    {
        self::assertSame(self::PUBLIC_ART, ThumbnailPolicy::resolve(self::INHERIT, [], false));
    }

    /** Nearest first, so a nearer category can re-open a locked parent. */
    public function testTheNearestDecisiveCategoryWins(): void
    {
        self::assertSame(
            self::PUBLIC_ART,
            ThumbnailPolicy::resolve(self::INHERIT, [self::PUBLIC_ART, self::MEMBERS], false)
        );
    }

    public function testCategoriesThatDeferAreSkipped(): void
    {
        self::assertSame(
            self::MEMBERS,
            ThumbnailPolicy::resolve(self::INHERIT, [self::INHERIT, self::INHERIT, self::MEMBERS], false)
        );
    }

    // ------------------------------------------------------------ the viewer

    public function testMembersOnlyArtIsWithheldFromSomeoneWhoCannotWatch(): void
    {
        self::assertFalse(ThumbnailPolicy::showsRealArt(self::MEMBERS, false));
        self::assertTrue(ThumbnailPolicy::showsRealArt(self::MEMBERS, true));
    }

    public function testPublicArtIsShownToEveryone(): void
    {
        self::assertTrue(ThumbnailPolicy::showsRealArt(self::PUBLIC_ART, false));
        self::assertTrue(ThumbnailPolicy::showsRealArt(self::PUBLIC_ART, true));
    }

    // -------------------------------------------------------------- sanitize

    /**
     * A stray value must not lock artwork. Nobody reports a thumbnail that is
     * visible when it should not be, so a bad value silently turning things
     * grey is the failure that gets noticed and the wrong one to choose.
     */
    public function testUnknownValuesBecomeInheritRatherThanMembers(): void
    {
        foreach (['', 'yes', 'on', '1', 'MEMBERS', null, [], 3.4] as $value) {
            self::assertSame(
                self::INHERIT,
                ThumbnailPolicy::sanitize($value),
                'Unrecognised input must never resolve to members-only.'
            );
        }
    }

    public function testValidValuesSurviveSanitising(): void
    {
        self::assertSame(self::INHERIT, ThumbnailPolicy::sanitize('default'));
        self::assertSame(self::PUBLIC_ART, ThumbnailPolicy::sanitize('public'));
        self::assertSame(self::MEMBERS, ThumbnailPolicy::sanitize('members'));
    }

    public function testChoicesCoverEveryModeExactlyOnce(): void
    {
        $choices = ThumbnailPolicy::choices('Inherit');

        self::assertSame(
            [self::INHERIT, self::PUBLIC_ART, self::MEMBERS],
            array_keys($choices),
            'A mode missing from the picker is a setting nobody can select.'
        );
    }
}
