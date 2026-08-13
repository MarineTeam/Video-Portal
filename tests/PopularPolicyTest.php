<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Popular\PopularPolicy;

require_once dirname(__DIR__) . '/plugins/popular/src/PopularPolicy.php';

/**
 * What "most watched" is allowed to say.
 *
 * The ranking comes from the view table and the permission comes from the
 * listing query; the interesting part is what happens where the two meet, and
 * that is all here.
 */
final class PopularPolicyTest extends TestCase
{
    /**
     * THE claim of the whole plugin.
     *
     * The ranking decides the order, the listing decides who may be named, and
     * neither may be used for the other's job. Video 2 is watched second-most
     * and is not in the permitted set — a members-only episode a stranger is
     * being shown the homepage for — so it is dropped, and 3 moves up without
     * 5 overtaking it.
     */
    public function testTheRankingSurvivesWhileTheHiddenAreDropped(): void
    {
        $kept = PopularPolicy::keepInRankOrder([1, 2, 3, 5], [1, 3, 5], 10);

        self::assertSame([1, 3, 5], $kept);
    }

    /** And the listing's own order — publication date — never leaks in. */
    public function testThePermittedSetIsASetAndNotAnOrder(): void
    {
        $kept = PopularPolicy::keepInRankOrder([9, 4, 7], [7, 4, 9], 10);

        self::assertSame([9, 4, 7], $kept);
    }

    public function testItStopsAtTheRequestedCount(): void
    {
        self::assertSame([1, 2, 3], PopularPolicy::keepInRankOrder([1, 2, 3, 4, 5], [1, 2, 3, 4, 5], 3));
    }

    public function testNothingPermittedMeansNothingShown(): void
    {
        self::assertSame([], PopularPolicy::keepInRankOrder([1, 2, 3], [], 8));
        self::assertSame([], PopularPolicy::keepInRankOrder([], [1, 2, 3], 8));
    }

    /**
     * A video listed twice would appear twice in a row of eight — invisible in
     * code and immediately visible on a page.
     */
    public function testADuplicateIsShownOnce(): void
    {
        self::assertSame([4, 6], PopularPolicy::keepInRankOrder([4, 4, 6], [4, 6], 8));
    }

    /**
     * A row of one is not a ranking. It is the only thing anybody opened,
     * presented as though a crowd had chosen it — which on a freshly installed
     * site is every row it would ever draw.
     */
    public function testARowOfOneOrTwoIsNotWorthShowing(): void
    {
        self::assertFalse(PopularPolicy::worthShowing(0));
        self::assertFalse(PopularPolicy::worthShowing(PopularPolicy::MIN_VIDEOS - 1));
        self::assertTrue(PopularPolicy::worthShowing(PopularPolicy::MIN_VIDEOS));
    }

    /**
     * More candidates than are wanted, because the view table knows nothing
     * about who is asking: a top eight full of members-only videos would hand a
     * signed-out visitor two.
     */
    public function testMoreCandidatesAreAskedForThanAreShown(): void
    {
        self::assertSame(32, PopularPolicy::candidateLimit(8));
        self::assertGreaterThan(8, PopularPolicy::candidateLimit(8));
    }

    /**
     * Capped at what VideoRepository::query() clamps a page to. Asking for more
     * would silently return one page and drop the tail — a cap that is
     * invisible until the data grows past it.
     */
    public function testTheCandidateLimitNeverExceedsOnePage(): void
    {
        // The biggest row anybody can configure still fits inside a page, which
        // is the case that has to hold on a real site.
        self::assertLessThanOrEqual(
            PopularPolicy::CANDIDATE_MAX,
            PopularPolicy::candidateLimit(PopularPolicy::MAX_COUNT)
        );

        // And the cap is real rather than incidental: ask for something absurd
        // and it stops, instead of handing query() a page size it will silently
        // clamp on its own.
        self::assertSame(PopularPolicy::CANDIDATE_MAX, PopularPolicy::candidateLimit(1000));
    }

    public function testTheWindowIsClampedRatherThanRefused(): void
    {
        self::assertSame(7, PopularPolicy::days(7));
        self::assertSame(1, PopularPolicy::days(0));
        self::assertSame(1, PopularPolicy::days(-30));
        self::assertSame(PopularPolicy::MAX_DAYS, PopularPolicy::days(99999));
        self::assertSame(PopularPolicy::DEFAULT_DAYS, PopularPolicy::days('a month'));
    }

    /**
     * The count cannot be set below the minimum that makes the row worth
     * showing — otherwise the two settings would contradict each other and the
     * row would silently never appear.
     */
    public function testTheCountCannotBeSetBelowTheMinimum(): void
    {
        self::assertSame(PopularPolicy::MIN_VIDEOS, PopularPolicy::count(1));
        self::assertSame(PopularPolicy::MAX_COUNT, PopularPolicy::count(500));
        self::assertSame(12, PopularPolicy::count('12'));
        self::assertSame(PopularPolicy::DEFAULT_COUNT, PopularPolicy::count('lots'));
    }

    public function testTheHeadingIsTrimmedCappedAndNeverBlank(): void
    {
        self::assertSame('Trending', PopularPolicy::title('  Trending '));
        self::assertSame(PopularPolicy::DEFAULT_TITLE, PopularPolicy::title(''));
        self::assertSame(PopularPolicy::DEFAULT_TITLE, PopularPolicy::title(null));
        self::assertSame(PopularPolicy::TITLE_MAX, mb_strlen(PopularPolicy::title(str_repeat('x', 200))));
    }

    public function testAnythingButLastMeansFirst(): void
    {
        self::assertSame(PopularPolicy::LAST, PopularPolicy::position('last'));
        self::assertSame(PopularPolicy::FIRST, PopularPolicy::position('first'));
        self::assertSame(PopularPolicy::FIRST, PopularPolicy::position('middle'));
        self::assertSame(PopularPolicy::FIRST, PopularPolicy::position(null));
    }
}
