<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Turning a subtitle file into timed lines.
 *
 * Pure: a string in, cues out. WebVTT and SubRip both, because those are the
 * two formats anybody actually has — every captioning service and every
 * transcription tool exports one or the other, and asking a site owner to
 * convert is asking them not to use the feature.
 *
 * The parser is deliberately forgiving. A transcript that fails to import
 * teaches nobody anything: the file came from a machine, the person pasting it
 * did not write it and cannot fix it, and the useful behaviour is to take the
 * cues that parse and ignore the rest. What it will NOT do is guess at
 * timestamps — a cue without a readable time is dropped, because a line placed
 * at the wrong moment is worse than a line missing.
 */
final class TranscriptParser
{
    /**
     * A ceiling on cues.
     *
     * A three-hour sermon transcribed at four seconds a cue is about 2,700
     * lines, so this is generous. It exists because the input arrives from a
     * textarea and an upload, and neither should be able to turn one request
     * into a hundred thousand inserts.
     */
    public const MAX_CUES = 20000;

    /** Longer than this and it is not a subtitle line. */
    public const MAX_TEXT_LENGTH = 2000;

    /**
     * Parse a VTT or SRT document.
     *
     * The format is detected rather than declared: the two are similar enough
     * that one parser handles both, and asking somebody to pick from a dropdown
     * is asking them to get it wrong.
     *
     * @return list<array{start: int, end: int, text: string}> seconds, and the line
     */
    public static function parse(string $raw): array
    {
        $text = self::normalize($raw);

        if ($text === '') {
            return [];
        }

        $cues = [];

        // Blocks are separated by a blank line in both formats.
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            foreach (self::parseBlock($block) as $cue) {
                $cues[] = $cue;

                if (count($cues) >= self::MAX_CUES) {
                    break 2;
                }
            }
        }

        /*
         * Sorted by start time, and only here.
         *
         * Files out of order exist — a merge of two transcripts, or an editor
         * appending a correction. The display and the "jump to this moment"
         * behaviour both assume order, so it is established once rather than
         * assumed everywhere.
         */
        usort($cues, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $cues;
    }

    /**
     * Everything said, as one block of text.
     *
     * What search indexes. Timestamps are not useful to a keyword search and
     * would match a query like "2026" on every transcript in the library.
     */
    public static function plainText(array $cues): string
    {
        return trim(implode(' ', array_column($cues, 'text')));
    }

    /**
     * Render cues back out as WebVTT.
     *
     * The download path. Cues are stored parsed rather than as the original
     * file, so this is a regeneration and not a copy — inline styling and
     * positioning from the source are gone. For a transcript panel that is the
     * right trade; a site that needs pixel-accurate captions should attach the
     * original file as an asset.
     *
     * @param list<array{start: int, end: int, text: string}> $cues
     */
    public static function toVtt(array $cues): string
    {
        $out = "WEBVTT\n\n";

        foreach ($cues as $index => $cue) {
            $out .= ($index + 1) . "\n";
            $out .= self::formatTimestamp($cue['start']) . ' --> ' . self::formatTimestamp($cue['end']) . "\n";
            $out .= $cue['text'] . "\n\n";
        }

        return $out;
    }

    // ------------------------------------------------------------- internals

    private static function normalize(string $raw): string
    {
        // A BOM survives every copy-paste and makes the first timestamp
        // unparseable, which presents as "the first line is always missing".
        $raw = preg_replace('/^\x{FEFF}/u', '', $raw) ?? $raw;

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Invalid UTF-8 from a Windows-1252 export would otherwise survive as
        // far as the database and fail there, one insert into a transaction.
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return trim($raw);
    }

