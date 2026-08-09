<?php

declare(strict_types=1);

namespace Portal\Plugins\QueryMonitor;

/**
 * Reading a request's query log.
 *
 * Pure: a log in, numbers out, no database and no idea what a request is. The
 * whole class exists because the interesting question is not "how many
 * queries" — that number alone tells nobody what to do — but "which statement
 * ran twenty-eight times", which is the shape every N+1 problem takes and the
 * one thing a person can act on.
 *
 * A note that makes this feature safe to render at all: the logged SQL is the
 * PREPARED statement, with placeholders where the values were. Db never
 * records bound parameters and Http never records a query string. So nothing
 * here can print an email address, a session id, or a signed token, and that
 * is a property of the logs rather than of this class being careful — which is
 * the right place for it, because this class is the sort of thing somebody
 * extends in a hurry.
 */
final class QueryReport
{
    /**
     * How many times a statement has to repeat before it is worth naming.
     *
     * Two is not a problem — a count and a page of rows is two queries and
     * exactly right. Three is where a loop starts to look like a loop.
     */
    public const REPEAT_THRESHOLD = 3;

    /** Statements slower than this get called out however few there are. */
    public const SLOW_MS = 50.0;

    /** Long SQL is cut for display; the shape is what matters, not the tail. */
    private const MAX_SQL_LENGTH = 300;

    /**
     * The headline numbers.
     *
     * Takes the totals rather than deriving them from the log, because the log
     * is capped at 500 entries and the counters are not. A page issuing a
     * thousand queries is exactly the page this plugin exists to catch, and
     * counting the array would report 500 and hide it.
     *
     * @param  list<array{sql: string, ms: float, rows: int}> $log
     * @return array{queries: int, ms: float, logged: int, truncated: bool, slowest: array{sql: string, ms: float}|null}
     */
    public static function summarise(array $log, int $totalQueries, float $totalMs): array
    {
        $slowest = null;

        foreach ($log as $entry) {
            if ($slowest === null || $entry['ms'] > $slowest['ms']) {
                $slowest = ['sql' => self::tidy($entry['sql']), 'ms' => (float) $entry['ms']];
            }
        }

        return [
            'queries'   => $totalQueries,
            'ms'        => round($totalMs, 2),
            'logged'    => count($log),
            // Said out loud rather than left for somebody to infer from a
            // suspiciously round 500. A truncated log means the duplicate
            // counts below are floors, not totals.
            'truncated' => $totalQueries > count($log),
            'slowest'   => $slowest,
        ];
    }

    /**
     * Statements that ran more than once, worst first.
     *
     * Grouped on the exact statement text after whitespace is collapsed, which
     * means a batched `IN (?, ?, ?)` and a batched `IN (?, ?)` count as two
     * different queries. That is correct rather than a limitation: they are two
     * different statements to MySQL, and folding them together would hide the
     * case where a loop is issuing differently-sized batches.
     *
     * @param  list<array{sql: string, ms: float, rows: int}> $log
     * @return list<array{sql: string, times: int, ms: float}>
     */
    public static function duplicates(array $log): array
    {
        $groups = [];

        foreach ($log as $entry) {
            $key = self::tidy($entry['sql']);

            if (!isset($groups[$key])) {
                $groups[$key] = ['sql' => $key, 'times' => 0, 'ms' => 0.0];
            }

            $groups[$key]['times']++;
            $groups[$key]['ms'] += (float) $entry['ms'];
        }

        $repeated = array_values(array_filter(
            $groups,
            static fn (array $group): bool => $group['times'] >= self::REPEAT_THRESHOLD
        ));

        // By count, not by time. A statement that ran forty times in nine
        // milliseconds is still the bug; the fix is to stop running it forty
        // times, and sorting by duration would bury it under one slow join
        // that is behaving exactly as intended.
        usort($repeated, static fn (array $a, array $b): int => $b['times'] <=> $a['times']);

        foreach ($repeated as $index => $group) {
            $repeated[$index]['ms'] = round($group['ms'], 2);
        }

        return $repeated;
    }

    /**
     * Statements slow enough to be worth a look on their own.
     *
     * Separate from duplicates because they are different problems with
     * different fixes: a repeated query wants a batch, a slow one wants an
     * index. A panel that merged them would leave somebody applying the wrong
     * remedy to whichever line was at the top.
     *
     * @param  list<array{sql: string, ms: float, rows: int}> $log
     * @return list<array{sql: string, ms: float, rows: int}>
     */
    public static function slow(array $log): array
    {
        $slow = [];

        foreach ($log as $entry) {
            if ((float) $entry['ms'] >= self::SLOW_MS) {
                $slow[] = [
                    'sql'  => self::tidy($entry['sql']),
                    'ms'   => round((float) $entry['ms'], 2),
                    'rows' => (int) $entry['rows'],
                ];
            }
        }

        usort($slow, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return $slow;
    }

    /**
     * A one-line verdict.
     *
     * The panel is a wall of numbers and most people will read one thing. This
     * is that thing, and it names the worst finding rather than averaging the
     * request into a grade — "fine on the whole" is not useful to somebody
     * whose page is slow.
     *
     * @param array{queries: int, ms: float, logged: int, truncated: bool, slowest: array{sql: string, ms: float}|null} $summary
     * @param list<array{sql: string, times: int, ms: float}> $duplicates
     * @param list<array{sql: string, ms: float, rows: int}>  $slow
     */
    public static function verdict(array $summary, array $duplicates, array $slow): string
    {
        if ($duplicates !== []) {
            return sprintf(
                'One statement ran %d times. That is the shape of a query inside a loop.',
                $duplicates[0]['times']
            );
        }

        if ($slow !== []) {
            return sprintf(
                'The slowest statement took %sms. Worth an index if it is on a page people wait for.',
                $slow[0]['ms']
            );
        }

        if ($summary['queries'] === 0) {
            return 'No database queries on this request.';
        }

        return sprintf(
            '%d quer%s in %sms, nothing repeated.',
            $summary['queries'],
            $summary['queries'] === 1 ? 'y' : 'ies',
            $summary['ms']
        );
    }

    /**
     * SQL as one line, cut to a readable length.
     *
     * Statements in this codebase are written across several lines with
     * generous indentation, so the raw text groups badly — two calls to the
     * same query would count separately if either was ever reformatted.
     */
    public static function tidy(string $sql): string
    {
        $tidied = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($tidied) > self::MAX_SQL_LENGTH
            ? substr($tidied, 0, self::MAX_SQL_LENGTH) . '…'
            : $tidied;
    }
}
