<?php

declare(strict_types=1);

namespace Portal\Schedules;

/**
 * Turning a spreadsheet into rota rows.
 *
 * Pure: text in, rows and problems out. No database, no HTTP, no writes — so
 * the preview screen and the sync run the SAME code and cannot disagree about
 * what a sheet says. A preview that is a separate implementation of the parser
 * is a preview of something else.
 *
 * # THE TWO LAYOUTS
 *
 * ROWS — one line per person per date, the shape a form or a database export
 * gives:
 *
 *     Date        | Name       | Doing  | Note
 *     2026-09-06  | Jane Cole  | Coffee |
 *
 * GRID — a date per line and a job per column, the shape a rota kept by hand
 * actually takes, because it is the one you can read at a glance:
 *
 *     Date   | Coffee    | Reading   | Sound
 *     6 Sep  | Jane Cole | Sam Ives  | Ade O.
 *
 * Both are offered because refusing the second would mean asking every church
 * that already keeps a rota to retype it, and they would not.
 *
 * # WHAT IS DELIBERATELY NOT DONE
 *
 * A cell holding two names is ONE name. Splitting on "&" or "/" or "and" reads
 * "Anne & Sons" as two people and "Mary-Jane" as one, and there is no rule that
 * gets both right — so the cell is taken as written and whoever keeps the sheet
 * puts the second person in their own row or column.
 *
 * An empty cell is nobody yet, which is a normal state for a rota six weeks out
 * and is not reported as a problem. An unreadable DATE is, because that row is
 * silently lost otherwise.
 */
final class SheetLayout
{
    public const ROWS = 'rows';
    public const GRID = 'grid';

    /** Enough problems to see the pattern; past that it is one broken sheet. */
    private const MAX_PROBLEMS = 25;

    /** @var list<string> */
    private const DATE_HEADINGS = ['date', 'day', 'when', 'dates'];

    /** @var list<string> */
    private const NAME_HEADINGS = ['name', 'who', 'person', 'volunteer', 'server'];

    /** @var list<string> */
    private const ROLE_HEADINGS = ['role', 'doing', 'job', 'task', 'position', 'duty', 'what'];

    /** @var list<string> */
    private const NOTE_HEADINGS = ['note', 'notes', 'comment', 'comments'];

    /**
     * @return array{
     *     rows: list<array{date: string, name: string, role: string, note: string}>,
     *     problems: list<string>
     * }
     */
    public static function read(string $csv, string $layout, string $dateOrder = SheetDate::AUTO): array
    {
        $table = self::table($csv);

        return $layout === self::GRID
            ? self::readGrid($table, $dateOrder)
            : self::readRows($table, $dateOrder);
    }

    /**
     * The CSV, as a table.
     *
     * Through a stream and fgetcsv() rather than str_getcsv() per line, because
     * a quoted cell may contain a newline — a note field with two lines in it
     * would otherwise split the row in half and take the rest of the sheet with
     * it.
     *
     * @return list<list<string>>
     */
    public static function table(string $csv): array
    {
        // A BOM survives a Google export and would make the first heading
        // unmatchable while looking identical on screen.
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $csv);
        rewind($handle);

