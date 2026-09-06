<?php

declare(strict_types=1);

namespace Portal\Schedules;

/**
 * Reading a date out of a spreadsheet cell.
 *
 * # WHY THIS IS NOT strtotime()
 *
 * strtotime() answers "5/9/2026" with a guess, and the guess is American: 9 May
 * rather than 5 September. On a rota that is not a formatting quirk — it puts
 * somebody on a date four months from the one they agreed to, and nobody finds
 * out until the day. It also answers "next tuesday" and "+1 week", which a
 * spreadsheet cell has no business becoming.
 *
 * So: AN AMBIGUOUS DATE IS REFUSED UNLESS THE SOURCE HAS SAID WHICH WAY ROUND
 * IT IS. "13/9" is unambiguous whichever convention you hold, because there is
 * no thirteenth month, and it is read. "5/9" is not, and under `auto` it comes
 * back as a problem naming the row rather than as a date. The person who keeps
 * the sheet knows the answer; this does not, and guessing is the one thing it
 * must not do.
 *
 * # THE YEAR
 *
 * Real rota sheets say "5 Sep" and expect to be understood. Refusing those
 * would mean most sheets never sync, so a missing year is inferred as the
 * NEAREST reading that is not well in the past: a cell written today means the
 * one coming, not the one that has been and gone. Sixty days of slack, because
 * a sheet is often still being read a few weeks after the date it holds.
 */
final class SheetDate
{
    /** Refuse anything that could be read two ways. */
    public const AUTO = 'auto';

    /** The sheet has told us: day first, then month. */
    public const DMY = 'dmy';

    /** Or month first. */
    public const MDY = 'mdy';

    /** How far back a bare "5 Sep" is allowed to mean before it is next year. */
    private const LOOK_BACK_DAYS = 60;

    /**
     * Month names, written out rather than left to strtotime().
     *
     * Locale-independent by construction, the same discipline PersonKey uses
     * for its folding map: a host with a different locale must not read a
     * different date out of the same sheet.
     *
     * @var array<string, int>
     */
    private const MONTHS = [
        'jan' => 1, 'january' => 1,
        'feb' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5,
        'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    /** @var list<string> */
    private const WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'mon', 'tue', 'tues', 'wed', 'weds', 'thu', 'thur', 'thurs', 'fri', 'sat', 'sun',
    ];

    /**
     * A date, or null if the cell cannot be read as one WITHOUT GUESSING.
     *
     * $today exists so the year inference can be tested against a fixed day
     * rather than against whenever the suite happens to run.
     */
    public static function parse(string $raw, string $order = self::AUTO, ?string $today = null): ?string
    {
        $text = self::tidy($raw);

        if ($text === '') {
            return null;
        }

        // Year first is unambiguous everywhere and is what a sheet set to
        // "ISO" or left as a real date value exports.
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $m) === 1) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})(?:[-\/.](\d{2,4}))?$/', $text, $m) === 1) {
            $pair = self::disambiguate((int) $m[1], (int) $m[2], $order);

            if ($pair === null) {
                return null;
            }

            [$day, $month] = $pair;

            return isset($m[3]) && $m[3] !== ''
                ? self::make(self::fullYear((int) $m[3]), $month, $day)
                : self::inferYear($month, $day, $today);
        }

        // "5 Sep", "5 September 2026", "5th Sept"
        if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s+([a-z]+)\.?(?:\s+(\d{2,4}))?$/', $text, $m) === 1) {
            return self::fromWords((int) $m[1], $m[2], $m[3] ?? '', $today);
        }

        // "Sep 5", "September 5, 2026"
        if (preg_match('/^([a-z]+)\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{2,4}))?$/', $text, $m) === 1) {
            return self::fromWords((int) $m[2], $m[1], $m[3] ?? '', $today);
        }

        return null;
    }

    /**
     * Whether a cell is ambiguous rather than merely unreadable.
     *
     * The two deserve different words on the preview screen: "this is not a
     * date" sends somebody to fix a typo, where "this could be either" sends
     * them to the one setting that resolves it.
     */
    public static function isAmbiguous(string $raw): bool
    {
        $text = self::tidy($raw);

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})(?:[-\/.]\d{2,4})?$/', $text, $m) !== 1) {
            return false;
        }

        return (int) $m[1] <= 12 && (int) $m[2] <= 12;
    }

    // --------------------------------------------------------- internals

    private static function tidy(string $raw): string
    {
        $text = strtolower(trim($raw));
        $text = (string) preg_replace('/\s+/', ' ', $text);

        // A leading weekday is decoration — "Sun 5 Sep" and "Sunday, 5 Sep"
        // are how half of all rota sheets are written.
        foreach (self::WEEKDAYS as $day) {
            if (str_starts_with($text, $day . ' ') || str_starts_with($text, $day . ', ')) {
                $text = ltrim(substr($text, strlen($day)), ' ,');
                break;
            }
        }

        return trim($text);
    }

    /**
     * Which of the two numbers is the day.
     *
     * Null means it could be either and nobody has said which — the whole
     * point of this class.
     *
     * @return array{int, int}|null [day, month]
     */
    private static function disambiguate(int $a, int $b, string $order): ?array
    {
        // One of them being over twelve settles it whatever convention the
        // sheet keeps, so a declared order is never needed for these.
        if ($a > 12 && $b <= 12) {
            return [$a, $b];
        }

        if ($b > 12 && $a <= 12) {
            return [$b, $a];
        }

        if ($a > 12 && $b > 12) {
            return null;
        }

        return match ($order) {
            self::DMY => [$a, $b],
            self::MDY => [$b, $a],
            default   => null,
        };
    }

    private static function fromWords(int $day, string $monthWord, string $year, ?string $today): ?string
    {
        $month = self::MONTHS[$monthWord] ?? null;

        if ($month === null) {
            return null;
        }

        return $year === ''
            ? self::inferYear($month, $day, $today)
            : self::make(self::fullYear((int) $year), $month, $day);
    }

    /**
     * The nearest reading that is not well in the past.
     *
     * A rota cell with no year means the one coming round, so last year is
     * only chosen when this year's has not happened yet AND last year's is
     * still recent — which is what a sheet being read a few weeks late looks
     * like.
     */
    private static function inferYear(int $month, int $day, ?string $today): ?string
    {
        $now = $today !== null ? strtotime($today) : time();

        if ($now === false) {
            return null;
        }

        $floor = $now - self::LOOK_BACK_DAYS * 86400;
        $thisYear = (int) date('Y', $now);

        foreach ([$thisYear - 1, $thisYear, $thisYear + 1] as $year) {
            $made = self::make($year, $month, $day);

            if ($made === null) {
                continue;
            }

            $stamp = strtotime($made);

            if ($stamp !== false && $stamp >= $floor) {
                return $made;
            }
        }

        return null;
    }

    /** Two digits are this century; a sheet is not recording 1926. */
    private static function fullYear(int $year): int
    {
        return $year < 100 ? 2000 + $year : $year;
    }

    private static function make(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
