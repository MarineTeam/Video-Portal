<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Ratings\RatingPolicy;

require_once PORTAL_PLUGINS . '/ratings/src/RatingPolicy.php';

/**
 * The arithmetic behind the stars.
 *
 * All of it is pure, and all of it is the kind of thing that looks obviously
 * right and is off by one somewhere. The ranking function especially: it is the
 * only part with an opinion, and the opinion is that a single five-star rating
 * should not outrank fifty near-perfect ones.
 */
final class RatingPolicyTest extends TestCase
{
    // -------------------------------------------------------------- sanitize

    public function testEveryScoreInRangeIsAccepted(): void
    {
        for ($score = RatingPolicy::MIN_SCORE; $score <= RatingPolicy::MAX_SCORE; $score++) {
            self::assertSame($score, RatingPolicy::sanitize($score));
            self::assertSame($score, RatingPolicy::sanitize((string) $score));
        }
    }

    /**
     * Refused, not clamped.
     *
     * A form that turns a tampered 9 into a 5 records a five-star rating nobody
     * gave, which is a worse outcome than losing the submission.
     */
    public function testOutOfRangeIsRefusedRatherThanClamped(): void
    {
        self::assertNull(RatingPolicy::sanitize(0));
        self::assertNull(RatingPolicy::sanitize(6));
        self::assertNull(RatingPolicy::sanitize(9));
        self::assertNull(RatingPolicy::sanitize(-1));
        self::assertNull(RatingPolicy::sanitize(PHP_INT_MAX));
    }

    public function testAnythingThatIsNotAWholeNumberIsRefused(): void
    {
        foreach (['', ' ', 'five', '3.5', '3abc', 'abc3', '٣', null, true, [], 4.5] as $raw) {
            self::assertNull(
                RatingPolicy::sanitize($raw),
                var_export($raw, true) . ' should not be accepted as a score.'
            );
        }
    }

    /** Whitespace around a number a browser sent is not the visitor's fault. */
    public function testSurroundingWhitespaceIsTolerated(): void
    {
        self::assertSame(4, RatingPolicy::sanitize(" 4\n"));
    }

    // --------------------------------------------------------------- average

    public function testTheAverageIsTheMean(): void
    {
        self::assertSame(4.0, RatingPolicy::average(3, 12));
        self::assertSame(4.33, RatingPolicy::average(3, 13));
        self::assertSame(1.0, RatingPolicy::average(1, 1));
    }

    public function testNoVotesAveragesToZeroRatherThanDividingByIt(): void
    {
        self::assertSame(0.0, RatingPolicy::average(0, 0));
        self::assertSame(0.0, RatingPolicy::average(-1, 5));
    }

    // --------------------------------------------------------------- ranking

    /**
     * The whole reason the ranking exists.
     *
     * One perfect rating must not beat a large body of nearly-perfect ones. If
     * this ever passes with the prior removed, the leaderboard is a plain
     * average wearing a different name.
     */
    public function testOnePerfectRatingDoesNotOutrankFiftyNearlyPerfectOnes(): void
    {
        $site = 3.5;

        $lonely = RatingPolicy::ranking(1, 5, $site);       // a single 5
        $popular = RatingPolicy::ranking(50, 240, $site);   // fifty averaging 4.8

        self::assertGreaterThan(
            $lonely,
            $popular,
            'A well-rated video with many votes must rank above a single five-star rating.'
        );
    }

    /** And the plain average would have said the opposite. */
    public function testThePlainAverageDisagrees(): void
    {
        self::assertGreaterThan(
            RatingPolicy::average(50, 240),
            RatingPolicy::average(1, 5)
        );
    }

    /**
     * With many votes the prior stops mattering, which is the property that
     * makes it fair rather than merely conservative.
     */
    public function testTheRankingConvergesOnTheAverageAsVotesAccumulate(): void
    {
        $site = 3.0;

        $few = abs(RatingPolicy::ranking(3, 15, $site) - 5.0);
        $many = abs(RatingPolicy::ranking(500, 2500, $site) - 5.0);

        self::assertGreaterThan($many, $few);
        self::assertEqualsWithDelta(5.0, RatingPolicy::ranking(5000, 25000, $site), 0.01);
    }

    /** A video rated exactly at the site average is unmoved by the prior. */
    public function testARatingAtTheSiteAverageIsNotPulledAnywhere(): void
    {
        self::assertSame(4.0, RatingPolicy::ranking(2, 8, 4.0));
    }

    public function testAnUnratedVideoRanksAtTheSiteAverage(): void
    {
        self::assertSame(3.7, RatingPolicy::ranking(0, 0, 3.7));
    }

    /** Nothing to rank and nothing to fall back on must not divide by zero. */
    public function testNoVotesAndNoPriorIsZeroRatherThanAnError(): void
    {
        self::assertSame(0.0, RatingPolicy::ranking(0, 0, 4.0, 0));
        self::assertSame(0.0, RatingPolicy::ranking(0, 0, 4.0, -10));
    }

    // ---------------------------------------------------------- presentation

    public function testTheAverageIsWithheldUntilEnoughPeopleHaveRated(): void
    {
        self::assertFalse(RatingPolicy::showAverage(2, 3));
        self::assertTrue(RatingPolicy::showAverage(3, 3));
        self::assertTrue(RatingPolicy::showAverage(4, 3));
    }

    /** Zero ratings never shows an average, whatever the threshold says. */
    public function testNoRatingsNeverShowsAnAverage(): void
    {
        self::assertFalse(RatingPolicy::showAverage(0, 1));
        self::assertFalse(RatingPolicy::showAverage(0, 0));
        self::assertFalse(RatingPolicy::showAverage(0, -5));
    }

    /** A threshold below one is treated as one, not as "always". */
    public function testAThresholdBelowOneStillNeedsARating(): void
    {
        self::assertTrue(RatingPolicy::showAverage(1, 0));
        self::assertTrue(RatingPolicy::showAverage(1, -3));
    }

    public function testTheAverageAlwaysFormatsToOneDecimal(): void
    {
        self::assertSame('4.0', RatingPolicy::format(4.0));
        self::assertSame('4.3', RatingPolicy::format(4.33));
        self::assertSame('5.0', RatingPolicy::format(5.0));
        self::assertSame('0.0', RatingPolicy::format(0.0));
    }

    public function testThePercentageTracksTheAverage(): void
    {
        self::assertSame(0.0, RatingPolicy::percent(0.0));
        self::assertSame(50.0, RatingPolicy::percent(2.5));
        self::assertSame(86.0, RatingPolicy::percent(4.3));
        self::assertSame(100.0, RatingPolicy::percent(5.0));
    }

    /** A total that has somehow gone out of range must not overflow the bar. */
    public function testThePercentageIsClamped(): void
    {
        self::assertSame(100.0, RatingPolicy::percent(9.9));
        self::assertSame(0.0, RatingPolicy::percent(-2.0));
    }
}
