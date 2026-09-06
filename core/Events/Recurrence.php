<?php

declare(strict_types=1);

namespace Portal\Events;

use DateTimeImmutable;
use Portal\Http\HttpException;

/**
 * A repeating rule, and the dates it means.
 *
 * Pure: strings in, wall-clock strings out. No database, no zone conversion —
 * the dates this produces are wall clocks, because that is what an event row
 * stores, and turning one into an instant is WallClock's job at the moment
 * somebody actually needs one.
 *
 * # A REAL SUBSET, AND IT REFUSES THE REST AT PARSE TIME
 *
 * FREQ, INTERVAL, BYDAY (including 1SU and -1SA), BYMONTHDAY, COUNT, UNTIL.
 * Anything else — BYSETPOS, BYWEEKNO, BYYEARDAY, WKST, BYHOUR — is refused when
 * the rule is saved, naming the part that was not understood.
 *
 * Refusing at parse time rather than ignoring is the whole point. A rule
 * carrying BYSETPOS that is silently dropped produces a series that looks
 * plausible and is wrong on a schedule nobody checks against the rule they
 * typed — and the dates it puts out are meetings people either attend or miss.
 * An error when they press save is a person fixing a rule; a quietly ignored
 * part is a year of wrong Sundays.
 *
 * BYDAY and BYMONTHDAY are refused with YEARLY rather than half-implemented,
 * for the same reason: "the second Tuesday of the month, yearly" is a question
 * this does not answer, and answering it approximately would be worse than
 * saying so.
 */
final class Recurrence
{
    public const DAILY = 'DAILY';
    public const WEEKLY = 'WEEKLY';
    public const MONTHLY = 'MONTHLY';
    public const YEARLY = 'YEARLY';

