<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Preparing a subtitle file for a player that is not ours.
 *
 * Captions and transcripts come out of the same file and are not the same
 * thing, which is why this exists next to TranscriptParser rather than reusing
 * it. A transcript is read: it is prose with times attached, second precision
 * is plenty, and stripping markup makes it better. A caption is displayed over
 * a moving picture at a moment somebody chose, and being a second early makes
 * it appear over the wrong shot. So this converts TEXTUALLY — timing lines are
 * rewritten in place and everything else is passed through byte for byte —
 * where the transcript parser deliberately re-serialises and loses the
 * milliseconds, the positioning, and the styling.
 *
 * Pure. A string in, a string out, and no idea where either came from.
 */
final class CaptionFile
{
    /**
     * A ceiling before anything is read.
     *
     * A feature-length caption file is tens of kilobytes. Two megabytes is
     * absurd headroom, and the point of the limit is that a shared host must
     * not run out of memory finding out that an upload was not a caption file.
     */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Caps the language tag, which becomes a URL path segment at the provider. */
    private const MAX_LANGUAGE_LENGTH = 20;

    /** Caps the label, which is what the player's menu shows. */
    private const MAX_LABEL_LENGTH = 60;

    /**
     * Languages offered in the dropdown, and their names.
     *
     * Not a complete list and not meant to be — it is the set somebody picks
     * from without typing. The field accepts anything valid alongside it, so a
     * language missing from here is inconvenient rather than impossible.
     *
     * @return array<string, string>
     */
    public static function languages(): array
    {
        return [
            'en'    => 'English',
            'es'    => 'Spanish',
            'fr'    => 'French',
            'de'    => 'German',
            'pt'    => 'Portuguese',
            'pt-br' => 'Portuguese (Brazil)',
            'it'    => 'Italian',
            'nl'    => 'Dutch',
            'pl'    => 'Polish',
            'ru'    => 'Russian',
            'uk'    => 'Ukrainian',
            'ro'    => 'Romanian',
            'ar'    => 'Arabic',
            'fa'    => 'Persian',
            'hi'    => 'Hindi',
            'ur'    => 'Urdu',
            'zh'    => 'Chinese',
            'ja'    => 'Japanese',
            'ko'    => 'Korean',
            'vi'    => 'Vietnamese',
            'tl'    => 'Tagalog',
            'id'    => 'Indonesian',
            'sw'    => 'Swahili',
            'am'    => 'Amharic',
            'ht'    => 'Haitian Creole',
        ];
    }

