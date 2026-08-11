<?php

declare(strict_types=1);

namespace Portal\Plugins\ProviderStats;

/**
 * Two counts of the same thing, and what their difference means.
 *
 * This site counts a play when somebody opens one of its own watch pages.
 * bunny.net counts a play at the CDN, which includes every route to the file
 * this site never sees: a share link, an embed on another page, a feed client
 * fetching the MP4. The two numbers are measuring different things on purpose
 * and they are not supposed to match.
 *
 * Which makes the DIRECTION of the gap the interesting part, and the reason
 * this is worth a screen rather than a number:
 *
 *   provider ahead  — normal. The difference is roughly "plays that did not
 *                     come through a watch page on this site".
 *   site ahead      — should not happen. This site recorded plays the CDN did
 *                     not, which means either the counts are being written
 *                     without a real play behind them, or plays are reaching
 *                     the recorder without reaching bunny.
 *
 * Kept free of the network and the database so the judgement can be tested
 * against fixed inputs — the reading itself is a single HTTP call and a single
 * query, and neither of those is where a mistake would hide.
 */
final class ProviderStatsReport
{
    /** The provider answered and the answer is usable. */
    public const READ_OK = 'ok';

    /** Both sides are zero. Nothing to compare, and no way to tell why. */
    public const READ_QUIET = 'quiet';

    /** The provider reported nothing while this site recorded plays. */
    public const READ_UNREADABLE = 'unreadable';

    /**
     * Compare one window's figures.
     *
     * @param array{views?: int, watchTime?: int, chart?: array<string, int>} $provider
     *        exactly what VideoProvider::statistics() returned
     * @param array{views?: int, completions?: int} $local
     *        exactly what ViewRepository::summary() returned
     *
     * @return array{
     *     state: string, days: int,
     *     providerViews: int, providerWatchTime: int,
     *     localViews: int, localCompletions: int,
     *     gap: int, gapPercent: ?float, siteAhead: bool,
     *     chart: array<string, int>
     * }
     */
    public static function compare(array $provider, array $local, int $days): array
    {
        // Negative counts are not a thing either side can legitimately report,
        // and a negative here would flow into the gap and invert its meaning.
        $providerViews = max(0, (int) ($provider['views'] ?? 0));
        $providerWatch = max(0, (int) ($provider['watchTime'] ?? 0));
        $localViews = max(0, (int) ($local['views'] ?? 0));
        $localCompletions = max(0, (int) ($local['completions'] ?? 0));

        $gap = $providerViews - $localViews;

        return [
            'state'             => self::state($providerViews, $providerWatch, $provider['chart'] ?? [], $localViews),
            'days'              => $days,
            'providerViews'     => $providerViews,
            'providerWatchTime' => $providerWatch,
            'localViews'        => $localViews,
            'localCompletions'  => $localCompletions,
            'gap'               => abs($gap),
            // Against the larger of the two, so the figure means "how far apart
            // are these" regardless of which side is ahead. Undefined rather
            // than zero when there is nothing to divide by: a percentage of
            // nothing would print 0% and read as perfect agreement.
            'gapPercent'        => max($providerViews, $localViews) === 0
                ? null
                : round(abs($gap) / max($providerViews, $localViews) * 100, 1),
            'siteAhead'         => $gap < 0,
            'chart'             => self::chart($provider['chart'] ?? []),
        ];
    }

    /**
     * Did the reading work?
     *
     * BunnyStreamProvider::statistics() catches its own failure and returns
     * zeroes, deliberately — analytics is a nice-to-have and it should not
     * 502 a page. The cost of that choice lands here: a failed API call and a
     * library nobody watched produce the same value, and a screen that printed
     * "0 views" for both would be confidently wrong half the time.
     *
     * So this infers. A provider reporting no views, no watch time and no chart
     * at all, in a window where this site recorded plays of its own, is not a
     * state a working API produces — the plays this site saw went through the
     * CDN by definition. That is strong enough to say the reading failed.
     *
     * It is an inference and the screen says so. What it cannot do is tell a
     * failed call from a genuinely idle month when both sides are zero, and
     * that case is reported as unknown rather than guessed at.
     *
     * @param array<string, int> $chart
     */
    private static function state(int $providerViews, int $providerWatch, array $chart, int $localViews): string
    {
        $providerSaidNothing = $providerViews === 0 && $providerWatch === 0 && $chart === [];

        if (!$providerSaidNothing) {
            return self::READ_OK;
        }

        return $localViews > 0 ? self::READ_UNREADABLE : self::READ_QUIET;
    }

    /**
     * The daily series, oldest first, with junk dropped.
     *
     * bunny.net has returned this keyed by date and as a list of objects, and
     * the provider already normalises both into a date-keyed map. What it does
     * not do is order it, and a chart drawn in hash order is a chart that lies
     * about a trend.
     *
     * @param array<string, int>|mixed $raw
     * @return array<string, int>
     */
    private static function chart(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $chart = [];
        foreach ($raw as $day => $value) {
            $label = trim((string) $day);
            if ($label === '') {
                continue;
            }
            $chart[$label] = max(0, (int) $value);
        }

        ksort($chart);

        return $chart;
    }

    /**
     * The tallest bar in a series, for scaling.
     *
     * Never returns zero: it exists to be divided by, and an all-zero series is
     * a real thing a quiet week produces.
     *
     * @param array<string, int> $chart
     */
    public static function peak(array $chart): int
    {
        // array_values matters and is not tidiness: the chart is keyed by date,
        // and spreading a string-keyed array turns every key into a NAMED
        // argument, so `max(1, ...$chart)` fatals with "Unknown named parameter
        // $2026-08-11" the moment it is handed a real series.
        $values = array_values(array_map(static fn (mixed $v): int => max(0, (int) $v), $chart));

        return $values === [] ? 1 : max(1, ...$values);
    }

    /**
     * Watch time, as a phrase.
     *
     * The unit is whatever bunny.net put in `totalWatchTime`, which their
     * documentation calls minutes. That has not been checked against a live
     * response from this account, so the screen labels it as their figure
     * rather than converting it into hours and presenting the result as fact.
     */
    public static function watchTime(int $minutes): string
    {
        if ($minutes <= 0) {
            return 'none recorded';
        }

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? number_format($hours) . ' hr'
            : number_format($hours) . ' hr ' . $rest . ' min';
    }
}
