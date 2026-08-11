<?php

declare(strict_types=1);

namespace Portal\Plugins\ProviderStats;

use Portal\Auth\Capability;
use Portal\Content\ViewRepository;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginPage;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * The screen.
 *
 * Read-only: no form, no POST, nothing to save. The period comes from the query
 * string and is one of the ones the analytics screen already offers, so the two
 * screens can be compared without doing arithmetic in your head.
 */
final class ProviderStatsPage extends PluginPage
{
    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        /*
         * The same capability as the analytics screen, not a stricter one.
         * This is the same question about the same library asked of a second
         * source; a separate permission would be one nobody could explain.
         */
        $this->require(Capability::VIEW_ANALYTICS);

        $days = ViewRepository::sanitizePeriod($request->query('days'));

        return $this->page(
            'Provider statistics',
            $this->body($days, $this->provider($days), $this->local($days)),
            'provider-stats'
        );
    }

    /**
     * What bunny.net says.
     *
     * Wrapped because this is an outbound HTTP call on a page render, and the
     * page has something worth showing even when it fails — this site's own
     * figures are right there and do not depend on anybody answering.
     *
     * @return array{views: int, watchTime: int, chart: array<string, int>}
     */
    private function provider(int $days): array
    {
        try {
            return $this->container->get(VideoProvider::class)->statistics($days);
        } catch (Throwable $e) {
            error_log('Provider statistics: the video provider could not be reached. ' . $e->getMessage());

            return ['views' => 0, 'watchTime' => 0, 'chart' => []];
        }
    }

    /**
     * What this site recorded.
     *
     * @return array{views: int, completions: int}
     */
    private function local(int $days): array
    {
        try {
            return $this->container->get(ViewRepository::class)->summary($days);
        } catch (Throwable $e) {
            // Before migration 0011, or on a database hiccup. Zeroes here make
            // the comparison meaningless rather than wrong, and the state logic
            // reports that honestly.
            error_log('Provider statistics: could not read local view counts. ' . $e->getMessage());

            return ['views' => 0, 'completions' => 0];
        }
    }

    /**
     * @param array{views: int, watchTime: int, chart: array<string, int>} $provider
     * @param array{views: int, completions: int} $local
     */
    private function body(int $days, array $provider, array $local): string
    {
        $report = ProviderStatsReport::compare($provider, $local, $days);

        $periods = '';
        foreach (ViewRepository::periods() as $value => $label) {
            $periods .= sprintf(
                '<a href="/admin/provider-stats?days=%d"%s>%s</a>',
                $value,
                $value === $days ? ' class="pill ok"' : ' class="pill"',
                e($label)
            );
        }

        $verdict = $this->verdict($report);
        $chart = $this->chart($report['chart']);

        $providerViews = number_format($report['providerViews']);
        $localViews = number_format($report['localViews']);
        $completions = number_format($report['localCompletions']);
        $watch = e(ProviderStatsReport::watchTime($report['providerWatchTime']));

        return <<<HTML
        <h1>Provider statistics</h1>
        <p class="muted">What your video service counted, beside what this site counted, over the
           same window. They are not supposed to match — the paragraph under the tiles explains
           what the difference means.</p>

        <div class="toolbar">{$periods}</div>

        <div class="tiles">
          <div class="tile"><span class="n">{$providerViews}</span><span class="l">Plays, per bunny.net</span></div>
          <div class="tile"><span class="n">{$localViews}</span><span class="l">Plays, per this site</span></div>
          <div class="tile"><span class="n">{$completions}</span><span class="l">Finished, per this site</span></div>
          <div class="tile"><span class="n">{$watch}</span><span class="l">Watch time, per bunny.net</span></div>
        </div>

        {$verdict}
        {$chart}

        <h2>Why the two numbers differ</h2>
        <p class="muted">This site counts a play when somebody opens one of its own watch pages.
           bunny.net counts a play at the CDN, which also catches every route to the file this site
           never sees — a share link, an embed somewhere else, a podcast client fetching the MP4.
           <strong>bunny.net being ahead is normal.</strong> This site being ahead is not, and is
           worth looking into: it would mean plays are being recorded here that never reached the
           service holding the video.</p>
        <p class="muted small">Watch time is bunny.net's own <code>totalWatchTime</code> figure,
           shown in the unit they report it in. Nothing here is stored — each visit asks the service
           again, so there is no history and no table for this plugin to leave behind.</p>
        HTML;
    }

    /**
     * The sentence that says whether the comparison above means anything.
     *
     * @param array<string, mixed> $report
     */
    private function verdict(array $report): string
    {
        if ($report['state'] === ProviderStatsReport::READ_UNREADABLE) {
            return '<div class="flash error"><strong>bunny.net returned nothing for this window.</strong>
                    This site recorded plays over the same days, and every one of those went through
                    the CDN, so an empty answer almost certainly means the statistics call failed
                    rather than that nobody watched. Check the video service credentials on
                    <a href="/admin/providers">Services</a>. The figures from this site, above, are
                    unaffected.</div>';
        }

        if ($report['state'] === ProviderStatsReport::READ_QUIET) {
            return '<div class="flash"><strong>Nothing to compare.</strong> Both sources report no
                    plays in this window. That is what a quiet period looks like and also what a
                    failed statistics call looks like, and there is no way to tell them apart from
                    here — try a longer window.</div>';
        }

        $gap = number_format((int) $report['gap']);
        $percent = $report['gapPercent'] === null ? '' : ' (' . $report['gapPercent'] . '%)';

        if ((int) $report['gap'] === 0) {
            return '<div class="flash">The two sources agree exactly. Worth a glance rather than a
                    celebration — identical numbers usually mean every play came through a watch
                    page on this site, which is expected on a library with no share links or
                    embeds in use.</div>';
        }

        if ($report['siteAhead'] === true) {
            return "<div class=\"flash error\"><strong>This site counted {$gap} more plays than
                    bunny.net{$percent}.</strong> That is the wrong direction. Every play recorded
                    here should have been a request to the CDN as well, so this points at view
                    counts being written without a real play behind them.</div>";
        }

        return "<div class=\"flash\">bunny.net counted {$gap} more plays than this site{$percent} —
                the expected direction. That difference is roughly your share links, embeds and
                feed downloads: real plays that did not go through a watch page here.</div>";
    }

    /**
     * A bar per day, drawn in CSS.
     *
     * No chart library. One would be a vendored dependency that can only be
     * patched by cutting a whole release, for a picture that thirty divs draw
     * perfectly well.
     *
     * @param array<string, int> $chart
     */
    private function chart(array $chart): string
    {
        if ($chart === []) {
            return '';
        }

        $peak = ProviderStatsReport::peak($chart);

        $bars = '';
        foreach ($chart as $day => $views) {
            $bars .= sprintf(
                '<span class="ps-bar" style="height:%.1f%%" title="%s: %s"></span>',
                max(2, $views / $peak * 100),
                e((string) $day),
                e(number_format($views))
            );
        }

        $first = e((string) array_key_first($chart));
        $last = e((string) array_key_last($chart));
        $peakLabel = e(number_format($peak));

        return <<<HTML
        <h2>Plays per day, per bunny.net</h2>
        <div class="ps-chart">{$bars}</div>
        <p class="muted small">{$first} to {$last}. Tallest bar: {$peakLabel}. Hover a bar for its day.</p>
        <style>
          .ps-chart { display:flex; align-items:flex-end; gap:2px; height:8rem; padding:.5rem;
                      border:1px solid rgba(148,163,184,.2); border-radius:12px; background:#0f172a; }
          .ps-bar { flex:1 1 0; min-width:2px; border-radius:2px 2px 0 0; background:#38bdf8; opacity:.85; }
          .ps-bar:hover { opacity:1; }
        </style>
        HTML;
    }
}
