<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\ProviderStats\ProviderStatsReport;

// A plugin's classes are require'd by its plugin.php at load time, not
// autoloaded, so a test that never boots the plugin has to reach for the file.
require_once PORTAL_PLUGINS . '/provider-stats/src/ProviderStatsReport.php';

/**
 * The judgement the provider-stats screen makes.
 *
 * All of it is here rather than on the page, because the page is one HTTP call
 * and one query wrapped around this — and neither of those is where a mistake
 * would hide. What can be wrong is the reading of two numbers that are supposed
 * to disagree.
 */
final class ProviderStatsReportTest extends TestCase
{
    /** @param array<string, int> $chart */
    private function provider(int $views, int $watch = 0, array $chart = []): array
    {
        return ['views' => $views, 'watchTime' => $watch, 'chart' => $chart];
    }

    private function local(int $views, int $completions = 0): array
    {
        return ['views' => $views, 'completions' => $completions];
    }

    // ------------------------------------------------------------- direction

    /**
     * The CDN ahead of the site is the expected shape, because a share link and
     * an embed are both plays this site never renders a page for.
     */
    public function testTheProviderBeingAheadIsTheNormalDirection(): void
    {
        $report = ProviderStatsReport::compare($this->provider(500, 1200), $this->local(400), 30);

        self::assertSame(ProviderStatsReport::READ_OK, $report['state']);
        self::assertFalse($report['siteAhead']);
        self::assertSame(100, $report['gap']);
        self::assertSame(20.0, $report['gapPercent']);
    }

    /**
     * The other direction is the one worth a warning: a play recorded here was,
     * by definition, a request to the CDN, so this site cannot legitimately be
     * ahead.
     */
    public function testTheSiteBeingAheadIsFlagged(): void
    {
        $report = ProviderStatsReport::compare($this->provider(80, 300), $this->local(100), 30);

        self::assertTrue($report['siteAhead']);
        self::assertSame(20, $report['gap'], 'the gap is a distance, not a signed difference');
    }

    public function testThePercentageIsMeasuredAgainstTheLargerSide(): void
    {
        // Ten apart. Against the larger side that is 10%; against the smaller
        // it would read as 11.1% and change with which source happened to win.
        $ahead = ProviderStatsReport::compare($this->provider(100, 5), $this->local(90), 30);
        $behind = ProviderStatsReport::compare($this->provider(90, 5), $this->local(100), 30);

        self::assertSame(10.0, $ahead['gapPercent']);
        self::assertSame(10.0, $behind['gapPercent']);
    }

    // ----------------------------------------------------- did the read work

    /**
     * The inference the whole screen rests on.
     *
     * statistics() catches its own failure and returns zeroes, so a dead API
     * and an unwatched library are the same value. They are not the same
     * situation, and this is the one signal that separates them: every play
     * this site recorded went through the CDN, so the CDN reporting none of
     * them is not something a working call produces.
     */
    public function testAProviderSayingNothingWhileTheSiteSawPlaysReadsAsAFailedCall(): void
    {
        $report = ProviderStatsReport::compare($this->provider(0), $this->local(240), 30);

        self::assertSame(ProviderStatsReport::READ_UNREADABLE, $report['state']);
    }

    /**
     * And the case it must NOT claim to have diagnosed. Both sides at zero is
     * a quiet month and a failed call at the same time, and saying which would
     * be a guess presented as a finding.
     */
    public function testBothSidesAtZeroIsReportedAsUnknownRatherThanGuessedAt(): void
    {
        $report = ProviderStatsReport::compare($this->provider(0), $this->local(0), 30);

        self::assertSame(ProviderStatsReport::READ_QUIET, $report['state']);
        self::assertNull(
            $report['gapPercent'],
            'a percentage of nothing prints as 0% and reads as perfect agreement'
        );
    }

    /**
     * Any sign of life counts as an answer. A window where the provider
     * genuinely served nothing but still returned a chart of zeroes is a
     * working call, and calling it broken would send somebody to check
     * credentials that are fine.
     */
    public function testAChartAloneProvesTheCallSucceeded(): void
    {
        $report = ProviderStatsReport::compare(
            $this->provider(0, 0, ['2026-08-01' => 0, '2026-08-02' => 0]),
            $this->local(12),
            30
        );

        self::assertSame(ProviderStatsReport::READ_OK, $report['state']);
    }

    public function testWatchTimeAloneAlsoProvesTheCallSucceeded(): void
    {
        $report = ProviderStatsReport::compare($this->provider(0, 45), $this->local(12), 30);

        self::assertSame(ProviderStatsReport::READ_OK, $report['state']);
    }

    // ------------------------------------------------------------ hostile input

    public function testNegativeCountsCannotInvertTheComparison(): void
    {
        $report = ProviderStatsReport::compare(
            ['views' => -50, 'watchTime' => -1, 'chart' => []],
            ['views' => 10, 'completions' => -3],
            30
        );

        self::assertSame(0, $report['providerViews']);
        self::assertSame(0, $report['providerWatchTime']);
        self::assertSame(0, $report['localCompletions']);
        self::assertTrue($report['siteAhead'], 'a negative provider count must not read as ahead');
    }

    public function testMissingKeysAreTreatedAsZeroRatherThanFatal(): void
    {
        $report = ProviderStatsReport::compare([], [], 7);

        self::assertSame(ProviderStatsReport::READ_QUIET, $report['state']);
        self::assertSame(7, $report['days']);
    }

    // ------------------------------------------------------------------ chart

    /**
     * A chart drawn in the order the keys happened to arrive is a chart that
     * lies about a trend, and bunny.net has been observed returning this both
     * date-keyed and as a list.
     */
    public function testTheChartIsOrderedOldestFirst(): void
    {
        $report = ProviderStatsReport::compare(
            $this->provider(6, 0, ['2026-08-03' => 3, '2026-08-01' => 1, '2026-08-02' => 2]),
            $this->local(6),
            7
        );

        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], array_keys($report['chart']));
        self::assertSame([1, 2, 3], array_values($report['chart']));
    }

    public function testBlankChartLabelsAreDropped(): void
    {
        $report = ProviderStatsReport::compare(
            $this->provider(5, 0, ['' => 4, '   ' => 9, '2026-08-01' => 5]),
            $this->local(5),
            7
        );

        self::assertSame(['2026-08-01'], array_keys($report['chart']));
    }

    /**
     * peak() exists to be divided by, so zero is never an acceptable answer —
     * and a real chart is keyed by DATE, which is what makes this more than a
     * bounds check: spreading a string-keyed array into max() turns every key
     * into a named argument and fatals on the first real series it sees.
     */
    public function testPeakSurvivesADateKeyedSeriesAndNeverReturnsZero(): void
    {
        self::assertSame(9, ProviderStatsReport::peak(['2026-08-01' => 4, '2026-08-02' => 9]));
        self::assertSame(1, ProviderStatsReport::peak(['2026-08-01' => 0]));
        self::assertSame(1, ProviderStatsReport::peak([]));
    }

    // -------------------------------------------------------------- watch time

    public function testWatchTimeReadsAsAPhrase(): void
    {
        self::assertSame('none recorded', ProviderStatsReport::watchTime(0));
        self::assertSame('none recorded', ProviderStatsReport::watchTime(-5));
        self::assertSame('45 min', ProviderStatsReport::watchTime(45));
        self::assertSame('2 hr', ProviderStatsReport::watchTime(120));
        self::assertSame('2 hr 5 min', ProviderStatsReport::watchTime(125));
        self::assertSame('1,000 hr', ProviderStatsReport::watchTime(60000));
    }
}