        $table = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $table[] = array_map(static fn ($cell): string => trim((string) $cell), $row);
        }

        fclose($handle);

        return $table;
    }

    // ------------------------------------------------------------ layouts

    /**
     * @param list<list<string>> $table
     * @return array{rows: list<array{date: string, name: string, role: string, note: string}>, problems: list<string>}
     */
    private static function readRows(array $table, string $dateOrder): array
    {
        $header = null;
        $headerLine = 0;

        foreach ($table as $i => $row) {
            $map = self::mapColumns($row);

            if ($map !== null) {
                $header = $map;
                $headerLine = $i;
                break;
            }
        }

        if ($header === null) {
            return [
                'rows'     => [],
                'problems' => [sprintf(
                    'No heading row was found. One row needs a %s column and a %s column.',
                    self::orList(self::DATE_HEADINGS),
                    self::orList(self::NAME_HEADINGS)
                )],
            ];
        }

        $rows = [];
        $problems = [];

        foreach (array_slice($table, $headerLine + 1) as $offset => $row) {
            $line = $headerLine + $offset + 2;
            $name = self::cell($row, $header['name']);
            $rawDate = self::cell($row, $header['date']);

            // A wholly blank line is a gap in the sheet, not a fault.
            if ($name === '' && $rawDate === '') {
                continue;
            }

            $date = SheetDate::parse($rawDate, $dateOrder);

            if ($date === null) {
                self::addProblem($problems, self::dateProblem($line, $rawDate));
                continue;
            }

            // Nobody on this date yet. Normal, and not worth reporting.
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'name' => $name,
                'role' => $header['role'] === null ? '' : self::cell($row, $header['role']),
                'note' => $header['note'] === null ? '' : self::cell($row, $header['note']),
            ];
        }

        return ['rows' => $rows, 'problems' => $problems];
    }

    /**
     * @param list<list<string>> $table
     * @return array{rows: list<array{date: string, name: string, role: string, note: string}>, problems: list<string>}
     */
    private static function readGrid(array $table, string $dateOrder): array
    {
        $headerLine = null;

        /*
         * The heading row is the first one with something in at least two
         * columns whose FIRST cell is not itself a date — which skips a title
         * line, a blank line, and the "Autumn rota" banner every real sheet
         * has above the table.
         */
        foreach ($table as $i => $row) {
            if (self::filled($row) < 2) {
                continue;
            }

            if (SheetDate::parse(self::cell($row, 0), $dateOrder) !== null) {
                continue;
            }

            $headerLine = $i;
            break;
        }

        if ($headerLine === null) {
            return [
                'rows'     => [],
                'problems' => ['No heading row was found. The first column holds the dates and '
                    . 'each heading after it names a job.'],
            ];
        }

        $roles = $table[$headerLine];
        $rows = [];
        $problems = [];

        foreach (array_slice($table, $headerLine + 1) as $offset => $row) {
            $line = $headerLine + $offset + 2;

            if (self::filled($row) === 0) {
                continue;
            }

            $rawDate = self::cell($row, 0);
            $date = SheetDate::parse($rawDate, $dateOrder);

            if ($date === null) {
                self::addProblem($problems, self::dateProblem($line, $rawDate));
                continue;
            }

            for ($column = 1, $last = count($row); $column < $last; $column++) {
                $name = self::cell($row, $column);

                if ($name === '') {
                    continue;
                }

                $rows[] = [
                    'date' => $date,
                    // An unheaded column still holds a real person, so it is
                    // kept with no job rather than dropped.
                    'role' => self::cell($roles, $column),
                    'name' => $name,
                    'note' => '',
                ];
            }
        }

        return ['rows' => $rows, 'problems' => $problems];
    }

    // ---------------------------------------------------------- internals

    /**
     * Which column is which, or null if this row is not a heading row.
     *
     * @param list<string> $row
     * @return array{date: int, name: int, role: int|null, note: int|null}|null
     */
    private static function mapColumns(array $row): ?array
    {
        $date = self::findColumn($row, self::DATE_HEADINGS);
        $name = self::findColumn($row, self::NAME_HEADINGS);

        if ($date === null || $name === null) {
            return null;
        }

        return [
            'date' => $date,
            'name' => $name,
            'role' => self::findColumn($row, self::ROLE_HEADINGS),
            'note' => self::findColumn($row, self::NOTE_HEADINGS),
        ];
    }

    /**
     * @param list<string> $row
     * @param list<string> $wanted
     */
    private static function findColumn(array $row, array $wanted): ?int
    {
        foreach ($row as $index => $cell) {
            $heading = strtolower(trim($cell));

            if ($heading !== '' && in_array($heading, $wanted, true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param list<string> $row */
    private static function cell(array $row, int $index): string
    {
        return trim($row[$index] ?? '');
    }

    /** @param list<string> $row */
    private static function filled(array $row): int
    {
        return count(array_filter($row, static fn (string $cell): bool => trim($cell) !== ''));
    }

    private static function dateProblem(int $line, string $raw): string
    {
        if ($raw === '') {
            return sprintf('Row %d has no date.', $line);
        }

        /*
         * Two different sentences on purpose. "Not a date" sends somebody to
         * fix a typo; "could be either" sends them to the one setting that
         * resolves it, and telling them the wrong one wastes the afternoon.
         */
        if (SheetDate::isAmbiguous($raw)) {
            return sprintf(
                'Row %d: "%s" could be either day-month or month-day. Say which the sheet uses.',
                $line,
                $raw
            );
        }

        return sprintf('Row %d: "%s" is not a date this can read.', $line, $raw);
    }

    /**
     * @param list<string> $problems
     */
    private static function addProblem(array &$problems, string $problem): void
    {
        if (count($problems) < self::MAX_PROBLEMS) {
            $problems[] = $problem;

            return;
        }

        if (count($problems) === self::MAX_PROBLEMS) {
            $problems[] = 'More rows have the same trouble; the rest are not listed.';
        }
    }

    /** @param list<string> $words */
    private static function orList(array $words): string
    {
        $quoted = array_map(static fn (string $word): string => '"' . $word . '"', $words);
        $last = array_pop($quoted);

        return implode(', ', $quoted) . ' or ' . $last;
    }
}
