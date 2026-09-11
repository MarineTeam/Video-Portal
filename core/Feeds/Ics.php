<?php

declare(strict_types=1);

namespace Portal\Feeds;

/**
 * Writing iCalendar, RFC 5545.
 *
 * # WHY THIS IS FUSSIER THAN IT LOOKS
 *
 * A calendar application does not reject a malformed feed. It mangles it
 * silently — a truncated title, a URL that will not open, an appointment on the
 * wrong day — and the person holding the phone has no way to tell that anything
 * went wrong. There is no error to see, which is exactly why the four details
 * below are worth this much care.
 *
 * FOLDING IS BY OCTET, NOT BY CHARACTER. RFC 5545 says lines are folded at 75
 * octets, and a description with an em dash or an accented name in it counts
 * more octets than characters. Folding by strlen on a multi-byte string also
 * splits a UTF-8 sequence in half, and the two halves arrive as replacement
 * characters — a name rendered as mojibake on somebody's phone.
 *
 * A COLON IS NOT ESCAPED. Backslash, semicolon and comma are, and a newline
 * becomes \n — but a colon is literal inside a value. Escaping it is the
 * tempting mistake because a colon separates the property from its value, and
 * doing it breaks every URL in the feed, which is most of what a feed is for.
 *
 * AN ALL-DAY DTEND IS THE FOLLOWING DAY. DTEND is exclusive. A one-day event
 * ending on its own date disappears from half the calendars that read it.
 *
 * A DECLINED DATE IS CANCELLED, NOT ABSENT. Omitting it leaves it on the phone
 * of the one person who already synced — the person who said no, who then turns
 * up. That rule lives in the callers; this class provides STATUS.
 */
final class Ics
{
    /** RFC 5545: 75 octets, not characters. */
    private const FOLD_AT = 75;

    /**
     * Escape a TEXT value.
     *
     * Backslash first, or the escapes added afterwards get escaped again.
     *
     * NOT the colon. See the class note: a colon is literal inside a value, and
     * escaping it produces `http\://…`, which no calendar will open.
     */
    public static function text(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value
        );
    }

    /**
     * Fold one content line.
     *
     * Octets, and never inside a UTF-8 sequence. A continuation line begins
     * with a single space, which the reader strips.
     *
     * The continuation's leading space counts toward its own 75, which is why
     * subsequent chunks are one shorter — get that wrong and a long line is
     * still over the limit after folding.
     */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_AT) {
            return $line;
        }

        $out = '';
        $room = self::FOLD_AT;
        $chunk = '';

        foreach (self::characters($line) as $character) {
            // A character that will not fit STARTS the next line rather than
            // being split across two — splitting is what produces mojibake.
            if (strlen($chunk) + strlen($character) > $room) {
                $out .= ($out === '' ? '' : "\r\n ") . $chunk;
                $chunk = '';
                $room = self::FOLD_AT - 1;
            }

            $chunk .= $character;
        }

        return $out . ($out === '' ? '' : "\r\n ") . $chunk;
    }

    /**
     * A string as whole UTF-8 characters.
     *
     * Hand-rolled rather than mb_str_split, which needs mbstring — present on
     * every host this targets, but this is the one function whose failure mode
     * is silent corruption, and a fallback that splits bytes would be worse
     * than no feed at all.
     *
     * @return list<string>
     */
    private static function characters(string $value): array
    {
        $out = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($out === false) {
            /*
             * Not valid UTF-8. Rather than split bytes and emit mojibake, the
             * invalid sequences are replaced first — a visible question mark
             * beats a corrupted name.
             */
            $clean = (string) preg_replace('/[\x80-\xFF]/', '?', $value);

            return str_split($clean) ?: [];
        }

        return $out;
    }

    /** One property line, escaped and folded. */
    public static function line(string $name, string $value, bool $escape = true): string
    {
        return self::fold($name . ':' . ($escape ? self::text($value) : $value));
    }

    /**
     * A moment, as UTC.
     *
     * Stored times in this application are WALL CLOCK plus a zone — see the
     * events work — so the zone is what turns one into an instant. A feed in
     * UTC is the form every calendar reads without needing a VTIMEZONE block
     * this would otherwise have to emit correctly.
     */
    public static function stamp(int $instant): string
    {
        return gmdate('Ymd\THis\Z', $instant);
    }

    /** A date, for an all-day event. */
    public static function date(string $day): string
    {
        return date('Ymd', (int) strtotime($day));
    }

    /**
     * The day AFTER the last day of an all-day event.
     *
     * DTEND is EXCLUSIVE. A one-day event whose DTEND is its own date has zero
     * length and vanishes from half the calendars that read it — which is the
     * kind of bug reported as "it did not appear" months later.
     */
    public static function allDayEnd(string $lastDay): string
    {
        return date('Ymd', (int) strtotime($lastDay . ' +1 day'));
    }

    /**
     * Wrap components into a calendar.
     *
     * @param list<string> $components already-built VEVENT blocks
     */
    public static function calendar(string $name, array $components): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            // A PRODID is required. This one names the application rather than
            // the site, because it identifies the software that wrote the file.
            'PRODID:-//Video Portal//Church Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            self::line('X-WR-CALNAME', $name),
        ];

        foreach ($components as $component) {
            $lines[] = $component;
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF throughout, which RFC 5545 requires and several readers enforce.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * One event.
     *
     * @param array{
     *     uid: string, summary: string, stamp: int,
     *     start?: int, end?: int, allDayStart?: string, allDayEnd?: string,
     *     description?: string, location?: string, url?: string, status?: string
     * } $event
     */
    public static function event(array $event): string
    {
        $lines = ['BEGIN:VEVENT'];

        $lines[] = self::line('UID', (string) $event['uid']);
        $lines[] = self::line('DTSTAMP', self::stamp((int) $event['stamp']), false);

        if (isset($event['allDayStart'])) {
            $lines[] = self::line('DTSTART;VALUE=DATE', self::date((string) $event['allDayStart']), false);
            $lines[] = self::line(
                'DTEND;VALUE=DATE',
                self::allDayEnd((string) ($event['allDayEnd'] ?? $event['allDayStart'])),
                false
            );
        } else {
            $lines[] = self::line('DTSTART', self::stamp((int) $event['start']), false);

            if (isset($event['end'])) {
                $lines[] = self::line('DTEND', self::stamp((int) $event['end']), false);
            }
        }

        $lines[] = self::line('SUMMARY', (string) $event['summary']);

        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $property) {
            if (trim((string) ($event[$key] ?? '')) !== '') {
                $lines[] = self::line($property, (string) $event[$key]);
            }
        }

        if (trim((string) ($event['url'] ?? '')) !== '') {
            // Not escaped: the colon in https:// must survive, and a URL
            // contains nothing else RFC 5545 wants escaped.
            $lines[] = self::line('URL', (string) $event['url'], false);
        }

        if (isset($event['status'])) {
            $lines[] = self::line('STATUS', (string) $event['status'], false);
        }

        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }

    /** A date somebody said no to. Cancelled rather than absent — see the class. */
    public const CANCELLED = 'CANCELLED';
    public const CONFIRMED = 'CONFIRMED';
}
