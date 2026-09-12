<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * A moment in a video, as a person writes it.
 *
 * # ONE RULE, IN ONE PLACE — INCLUDING THE BROWSER
 *
 * `?t=` is parsed here and nowhere else. The player is handed the answer as a
 * data attribute rather than reading the query string itself, and the "Share
 * at" field checks what somebody types against PATTERN, rendered into its
 * `pattern` attribute — so the browser runs this exact regular expression rather
 * than a JavaScript copy of it. Two implementations of the same rule eventually
 * disagree about "1:5", and the failure is a shared link that opens at a
 * different moment for the person who received it than the one who made it,
 * which nobody can see from either end.
 *
 * `?t=1:30` is a perfectly good link. A colon is legal unescaped in a query
 * string, and a link somebody can read — and type from a slide — is worth more
 * than one only a program could have produced.
 *
 * # AMBIGUOUS IS REFUSED, NOT GUESSED
 *
 * "1:75" could be a typo for 1:57, or 2:15 written badly, or an hour and fifteen
 * with a digit missing. Guessing produces a link that starts somewhere nobody
 * meant and looks like it worked — the same reason the rota's date parser
 * refuses "5/9/2026" rather than picking a convention.
 */
final class Timestamp
{
    /** The longest a link may ask for: a day, well past any sermon. */
    public const MAX_SECONDS = 86400;

    /**
     * The shape of a moment, as ONE regular expression.
     *
     * Plain seconds, or m:ss, or h:mm:ss — the first part any width (so "75:00"
     * is a fair way to write an hour and a quarter), every later part exactly two
     * digits under 60. No delimiters and no anchors, because both uses anchor it
     * themselves: parse() below, and the HTML `pattern` attribute, which browsers
     * anchor implicitly.
     *
     * Only syntax that means the same thing in PCRE and in a browser's regex
     * engine belongs here — digits, a character class, a non-capturing group —
     * because the whole value of sharing it is that it cannot mean two things.
     */
    public const PATTERN = '\d{1,6}|\d{1,4}:[0-5]\d(?::[0-5]\d)?';

    /**
     * Seconds, or null if this is not a moment.
     *
     * Accepts `90`, `1:30` and `1:02:30`. Surrounding space is forgiven; zero is
     * a real answer (the start); anything else is null.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= self::MAX_SECONDS ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(?:' . self::PATTERN . ')$/', $value) !== 1) {
            return null;
        }

        // Each part multiplies what came before it by sixty, which reads plain
        // seconds, m:ss and h:mm:ss with one loop and no branch per form.
        $seconds = 0;

        foreach (explode(':', $value) as $part) {
            $seconds = ($seconds * 60) + (int) $part;
        }

        return $seconds <= self::MAX_SECONDS ? $seconds : null;
    }

    /**
     * How a moment is written back: `1:30`, or `1:02:30` past the hour.
     *
     * The inverse of parse() for every value it produces, so a link built from a
     * formatted time opens where the formatted time says.
     */
    public static function format(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }
}
