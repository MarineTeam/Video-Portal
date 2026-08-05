<?php

declare(strict_types=1);

namespace Portal\Plugins\Ratings;

/**
 * What a rating may be, and what a pile of them means.
 *
 * Pure: no database, no request. The arithmetic here is the whole feature —
 * everything else is storage and markup — so it is worth being able to test
 * exhaustively rather than by eyeballing a page.
 */
final class RatingPolicy
{
    public const MIN_SCORE = 1;
    public const MAX_SCORE = 5;

    /**
     * How much a hypothetical average counts for when ranking.
     *
     * Expressed in votes: a video is ranked as though it already had this many
     * votes at the site's own average. Five is enough that a single opinion
     * cannot top the chart and small enough that a genuinely liked video with
     * a dozen ratings can.
     */
    public const PRIOR_VOTES = 5;

    /**
     * A submitted score, or null if it is not one.
     *
     * Refused rather than clamped. Clamping a tampered "9" to 5 records a
     * five-star rating nobody gave, which is worse than dropping it — and a
     * form that quietly rewrites what it was sent is a form nobody can debug.
     */
    public static function sanitize(mixed $raw): ?int
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '' || preg_match('/^-?\d+$/', $raw) !== 1) {
                return null;
            }
        }

        if (!is_int($raw) && !is_string($raw)) {
            return null;
        }

        $score = (int) $raw;

        return $score >= self::MIN_SCORE && $score <= self::MAX_SCORE ? $score : null;
    }

    /**
     * The plain mean.
     *
     * Zero votes is 0.0 rather than a division by zero or a null that every
     * caller then has to remember to handle. Nothing is ever *displayed* from a
     * count of zero — see showAverage() — so the value only has to be harmless.
     */
    public static function average(int $count, int $sum): float
    {
        return $count <= 0 ? 0.0 : round($sum / $count, 2);
    }

    /**
     * The number a leaderboard should sort on.
     *
     * A plain average puts one enthusiastic five-star rating above fifty
     * ratings averaging 4.8, which is not what anybody means by "top rated".
     * Pulling every video toward the site's own average in proportion to how
     * little is known about it fixes that without hiding new videos entirely:
     * a video with many votes is barely moved, a video with one is moved most
     * of the way back.
     *
     * @param float $siteAverage the mean across every rating on the site
     * @param int   $priorVotes  how many votes' worth the prior is given
     */
    public static function ranking(
        int $count,
        int $sum,
        float $siteAverage,
        int $priorVotes = self::PRIOR_VOTES
    ): float {
        $priorVotes = max(0, $priorVotes);

        $denominator = $priorVotes + $count;
        if ($denominator <= 0) {
            return 0.0;
        }

        return round((($priorVotes * $siteAverage) + $sum) / $denominator, 4);
    }

    /**
     * Is there enough here to publish an average?
     *
     * Below the threshold the count is still shown — "1 rating" is honest and
     * useful — but "5.0 out of 5" from a single vote reads as a verdict when it
     * is one person's opinion.
     */
    public static function showAverage(int $count, int $minimum): bool
    {
        return $count > 0 && $count >= max(1, $minimum);
    }

    /** One decimal place, always, so the number does not change width. */
    public static function format(float $average): string
    {
        return number_format($average, 1);
    }

    /**
     * How much of the star row to fill, as a percentage.
     *
     * A width rather than a count of whole and half stars, because 4.3 really
     * is between four stars and four and a half and there is no honest way to
     * round it that is also cheaper to draw. The value is clamped, so a total
     * that has somehow gone out of range produces a wrong picture rather than
     * one that overflows its container.
     */
    public static function percent(float $average): float
    {
        $clamped = max(0.0, min((float) self::MAX_SCORE, $average));

        return round(($clamped / self::MAX_SCORE) * 100, 2);
    }
}
