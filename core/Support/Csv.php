<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Writing CSV that a spreadsheet will not execute.
 *
 * Pure, and it exists for one reason that is not obvious from the format.
 *
 * A CSV is a text file, so it feels like the safe end of the export problem.
 * It is not. Excel, LibreOffice and Google Sheets all treat a cell beginning
 * with `=`, `+`, `-` or `@` as a FORMULA, and formulas can call out to the
 * network, read other cells, and in Excel's case invoke DDE to run a command —
 * with a warning most people click through, because they asked for this file
 * and it came from their own site.
 *
 * Which makes an export of titles an editor typed a way to hand somebody a
 * document that attacks them when opened. Nothing about the export is
 * compromised; the values are exactly what was stored. The spreadsheet is what
 * turns them into code, so this is where they have to be defused.
 */
final class Csv
{
    /**
     * Characters a spreadsheet reads as the start of a formula.
     *
     * The tab and carriage return are here because Excel strips leading
     * whitespace before deciding, so " =1+1" is still a formula to it.
     */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * One value, escaped for CSV and defused for spreadsheets.
     *
     * The defusing is a leading apostrophe, which every spreadsheet reads as
     * "this cell is text" and hides. Not a space, which some show and some
     * strip; not stripping the character, which would quietly corrupt a title
     * that legitimately begins with a minus.
     */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], self::FORMULA_STARTS, true)) {
            $value = "'" . $value;
        }

        /*
         * RFC 4180 quoting: wrap in quotes and double any quote inside. Applied
         * whenever the value contains a separator, a quote or a newline — and
         * also after the apostrophe above, since a defused value is still
         * ordinary text that may contain a comma.
         */
        if (preg_match('/[",\r\n;]/', $value) === 1) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /** @param list<mixed> $values */
    public static function row(array $values): string
    {
        // CRLF, which RFC 4180 specifies and Excel on Windows needs to avoid
        // reading the whole file as one line.
        return implode(',', array_map([self::class, 'cell'], $values)) . "\r\n";
    }

    /**
     * A whole document.
     *
     * @param list<string>       $headings
     * @param list<list<mixed>>  $rows
     */
    public static function document(array $headings, array $rows): string
    {
        /*
         * A UTF-8 byte order mark, deliberately.
         *
         * Excel assumes the system codepage for a .csv without one, so a
         * speaker called "Müller" opens as "MÃ¼ller" — which reads to whoever
         * opened it as the site having mangled their data. Every other consumer
         * tolerates the mark.
         */
        $out = "\xEF\xBB\xBF" . self::row($headings);

        foreach ($rows as $row) {
            $out .= self::row($row);
        }

        return $out;
    }

    /**
     * A filename that is safe in a Content-Disposition header.
     *
     * Anything that is not plainly a name is dropped rather than escaped: the
     * header has no room for cleverness, and a quote or a newline in it is a
     * response-splitting bug rather than an odd filename.
     */
    public static function filename(string $base, ?string $date = null): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($base)) ?? 'export';
        $base = trim($base, '-');

        if ($base === '') {
            $base = 'export';
        }

        return $base . '-' . ($date ?? date('Y-m-d')) . '.csv';
    }
}