    /**
     * A language tag fit to be used as an identifier, or null.
     *
     * Lowercased whole, which matters more than it looks: the tag is the key a
     * caption is stored under, so "EN" and "en" arriving on different days
     * would otherwise be two entries for one language and the player would
     * offer both.
     *
     * Validated strictly rather than escaped, because it goes into a URL path
     * at the provider. A tag that cannot be a path segment is refused here
     * instead of being encoded into something the provider stores and we can
     * then never address again.
     */
    public static function language(string $raw): ?string
    {
        $tag = strtolower(trim($raw));

        if ($tag === '' || strlen($tag) > self::MAX_LANGUAGE_LENGTH) {
            return null;
        }

        // BCP 47's shape, narrowed: a 2-3 letter primary subtag and any number
        // of alphanumeric subtags after it. Wide enough for "pt-br" and
        // "zh-hant", closed to everything that is not a language.
        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $tag) === 1 ? $tag : null;
    }

    /**
     * What the player's caption menu will show.
     *
     * Falls back to the language's name, and then to the tag itself, because a
     * caption track with a blank label is one nobody can choose — the menu
     * renders an empty row.
     */
    public static function label(string $raw, string $language): string
    {
        // Control characters and newlines out. This ends up in JSON so it
        // cannot break anything, but a label with a line break in it is not a
        // label, and the menu it lands in has one line to give.
        $label = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($raw)) ?? '';
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));

        if ($label === '') {
            $label = self::languages()[$language] ?? $language;
        }

        return mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }

    /**
     * A subtitle file as WebVTT, or null if it is not one.
     *
     * SubRip and WebVTT both, detected rather than declared, for the reason the
     * transcript importer gives: the person uploading did not write the file
     * and asking them which format it is asks them to get it wrong.
     *
     * The conversion is line-by-line and touches only timing lines. Cue
     * identifiers, cue settings, positioning, NOTE blocks and inline styling
     * all survive, which is the whole difference between this and generating a
     * file from stored cues.
     */
    public static function toVtt(string $raw): ?string
    {
        $text = self::normalize($raw);

        if ($text === '') {
            return null;
        }

        $lines = explode("\n", $text);
        $timings = 0;

        foreach ($lines as $index => $line) {
            $rewritten = self::rewriteTiming($line);

            if ($rewritten !== null) {
                $lines[$index] = $rewritten;
                $timings++;
            }
        }

        /*
         * No timing line means no caption, whatever else the file contains. A
         * document with a WEBVTT header and nothing under it is a file the
         * provider would accept and the player would show as an empty track —
         * which reads to a viewer as captions being broken rather than absent.
         */
        if ($timings === 0) {
            return null;
        }

        $body = implode("\n", $lines);

        // The header is mandatory in WebVTT and absent from every SRT. Added
        // only when missing, so a file that already has one — possibly with a
        // title after it, which is legal — keeps what it had.
        if (!self::hasHeader($body)) {
            $body = "WEBVTT\n\n" . $body;
        }

        return $body . "\n";
    }

    /**
     * Captions built from a video's stored transcript.
     *
     * Here rather than inline in the admin handler so the conversion can be
     * tested — the wiring is the part that goes wrong, by handing the provider
     * the raw thing instead of the prepared one, and a private method on a
     * controller is exactly where nothing would notice.
     *
     * The cost is real and is stated on the screen that offers this: cues are
     * stored at second precision, because a transcript panel seeks to the
     * second, so captions made this way can sit up to a second early. It is
     * offered anyway because the alternative for somebody with a transcript and
     * no caption file is no captions at all.
     *
     * @param list<array{start: int, end: int, text: string}> $cues
     */
    public static function fromTranscriptCues(array $cues): ?string
    {
        return $cues === [] ? null : self::toVtt(TranscriptParser::toVtt($cues));
    }

    /**
     * How many cues a prepared file holds.
     *
     * Reported back after an upload for the same reason the transcript importer
     * reports its count: a file that yielded four cues out of an expected four
     * hundred is broken, and the number is the only way anyone finds out. The
     * captions themselves live at the provider, where nothing here can look at
     * them again.
     */
    public static function cueCount(string $vtt): int
    {
        $count = 0;

        foreach (explode("\n", $vtt) as $line) {
            if (self::rewriteTiming($line) !== null) {
                $count++;
            }
        }

        return $count;
    }

    // ------------------------------------------------------------- internals

    private static function normalize(string $raw): string
    {
        // A BOM survives every copy-paste and makes the first line unparseable,
        // which presents as the first caption never appearing.
        $raw = preg_replace('/^\x{FEFF}/u', '', $raw) ?? $raw;

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Invalid UTF-8 from a Windows-1252 export would otherwise reach the
        // provider, which stores it and renders it as mojibake over the video.
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return trim($raw);
    }

    private static function hasHeader(string $body): bool
    {
        return preg_match('/^WEBVTT(\s|$)/', $body) === 1;
    }

    /**
     * A timing line rewritten as WebVTT, or null if the line is not one.
     *
     * Doubles as the detector, so there is exactly one definition of what
     * counts as a timing line rather than two that can drift apart.
     */
    private static function rewriteTiming(string $line): ?string
    {
        $stamp = '(?:\d{1,3}:)?\d{1,2}:\d{1,2}(?:[.,]\d{1,3})?';

        if (preg_match('/^(\s*)(' . $stamp . ')\s*-->\s*(' . $stamp . ')(.*)$/', $line, $m) !== 1) {
            return null;
        }

        // The trailing group is cue settings — align, position, line, size.
        // Kept verbatim: they are legal WebVTT and they are the difference
        // between a caption in the corner and one over somebody's face.
        return $m[1] . self::stamp($m[2]) . ' --> ' . self::stamp($m[3]) . $m[4];
    }

    /**
     * One timestamp in WebVTT's form.
     *
     * The milliseconds are the reason this class exists, so they are carried
     * through rather than rounded. Two things are fixed: SubRip's comma, which
     * WebVTT rejects outright, and a missing or short fraction, which some
     * tools emit as ".5" and WebVTT requires to be exactly three digits.
     */
    private static function stamp(string $raw): string
    {
        $stamp = str_replace(',', '.', trim($raw));

        if (!str_contains($stamp, '.')) {
            return $stamp . '.000';
        }

        [$time, $fraction] = explode('.', $stamp, 2);

        // Padded on the RIGHT: ".5" is five hundred milliseconds, not five.
        return $time . '.' . substr(str_pad($fraction, 3, '0', STR_PAD_RIGHT), 0, 3);
    }
}
