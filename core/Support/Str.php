<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * String helpers. Small, but three of these encode rules that the rest of the
 * app depends on being applied consistently.
 */
final class Str
{
    /**
     * Canonical email form used as the identity key everywhere.
     *
     * Every access comparison in this app — share recipient match, approved
     * viewer lookup, email-addressed permission grants — compares normalized
     * emails. If two call sites normalize differently, someone either gets
     * access they shouldn't or loses access they should have. One function.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function isEmail(string $email): bool
    {
        $email = self::normalizeEmail($email);
        return $email !== ''
            && strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Split a pasted blob of addresses on commas, semicolons, or whitespace.
     * Admins paste from spreadsheets, Outlook, and Slack; all three formats
     * land here.
     *
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public static function parseEmailList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $valid = [];
        $invalid = [];
        foreach ($parts as $part) {
            $email = self::normalizeEmail($part);
            if ($email === '') {
                continue;
            }
            if (self::isEmail($email)) {
                $valid[$email] = true; // dedupe while preserving first-seen order
            } else {
                $invalid[] = $part;
            }
        }

        return [
            'valid'   => array_keys($valid),
            'invalid' => array_values(array_unique($invalid)),
        ];
    }

    /** URL slug. Falls back to a random suffix so an all-punctuation title still gets a usable slug. */
    public static function slug(string $text, int $maxLength = 190): string
    {
        $text = trim($text);

        // Transliterate where the host supports it; otherwise strip.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        if (strlen($text) > $maxLength) {
            $text = rtrim(substr($text, 0, $maxLength), '-');
        }

        return $text !== '' ? $text : 'item-' . bin2hex(random_bytes(4));
    }

    public static function truncate(string $text, int $length, string $suffix = '…'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length - mb_strlen($suffix))) . $suffix;
    }

    /**
     * Human duration from seconds: 1:05, 12:34, 1:02:03.
     */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    /** "in 5 hours" / "in 3 days" / "expired" — used on bundle pages and share tables. */
    public static function relativeTo(\DateTimeImmutable $target, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $delta = $target->getTimestamp() - $now->getTimestamp();

        if ($delta <= 0) {
            return 'expired';
        }
        if ($delta < 3600) {
            $mins = max(1, intdiv($delta, 60));
            return sprintf('in %d minute%s', $mins, $mins === 1 ? '' : 's');
        }
        if ($delta < 86400) {
            $hours = intdiv($delta, 3600);
            return sprintf('in %d hour%s', $hours, $hours === 1 ? '' : 's');
        }
        $days = intdiv($delta, 86400);
        return sprintf('in %d day%s', $days, $days === 1 ? '' : 's');
    }
}
