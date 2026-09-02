<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Content\DownloadPolicy;

/**
 * Which way the download setting falls, with no database in the way.
 *
 * The inheritance rule is the part worth getting exactly right. Getting it
 * wrong in the permissive direction hands out a file that cannot be recalled —
 * unlike every other thing this application gates, where the mistake is
 * repairable by changing the setting back.
 */
final class DownloadPolicyTest extends TestCase
{
    public function testTheVideosOwnSettingWinsOverEverything(): void
    {
        self::assertSame(
            DownloadPolicy::ALLOW,
            DownloadPolicy::resolve(
                DownloadPolicy::ALLOW,
                DownloadPolicy::BLOCK,
                [DownloadPolicy::BLOCK],
                false
            )
        );

        self::assertSame(
            DownloadPolicy::BLOCK,
            DownloadPolicy::resolve(
                DownloadPolicy::BLOCK,
                DownloadPolicy::ALLOW,
                [DownloadPolicy::ALLOW],
                true
            )
        );
    }

    /**
     * Series before categories. A video sits in exactly one series and in any
     * number of categories, so the series is the unambiguous statement.
     */
    public function testTheSeriesOutranksTheCategories(): void
    {
        self::assertSame(
            DownloadPolicy::ALLOW,
            DownloadPolicy::resolve(
                DownloadPolicy::INHERIT,
                DownloadPolicy::ALLOW,
                [DownloadPolicy::BLOCK],
                false
            )
        );
    }

    public function testTheNearestCategoryWinsWithinTheChain(): void
    {
        // The chain arrives nearest-first, so a permissive parent under a
        // blocking child must not reopen it.
        self::assertSame(
            DownloadPolicy::BLOCK,
            DownloadPolicy::resolve(
                DownloadPolicy::INHERIT,
                DownloadPolicy::INHERIT,
                [DownloadPolicy::BLOCK, DownloadPolicy::ALLOW],
                true
            )
        );
    }

    public function testWithNoOpinionAnywhereTheSiteSettingDecides(): void
    {
        self::assertSame(DownloadPolicy::BLOCK, DownloadPolicy::resolve(DownloadPolicy::INHERIT));
        self::assertSame(
            DownloadPolicy::ALLOW,
            DownloadPolicy::resolve(DownloadPolicy::INHERIT, DownloadPolicy::INHERIT, [], true)
        );
    }

    /**
     * The default of the default. A site that has changed nothing does not hand
     * out files, and an upgrade must not start it doing so.
     */
    public function testTheSiteDefaultIsOff(): void
    {
        self::assertFalse(DownloadPolicy::allows(DownloadPolicy::resolve(DownloadPolicy::INHERIT)));
    }

    // ------------------------------------------------------------ reconciling

    /**
     * Two categories disagreeing resolves to BLOCK, so the order of rows in a
     * join table cannot decide whether a file leaves the site.
     */
    public function testDisagreeingCategoriesBlock(): void
    {
        self::assertSame(
            [DownloadPolicy::BLOCK],
            DownloadPolicy::reconcile([DownloadPolicy::ALLOW, DownloadPolicy::BLOCK])
        );
        self::assertSame(
            [DownloadPolicy::BLOCK],
            DownloadPolicy::reconcile([DownloadPolicy::BLOCK, DownloadPolicy::ALLOW])
        );
    }

    public function testAgreeingCategoriesKeepTheirAnswer(): void
    {
        self::assertSame(
            [DownloadPolicy::ALLOW],
            DownloadPolicy::reconcile([DownloadPolicy::ALLOW, DownloadPolicy::ALLOW])
        );
    }

    public function testNoOpinionsReconcileToNothing(): void
    {
        self::assertSame([], DownloadPolicy::reconcile([]));
    }

    // --------------------------------------------------------------- sanitize

    /**
     * A stray value defers. It must not grant a download and it must not
     * withdraw one — both look like the setting working, and neither is what
     * anybody asked for.
     */
    public function testUnknownValuesBecomeInherit(): void
    {
        self::assertSame(DownloadPolicy::INHERIT, DownloadPolicy::sanitize('yes'));
        self::assertSame(DownloadPolicy::INHERIT, DownloadPolicy::sanitize(''));
        self::assertSame(DownloadPolicy::INHERIT, DownloadPolicy::sanitize(null));
        self::assertSame(DownloadPolicy::INHERIT, DownloadPolicy::sanitize(['allow']));
        self::assertSame(DownloadPolicy::INHERIT, DownloadPolicy::sanitize('ALLOW'));
    }

    public function testValidValuesSurvive(): void
    {
        foreach ([DownloadPolicy::INHERIT, DownloadPolicy::ALLOW, DownloadPolicy::BLOCK] as $mode) {
            self::assertSame($mode, DownloadPolicy::sanitize($mode));
        }
    }

    /** One vocabulary, so four screens cannot describe the setting four ways. */
    public function testTheChoicesCoverEveryStoredValue(): void
    {
        $choices = DownloadPolicy::choices('Inherit');

        self::assertSame(
            [DownloadPolicy::INHERIT, DownloadPolicy::ALLOW, DownloadPolicy::BLOCK],
            array_keys($choices)
        );
        self::assertSame('Inherit', $choices[DownloadPolicy::INHERIT]);
    }
}
