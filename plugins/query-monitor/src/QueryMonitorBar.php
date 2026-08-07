<?php

declare(strict_types=1);

namespace Portal\Plugins\QueryMonitor;

/**
 * The panel, drawn at the bottom of a page.
 *
 * Collapsed to a single line by default and opened with a <details> element,
 * which is the whole interaction — no JavaScript, so it cannot break the page
 * it is measuring. A diagnostic tool that introduces its own bugs is worse
 * than no diagnostic tool, and this one renders on every page somebody with
 * the capability loads.
 *
 * Styles are inline for the same reason: a stylesheet would be one more
 * request, and this bar has to work on a page whose CSS has not loaded, which
 * is exactly the page somebody is debugging.
 */
final class QueryMonitorBar
{
    /**
     * @param list<array{sql: string, ms: float, rows: int}>       $log
     * @param list<array{method: string, url: string, status: int, ms: float}> $httpLog
     */
    public static function render(
        array $log,
        int $totalQueries,
        float $totalMs,
        array $httpLog,
        float $httpMs,
        float $requestMs,
        int $peakMemoryBytes
    ): string {
        $summary = QueryReport::summarise($log, $totalQueries, $totalMs);
        $duplicates = QueryReport::duplicates($log);
        $slow = QueryReport::slow($log);

        $verdict = e(QueryReport::verdict($summary, $duplicates, $slow));

        /*
         * The headline counts stay on the closed bar, so the numbers that
         * matter need no click. Everything requiring reading goes inside.
         */
        $headline = sprintf(
            '%d quer%s · %sms SQL · %sms total · %s',
            $summary['queries'],
            $summary['queries'] === 1 ? 'y' : 'ies',
            $summary['ms'],
            round($requestMs, 1),
            self::bytes($peakMemoryBytes)
        );

        if ($httpLog !== []) {
            $headline .= sprintf(' · %d outbound (%sms)', count($httpLog), round($httpMs, 1));
        }

        $sections = self::duplicateSection($duplicates)
            . self::slowSection($slow)
            . self::httpSection($httpLog)
            . self::allQueriesSection($log, $summary['truncated']);

        $css = self::css();

        return <<<HTML
        <div id="query-monitor">
          <details>
            <summary>
              <strong>Query Monitor</strong>
              <span class="qm-headline">{$headline}</span>
            </summary>
            <p class="qm-verdict">{$verdict}</p>
            {$sections}
          </details>
        </div>
        <style>{$css}</style>
        HTML;
    }

    /** @param list<array{sql: string, times: int, ms: float}> $duplicates */
    private static function duplicateSection(array $duplicates): string
    {
        if ($duplicates === []) {
            return '';
        }

        $rows = '';
        foreach ($duplicates as $group) {
            $rows .= sprintf(
                '<tr><td class="qm-num">%d×</td><td class="qm-num">%sms</td><td><code>%s</code></td></tr>',
                $group['times'],
                $group['ms'],
                e($group['sql'])
            );
        }

        return '<h4>Repeated statements</h4>'
            . '<p class="qm-note">The same statement, run more than once. This is what a query inside a '
            . 'loop looks like, and the fix is almost always to fetch them all at once.</p>'
            . '<table>' . $rows . '</table>';
    }

    /** @param list<array{sql: string, ms: float, rows: int}> $slow */
    private static function slowSection(array $slow): string
    {
        if ($slow === []) {
            return '';
        }

        $rows = '';
        foreach ($slow as $entry) {
            $rows .= sprintf(
                '<tr><td class="qm-num">%sms</td><td class="qm-num">%d rows</td><td><code>%s</code></td></tr>',
                $entry['ms'],
                $entry['rows'],
                e($entry['sql'])
            );
        }

        return '<h4>Slow statements</h4>'
            . '<p class="qm-note">Over ' . QueryReport::SLOW_MS . 'ms each. A different problem from the one '
            . 'above, with a different answer: this one usually wants an index.</p>'
            . '<table>' . $rows . '</table>';
    }

    /** @param list<array{method: string, url: string, status: int, ms: float}> $httpLog */
    private static function httpSection(array $httpLog): string
    {
        if ($httpLog === []) {
            return '';
        }

        $rows = '';
        foreach ($httpLog as $call) {
            $rows .= sprintf(
                '<tr><td class="qm-num">%sms</td><td class="qm-num">%s</td><td><code>%s %s</code></td></tr>',
                $call['ms'],
                $call['status'] === 0 ? 'failed' : (string) $call['status'],
                e($call['method']),
                e($call['url'])
            );
        }

        return '<h4>Outbound requests</h4>'
            . '<p class="qm-note">Calls to bunny.net, the mail provider, or an identity provider. A slow page '
            . 'is far more often one of these than it is SQL. Query strings are never logged, because signed '
            . 'URLs carry tokens in them.</p>'
            . '<table>' . $rows . '</table>';
    }

    /** @param list<array{sql: string, ms: float, rows: int}> $log */
    private static function allQueriesSection(array $log, bool $truncated): string
    {
        if ($log === []) {
            return '';
        }

        $rows = '';
        foreach ($log as $index => $entry) {
            $rows .= sprintf(
                '<tr><td class="qm-num">%d</td><td class="qm-num">%sms</td><td class="qm-num">%d</td>'
                . '<td><code>%s</code></td></tr>',
                $index + 1,
                $entry['ms'],
                $entry['rows'],
                e(QueryReport::tidy($entry['sql']))
            );
        }

        $note = $truncated
            ? '<p class="qm-note">The log stops at 500 statements, so the counts above are floors rather than '
              . 'totals — and a request that hit the cap is already the problem.</p>'
            : '';

        return '<h4>Every statement, in order</h4>' . $note . '<table>' . $rows . '</table>';
    }

    /**
     * Everything is scoped under #query-monitor and fixed to the bottom.
     *
     * Deliberately not inheriting anything from the page: this renders inside
     * a theme somebody else may have written, and a diagnostic panel that
     * picks up the site's font sizes is unreadable exactly when the site's CSS
     * is what has gone wrong.
     */
    private static function css(): string
    {
        return <<<'CSS'
        #query-monitor {
          position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147483000;
          font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
          background: #14161a; color: #e6e8ec; border-top: 2px solid #3a7bd5;
          max-height: 70vh; overflow: auto;
        }
        #query-monitor summary {
          cursor: pointer; padding: 6px 12px; list-style: none;
          display: flex; gap: 12px; align-items: baseline;
        }
        #query-monitor summary::-webkit-details-marker { display: none; }
        #query-monitor strong { color: #7fb3f5; }
        #query-monitor .qm-headline { color: #b9bec7; }
        #query-monitor .qm-verdict { margin: 0; padding: 8px 12px; background: #1b1e24; color: #ffd479; }
        #query-monitor .qm-note { margin: 0; padding: 4px 12px 8px; color: #98a0ac; max-width: 80ch; }
        #query-monitor h4 { margin: 12px 0 0; padding: 0 12px; color: #7fb3f5; font-size: 12px; }
        #query-monitor table { width: 100%; border-collapse: collapse; }
        #query-monitor td { padding: 3px 12px; border-top: 1px solid #262a31; vertical-align: top; }
        #query-monitor .qm-num { white-space: nowrap; text-align: right; color: #98a0ac; width: 1%; }
        #query-monitor code { color: #d7dbe2; word-break: break-word; }
        CSS;
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . 'MB'
            : max(1, (int) round($bytes / 1024)) . 'KB';
    }
}