    /**
     * Every cue in one block.
     *
     * A block usually holds exactly one, but this returns a list because a
     * malformed file is the normal case rather than the exception. Two shapes
     * turn up constantly, and splitting the block at EVERY timing line handles
     * both, where taking the first would mishandle each.
     *
     * A file with no blank lines between cues is one "block" holding the whole
     * transcript. Taking the first timestamp and everything after it as the
     * text would produce a single cue containing the entire recording,
     * timestamps and all — and search would then index "00:04:12.000" as a
     * word somebody could match.
     *
     * A cue preceded by prose somebody pasted in. The words after the
     * timestamp were said at a known moment, so they are kept.
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    private static function parseBlock(string $block): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $block)),
            static fn (string $line): bool => $line !== ''
        ));

        if ($lines === []) {
            return [];
        }

        // WebVTT metadata blocks. Dropped whole: NOTE and STYLE bodies are not
        // spoken words, and a comment rendered as a transcript line is worse
        // than the comment being lost.
        $first = $lines[0];
        if (str_starts_with($first, 'WEBVTT')
            || str_starts_with($first, 'NOTE')
            || str_starts_with($first, 'STYLE')
            || str_starts_with($first, 'REGION')) {
            return [];
        }

        $cues = [];
        $timing = null;
        $text = [];

        $flush = static function () use (&$cues, &$timing, &$text): void {
            if ($timing === null) {
                return;
            }

            $joined = self::cleanText(implode("\n", $text));

            if ($joined !== '') {
                $cues[] = ['start' => $timing[0], 'end' => $timing[1], 'text' => $joined];
            }

            $timing = null;
            $text = [];
        };

        foreach ($lines as $line) {
            $parsed = self::parseTiming($line);

            if ($parsed !== null) {
                // A new timing line ends whatever came before it.
                $flush();
                $timing = $parsed;
                continue;
            }

            if ($timing !== null) {
                $text[] = $line;
            }

            /*
             * Anything before the first timing line is neither kept nor a
             * reason to abandon the block. That covers a cue identifier, which
             * is meaningless here, and stray prose somebody pasted in — the
             * words after the timestamp were genuinely said at a known moment,
             * and losing them to tidy up around them is the wrong trade.
             */
        }

        $flush();

        return $cues;
    }

    /**
     * @return array{0: int, 1: int}|null start and end, in seconds
     */
    private static function parseTiming(string $line): ?array
    {
        // "00:00:01.000 --> 00:00:04.000", with SRT's comma allowed and the
        // hours optional, which WebVTT permits. Cue settings after the end
        // time (align, position) are matched and discarded.
        $pattern = '/^((?:\d{1,3}:)?\d{1,2}:\d{1,2}[.,]\d{1,3})\s*-->\s*((?:\d{1,3}:)?\d{1,2}:\d{1,2}[.,]\d{1,3})/';

        if (preg_match($pattern, $line, $matches) !== 1) {
            return null;
        }

        $start = self::toSeconds($matches[1]);
        $end = self::toSeconds($matches[2]);

        if ($start === null || $end === null) {
            return null;
        }

        /*
         * An end before the start is a broken cue, not a reason to reject the
         * file. Clamped to the start so it lands at a defensible moment rather
         * than being dropped — the words were said, and the timestamp being
         * slightly wrong is better than the line vanishing.
         */
        return [$start, max($start, $end)];
    }

    private static function toSeconds(string $stamp): ?int
    {
        $stamp = str_replace(',', '.', $stamp);
        $parts = explode(':', $stamp);

        if (count($parts) === 2) {
            // MM:SS.mmm — legal WebVTT, and what most short clips use.
            array_unshift($parts, '0');
        }

        if (count($parts) !== 3) {
            return null;
        }

        // Milliseconds are read and then dropped. A transcript panel seeks to
        // the second; sub-second precision is noise that would make two cues
        // in the same second look different in the interface.
        return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
    }

    private static function cleanText(string $text): string
    {
        /*
         * Inline markup goes. WebVTT allows <v Speaker>, <i>, <c.classname>
         * and timestamp tags; none of them mean anything in a transcript panel
         * and all of them would be escaped into visible angle brackets.
         *
         * The speaker name inside <v Name> is kept, because on a recording with
         * two people it is the most useful thing on the line.
         */
        $text = preg_replace('/<v\s+([^>]+)>/u', '$1: ', $text) ?? $text;
        $text = preg_replace('/<[^>]*>/u', '', $text) ?? $text;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Newlines within a cue are a rendering hint for a subtitle box, not a
        // paragraph break. In a transcript they read as one sentence.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_substr(trim($text), 0, self::MAX_TEXT_LENGTH);
    }

    private static function formatTimestamp(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf(
            '%02d:%02d:%02d.000',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }
}