    /** @var list<string> */
    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY, self::YEARLY];

    /** The parts this understands. Everything else is refused by name. */
    private const KNOWN = ['FREQ', 'INTERVAL', 'BYDAY', 'BYMONTHDAY', 'COUNT', 'UNTIL'];

    /** RFC 5545 two-letter days, in the order PHP's `w` uses. */
    private const DAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    /**
     * A ceiling on what one rule may produce in one pass.
     *
     * Not a policy about how long a series may run — that is the horizon — but
     * a guard against a rule that would spin: DAILY with an INTERVAL of 1 and
     * no end, asked for a century, is thirty-six thousand rows nobody wanted.
     */
    public const MAX_DATES = 500;

    /**
     * @param list<string> $byDay      "SU", or "1SU", or "-1SA"
     * @param list<int>    $byMonthDay 1..31, or -1..-31 counting from the end
     */
    private function __construct(
        public readonly string $freq,
        public readonly int $interval,
        public readonly array $byDay,
        public readonly array $byMonthDay,
        public readonly ?int $count,
        public readonly ?string $until,
    ) {
    }

    /**
     * Read a rule, or refuse it.
     *
     * @throws HttpException naming the part that was not understood
     */
    public static function parse(string $rrule): self
    {
        $rrule = trim($rrule);

        // "RRULE:FREQ=..." is how it arrives from a calendar file; the prefix
        // is optional here because a form field will not have it.
        if (stripos($rrule, 'RRULE:') === 0) {
            $rrule = substr($rrule, 6);
        }

        if ($rrule === '') {
            throw HttpException::badRequest('A repeat rule is needed.');
        }

        $parts = [];

        foreach (explode(';', $rrule) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            if (!str_contains($chunk, '=')) {
                throw HttpException::badRequest(sprintf('“%s” is not a part of a repeat rule.', $chunk));
            }

            [$name, $value] = explode('=', $chunk, 2);
            $name = strtoupper(trim($name));

            if (!in_array($name, self::KNOWN, true)) {
                throw HttpException::badRequest(sprintf(
                    'This site does not understand %s in a repeat rule. It understands %s.',
                    $name,
                    implode(', ', self::KNOWN)
                ));
            }

            $parts[$name] = trim($value);
        }

        $freq = strtoupper($parts['FREQ'] ?? '');

        if (!in_array($freq, self::FREQUENCIES, true)) {
            throw HttpException::badRequest(
                'A repeat rule needs FREQ, and it must be one of ' . implode(', ', self::FREQUENCIES) . '.'
            );
        }

        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;

        if ($interval < 1 || $interval > 366) {
            throw HttpException::badRequest('INTERVAL has to be between 1 and 366.');
        }

        $byDay = self::parseByDay($parts['BYDAY'] ?? '', $freq);
        $byMonthDay = self::parseByMonthDay($parts['BYMONTHDAY'] ?? '', $freq);

        $count = null;
        if (isset($parts['COUNT'])) {
            $count = (int) $parts['COUNT'];

            if ($count < 1 || $count > self::MAX_DATES) {
                throw HttpException::badRequest(
                    'COUNT has to be between 1 and ' . self::MAX_DATES . '.'
                );
            }
        }

        $until = null;
        if (isset($parts['UNTIL']) && $parts['UNTIL'] !== '') {
            $until = self::parseUntil($parts['UNTIL']);
        }

        if ($count !== null && $until !== null) {
            // RFC 5545 says the same. Both is not a stricter rule, it is two
            // rules that can disagree, and nobody reading the series later
            // could say which one ended it.
            throw HttpException::badRequest('A rule can have COUNT or UNTIL, not both.');
        }

        return new self($freq, $interval, $byDay, $byMonthDay, $count, $until);
    }

    /** The rule as it would be written in a calendar file. */
    public function __toString(): string
    {
        $parts = ['FREQ=' . $this->freq];

        if ($this->interval !== 1) {
            $parts[] = 'INTERVAL=' . $this->interval;
        }

        if ($this->byDay !== []) {
            $parts[] = 'BYDAY=' . implode(',', $this->byDay);
        }

        if ($this->byMonthDay !== []) {
            $parts[] = 'BYMONTHDAY=' . implode(',', $this->byMonthDay);
        }

        if ($this->count !== null) {
            $parts[] = 'COUNT=' . $this->count;
        }

        if ($this->until !== null) {
            $parts[] = 'UNTIL=' . str_replace(['-', ' ', ':'], '', $this->until) . 'Z';
        }

        return implode(';', $parts);
    }

    /**
     * The wall clocks this rule produces, starting from one.
     *
     * The time of day never changes: it comes from the start and is carried on
     * to every date. A rule cannot move an event to a different hour, which is
     * both what people expect and what stops a DST conversion feeding back into
     * the stored value.
     *
     * @param string $startWall "2026-01-04 10:30:00"
     * @param string $horizon   the last date worth producing, as "Y-m-d"
     * @return list<string> wall clocks, ascending, the first being the start
     */
    public function dates(string $startWall, string $horizon, int $max = self::MAX_DATES): array
    {
        $start = self::atMidnight($startWall);

        if ($start === null) {
            return [];
        }

        $time = date('H:i:s', (int) strtotime($startWall));
        $limit = self::atMidnight($horizon . ' 00:00:00');
        $max = max(1, min($max, self::MAX_DATES));

        $out = [];
        $seen = [];

        foreach ($this->candidates($start, $limit, $max) as $day) {
            $stamp = $day->format('Y-m-d');

            if (isset($seen[$stamp])) {
                continue;
            }

            $seen[$stamp] = true;
            $out[] = $stamp . ' ' . $time;

            if (count($out) >= $max) {
                break;
            }

            if ($this->count !== null && count($out) >= $this->count) {
                break;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ expansion

    /**
     * Every day the rule names, ascending, bounded by the horizon.
     *
     * A generator so the caller's COUNT and max can stop it without this having
     * to know about either — and so an unbounded MONTHLY rule does not build a
     * century of dates before the first one is looked at.
     *
     * @return iterable<DateTimeImmutable>
     */
    private function candidates(DateTimeImmutable $start, ?DateTimeImmutable $limit, int $max): iterable
    {
        $until = $this->until === null ? null : self::atMidnight($this->until);
        $stop = self::earliest($limit, $until);

        /*
         * A hard ceiling on periods examined, separate from the ceiling on
         * dates produced. A MONTHLY BYMONTHDAY=31 rule yields nothing in seven
         * months of the year, so "periods looked at" and "dates found" are
         * different numbers and only bounding the second would let the first
         * run away.
         */
        $periods = 0;
        $maxPeriods = self::MAX_DATES * 4;

        $cursor = $start;

        while ($periods++ < $maxPeriods) {
            foreach ($this->daysIn($cursor, $start) as $day) {
                if ($day < $start) {
                    continue;
                }

                if ($stop !== null && $day > $stop) {
                    return;
                }

                yield $day;
            }

            if ($stop !== null && $this->periodStart($cursor) > $stop) {
                return;
            }

            $cursor = $this->advance($cursor);
        }
    }

    /**
     * The days this rule names within one period.
     *
     * @return list<DateTimeImmutable>
     */
    private function daysIn(DateTimeImmutable $cursor, DateTimeImmutable $start): array
    {
        return match ($this->freq) {
            self::DAILY => [$cursor],

            self::WEEKLY => $this->weekDays($cursor, $start),

            self::MONTHLY => $this->monthDays($cursor, $start),

            // YEARLY repeats the start's own month and day. BYDAY and
            // BYMONTHDAY are refused with it at parse time rather than
            // approximated.
            default => [$cursor],
        };
    }

    /** @return list<DateTimeImmutable> */
    private function weekDays(DateTimeImmutable $cursor, DateTimeImmutable $start): array
    {
        $wanted = $this->byDay === [] ? [self::DAYS[(int) $start->format('w')]] : $this->byDay;

        // The Monday of the cursor's week. RFC 5545's default WKST is MO, and
        // WKST is refused at parse time, so there is one answer here.
        $monday = $cursor->modify('monday this week');
        $days = [];

        foreach ($wanted as $code) {
            $index = array_search($code, self::DAYS, true);

            if ($index === false) {
                continue;
            }

            // Monday is 1 in the DAYS ordering above; Sunday is 0 and belongs
            // to the END of this week rather than the start of it.
            $offset = $index === 0 ? 6 : $index - 1;
            $days[] = $monday->modify('+' . $offset . ' days');
        }

        sort($days);

        return $days;
    }

    /** @return list<DateTimeImmutable> */
    private function monthDays(DateTimeImmutable $cursor, DateTimeImmutable $start): array
    {
        $first = $cursor->modify('first day of this month');
        $daysInMonth = (int) $first->format('t');
        $days = [];

        if ($this->byMonthDay !== []) {
            foreach ($this->byMonthDay as $dayNumber) {
                $day = $dayNumber > 0 ? $dayNumber : $daysInMonth + $dayNumber + 1;

                /*
                 * A month without a 31st simply has no date, which is RFC
                 * behaviour and the right one: "the 31st, monthly" means seven
                 * meetings a year, not five meetings and two on the 1st of the
                 * following month.
                 */
                if ($day >= 1 && $day <= $daysInMonth) {
                    $days[] = $first->modify('+' . ($day - 1) . ' days');
                }
            }
        } elseif ($this->byDay !== []) {
            foreach ($this->byDay as $code) {
                $day = $this->nthWeekdayOfMonth($first, $code, $daysInMonth);

                if ($day !== null) {
                    $days[] = $day;
                }
            }
        } else {
            // No BYDAY and no BYMONTHDAY: the same day of the month as the
            // start, skipping months that do not have it.
            $dayNumber = (int) $start->format('j');

            if ($dayNumber <= $daysInMonth) {
                $days[] = $first->modify('+' . ($dayNumber - 1) . ' days');
            }
        }

        sort($days);

        return $days;
    }

    /**
     * "1SU" — the first Sunday. "-1SA" — the last Saturday.
     *
     * A bare "SU" inside a MONTHLY rule means every Sunday of the month, which
     * is handled by the caller expanding it; here an ordinal is required.
     */
    private function nthWeekdayOfMonth(
        DateTimeImmutable $firstOfMonth,
        string $code,
        int $daysInMonth
    ): ?DateTimeImmutable {
        if (!preg_match('~^(-?\d)?([A-Z]{2})$~', $code, $m)) {
            return null;
        }

        $ordinal = $m[1] === '' ? 1 : (int) $m[1];
        $index = array_search($m[2], self::DAYS, true);

        if ($index === false || $ordinal === 0) {
            return null;
        }

        $matching = [];

        for ($day = 0; $day < $daysInMonth; $day++) {
            $candidate = $firstOfMonth->modify('+' . $day . ' days');

            if ((int) $candidate->format('w') === $index) {
                $matching[] = $candidate;
            }
        }

        if ($ordinal > 0) {
            return $matching[$ordinal - 1] ?? null;
        }

        return $matching[count($matching) + $ordinal] ?? null;
    }

    private function advance(DateTimeImmutable $cursor): DateTimeImmutable
    {
        return match ($this->freq) {
            self::DAILY => $cursor->modify('+' . $this->interval . ' days'),
            self::WEEKLY => $cursor->modify('+' . $this->interval . ' weeks'),
            /*
             * "first day of" before adding months, because 31 January plus one
             * month is 3 March in PHP — and a monthly rule that skipped
             * February by overflowing into March would be wrong in a way that
             * looks like a missing meeting rather than a bug.
             */
            self::MONTHLY => $cursor->modify('first day of this month')
                ->modify('+' . $this->interval . ' months'),
            default => $cursor->modify('+' . $this->interval . ' years'),
        };
    }

    private function periodStart(DateTimeImmutable $cursor): DateTimeImmutable
    {
        return match ($this->freq) {
            self::WEEKLY => $cursor->modify('monday this week'),
            self::MONTHLY => $cursor->modify('first day of this month'),
            default => $cursor,
        };
    }

    // -------------------------------------------------------------- parsing

    /** @return list<string> */
    private static function parseByDay(string $raw, string $freq): array
    {
        if (trim($raw) === '') {
            return [];
        }

        if ($freq === self::DAILY || $freq === self::YEARLY) {
            throw HttpException::badRequest(
                'BYDAY only means something with FREQ=WEEKLY or FREQ=MONTHLY here.'
            );
        }

        $out = [];

        foreach (explode(',', strtoupper($raw)) as $code) {
            $code = trim($code);

            if (!preg_match('~^(-?\d)?([A-Z]{2})$~', $code, $m) || !in_array($m[2], self::DAYS, true)) {
                throw HttpException::badRequest(sprintf('“%s” is not a day this understands.', $code));
            }

            if ($m[1] !== '' && $freq === self::WEEKLY) {
                // "the second Tuesday, weekly" is not a thing anybody means,
                // and RFC 5545 forbids it too.
                throw HttpException::badRequest(
                    'A number in front of a day — like 1SU — only means something with FREQ=MONTHLY.'
                );
            }

            $out[] = $code;
        }

        return array_values(array_unique($out));
    }

    /** @return list<int> */
    private static function parseByMonthDay(string $raw, string $freq): array
    {
        if (trim($raw) === '') {
            return [];
        }

        if ($freq !== self::MONTHLY) {
            throw HttpException::badRequest('BYMONTHDAY only means something with FREQ=MONTHLY here.');
        }

        $out = [];

        foreach (explode(',', $raw) as $value) {
            $day = (int) trim($value);

            if ($day === 0 || $day < -31 || $day > 31) {
                throw HttpException::badRequest(sprintf('“%s” is not a day of the month.', trim($value)));
            }

            $out[] = $day;
        }

        return array_values(array_unique($out));
    }

    /** UNTIL as stored: a date, kept as a wall clock like everything else. */
    private static function parseUntil(string $raw): string
    {
        $raw = trim($raw);

        /*
         * A calendar file writes 20261231T235959Z. The Z is dropped rather than
         * honoured, deliberately: everything in this class is a wall clock, and
         * treating one bound as UTC while the dates around it are local is the
         * off-by-an-hour that ends a series a day early once a year.
         */
        $normal = preg_replace('~[TZ]~', ' ', $raw) ?? $raw;
        $stamp = strtotime(trim($normal));

        if ($stamp === false) {
            throw HttpException::badRequest(sprintf('“%s” is not a date UNTIL can use.', $raw));
        }

        return date('Y-m-d H:i:s', $stamp);
    }

    private static function atMidnight(string $wall): ?DateTimeImmutable
    {
        $stamp = strtotime(str_replace('T', ' ', trim($wall)));

        if ($stamp === false) {
            return null;
        }

        return new DateTimeImmutable(date('Y-m-d 00:00:00', $stamp));
    }

    private static function earliest(?DateTimeImmutable $a, ?DateTimeImmutable $b): ?DateTimeImmutable
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a < $b ? $a : $b;
    }
}
