<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Reading a chapter list.
 *
 * Pure. The input format is the one people already have: a timestamp and a
 * title on each line, exactly what gets pasted into a YouTube description.
 *
 *     0:00 Welcome
 *     2:15 The reading
 *     14:30 Questions
 *
 * That convention was chosen over a structured editor because it is what
 * exists. Somebody who has marked up a video anywhere else can paste their
 * list here unchanged, and somebody who has not can type it faster than they
 * could operate a row-at-a-time form.
 *
 * The parser accepts more shapes than the convention strictly allows —
 * brackets, dashes, hours or not — because every one of them turns up in real
 * lists and refusing them teaches nobody anything.
 */
final class ChapterParser
{
    /**
     * A ceiling. Two hundred chapters is a marker every twenty seconds on an
     * hour-long recording; past that it is not a chapter list.
     */
    public const MAX_CHAPTERS = 200;

    public const MAX_TITLE_LENGTH = 190;

    /**
     * Parse a pasted chapter list.
     *
     * Lines that carry no timestamp are skipped rather than failing the import.
     * A pasted list routinely has a heading on top or a blank line between
     * sections, and losing the whole list to one stray line would be the wrong
     * response to something nobody would notice writing.
     *
     * @return list<array{start: int, title: string}>
     */
    public static function parse(string $raw): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($raw));

        if ($text === '') {
            return [];
        }

        $chapters = [];
        $seen = [];

        foreach (explode("\n", $text) as $line) {
            $chapter = self::parseLine($line);

            if ($chapter === null) {
                continue;
            }

            /*
             * One chapter per moment. A duplicated timestamp is a paste that
             * went in twice, and two markers at the same second would render as
             * two identical links.
             */
            if (isset($seen[$chapter['start']])) {
                continue;
            }

            $seen[$chapter['start']] = true;
            $chapters[] = $chapter;

            if (count($chapters) >= self::MAX_CHAPTERS) {
                break;
            }
        }

        // In time order regardless of how they were pasted, because that is
        // the only order a chapter list can be read in.
        usort($chapters, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $chapters;
    }

    /**
     * Render chapters back into the format they were pasted in.
     *
     * So the edit screen can show what is stored in the same shape somebody
     * typed it, rather than making them rebuild the list to change one title.
     *
     * @param list<array{start: int, title: string}> $chapters
     */
    public static function toText(array $chapters): string
    {
        $lines = [];

        foreach ($chapters as $chapter) {
            $lines[] = self::formatTimestamp($chapter['start']) . ' ' . $chapter['title'];
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------- internals

    /**
     * @return array{start: int, title: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        /*
         * A timestamp at the START of the line only.
         *
         * A title that happens to contain a time — "Psalm 1:1" — must not be
         * read as a marker, and anchoring is what prevents it. The trade is
         * that "Welcome 0:00" does not parse, which nobody writes.
         */
        if (preg_match('/^\[?\(?((?:\d{1,3}:)?\d{1,2}:\d{1,2})\)?\]?\s*[-–—:]?\s*(.*)$/u', $line, $matches) !== 1) {
            return null;
        }

        $start = self::toSeconds($matches[1]);

        if ($start === null) {
            return null;
        }

        $title = trim($matches[2]);

        if ($title === '') {
            return null;
        }

        return [
            'start' => $start,
            'title' => mb_substr($title, 0, self::MAX_TITLE_LENGTH),
        ];
    }

    private static function toSeconds(string $stamp): ?int
    {
        $parts = explode(':', $stamp);

        if (count($parts) === 2) {
            // M:SS, which is how every list starts.
            array_unshift($parts, '0');
        }

        if (count($parts) !== 3) {
            return null;
        }

        $minutes = (int) $parts[1];
        $seconds = (int) $parts[2];

        /*
         * "1:75" is a typo, not seventy-five seconds. Refused rather than
         * normalised, because normalising silently moves a marker to a moment
         * nobody chose and the list still looks right.
         */
        if ($minutes > 59 || $seconds > 59) {
            return null;
        }

        return ((int) $parts[0] * 3600) + ($minutes * 60) + $seconds;
    }

    private static function formatTimestamp(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);

        // Hours only when there are some, matching how people write them.
        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
