<?php

declare(strict_types=1);

namespace Portal\Forms;

/**
 * The ten kinds of question, and what counts as an answer to each.
 *
 * # THE SERVER HAS THE LAST WORD
 *
 * That is the whole reason this is a class and not a `<select>` in a template.
 * The page a response came from proves nothing: it may have been saved,
 * edited, or never rendered by this site at all. So a choice question's answer
 * is checked against the options stored ON THE QUESTION, and a crafted request
 * cannot invent a fourth answer to a three-way question — which matters most
 * for the questions somebody actually acts on, like "which service do you come
 * to" or "are you happy to be contacted".
 *
 * Pure: a type, some options and a raw value in, a verdict out. No database, no
 * request, no session — so the admin preview, the public form and the CSV
 * export all agree by construction rather than by three people remembering.
 */
final class FieldType
{
    public const TEXT      = 'text';
    public const PARAGRAPH = 'paragraph';
    public const EMAIL     = 'email';
    public const PHONE     = 'phone';
    public const NUMBER    = 'number';
    public const DATE      = 'date';
    public const CHOICE    = 'choice';
    public const DROPDOWN  = 'dropdown';
    public const CHECKBOXES = 'checkboxes';
    public const YES_NO    = 'yesno';

    /** How long a single-line answer may be. */
    private const SHORT = 300;

    /** And a paragraph. Generous: this is where somebody tells their story. */
    private const LONG = 5000;

    /**
     * Every type, with the words an administrator picks between.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::TEXT       => 'Short answer',
            self::PARAGRAPH  => 'Long answer',
            self::EMAIL      => 'Email address',
            self::PHONE      => 'Phone number',
            self::NUMBER     => 'A number',
            self::DATE       => 'A date',
            self::CHOICE     => 'One of these (buttons)',
            self::DROPDOWN   => 'One of these (a list)',
            self::CHECKBOXES => 'Any of these',
            self::YES_NO     => 'Yes or no',
        ];
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /** Whether this type is answered by picking from a stored list. */
    public static function hasOptions(string $type): bool
    {
        return in_array($type, [self::CHOICE, self::DROPDOWN, self::CHECKBOXES], true);
    }

    /** Whether more than one may be picked. */
    public static function isMultiple(string $type): bool
    {
        return $type === self::CHECKBOXES;
    }

    /**
     * Check one answer.
     *
     * @param list<string> $options the answers this question actually permits
     * @param mixed $raw whatever arrived in the request
     * @return array{ok: bool, value: ?string, error: ?string}
     */
    public static function check(string $type, array $options, bool $required, mixed $raw): array
    {
        if (!self::exists($type)) {
            // A question whose type is not one of ours cannot be answered
            // safely, so it is refused rather than stored as free text.
            return self::bad('This question cannot be answered.');
        }

        return self::isMultiple($type)
            ? self::checkMany($options, $required, $raw)
            : self::checkOne($type, $options, $required, $raw);
    }

    /**
     * @param list<string> $options
     * @return array{ok: bool, value: ?string, error: ?string}
     */
    private static function checkOne(string $type, array $options, bool $required, mixed $raw): array
    {
        $value = is_scalar($raw) ? trim((string) $raw) : '';

        if ($value === '') {
            return $required
                ? self::bad('This one is needed.')
                : ['ok' => true, 'value' => null, 'error' => null];
        }

        return match ($type) {
            self::PARAGRAPH => self::length($value, self::LONG),

            self::EMAIL => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? self::bad('That does not look like an email address.')
                : self::good(mb_strtolower($value)),

            /*
             * Deliberately loose. Phone numbers are written with spaces,
             * brackets, dots, a leading +, and sometimes a note about when to
             * ring; a strict pattern refuses a real number somebody typed
             * correctly, and the cost of that is a person who cannot send the
             * form and gives up. What is checked is that it is mostly digits.
             */
            self::PHONE => preg_match('/\d/', $value) !== 1 || mb_strlen($value) > 40
                ? self::bad('That does not look like a phone number.')
                : self::good($value),

            self::NUMBER => !is_numeric($value)
                ? self::bad('That needs to be a number.')
                : self::good((string) (0 + $value)),

            self::DATE => self::date($value),

            self::YES_NO => in_array(mb_strtolower($value), ['yes', 'no'], true)
                ? self::good(mb_strtolower($value))
                : self::bad('Answer yes or no.'),

            // THE RULE. Checked against what the question stores, not against
            // what the page offered.
            self::CHOICE, self::DROPDOWN => in_array($value, $options, true)
                ? self::good($value)
                : self::bad('That is not one of the answers to this question.'),

            default => self::length($value, self::SHORT),
        };
    }

    /**
     * @param list<string> $options
     * @return array{ok: bool, value: ?string, error: ?string}
     */
    private static function checkMany(array $options, bool $required, mixed $raw): array
    {
        $picked = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
        $kept = [];

        foreach ($picked as $one) {
            if (!is_scalar($one)) {
                continue;
            }

            $one = trim((string) $one);

            // Same rule as the single-choice types, and the duplicate guard
            // matters too: sending the same box six times must not store it
            // six times and make a count meaningless.
            if (in_array($one, $options, true) && !in_array($one, $kept, true)) {
                $kept[] = $one;
            }
        }

        if ($kept === []) {
            return $required
                ? self::bad('Choose at least one.')
                : ['ok' => true, 'value' => null, 'error' => null];
        }

        /*
         * Anything picked that is NOT an option is dropped rather than refused.
         * A tick box whose option was retired between the page loading and the
         * form being sent is the common case, and refusing the whole response
         * over it loses everything the person wrote.
         */
        return self::good((string) json_encode(array_values($kept)));
    }

    /**
     * @return array{ok: bool, value: ?string, error: ?string}
     */
    private static function date(string $value): array
    {
        $stamp = strtotime($value);

        if ($stamp === false) {
            return self::bad('That does not look like a date.');
        }

        // Stored the one way round, so a CSV export and a listing cannot
        // disagree about what 05/09 meant.
        return self::good(date('Y-m-d', $stamp));
    }

    /**
     * @return array{ok: bool, value: ?string, error: ?string}
     */
    private static function length(string $value, int $limit): array
    {
        return mb_strlen($value) > $limit
            ? self::bad(sprintf('That is longer than the %d characters this allows.', $limit))
            : self::good($value);
    }

    /** @return array{ok: bool, value: ?string, error: ?string} */
    private static function good(string $value): array
    {
        return ['ok' => true, 'value' => $value, 'error' => null];
    }

    /** @return array{ok: bool, value: ?string, error: ?string} */
    private static function bad(string $error): array
    {
        return ['ok' => false, 'value' => null, 'error' => $error];
    }

    /**
     * How an answer reads on a screen or in a spreadsheet.
     *
     * One function, so the responses table, the CSV and any future export
     * cannot disagree about how a multi-choice answer is written down.
     */
    public static function display(string $type, ?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        if (self::isMultiple($type)) {
            $decoded = json_decode($stored, true);

            return is_array($decoded) ? implode(', ', array_map('strval', $decoded)) : $stored;
        }

        return match ($type) {
            self::YES_NO => $stored === 'yes' ? 'Yes' : 'No',
            default      => $stored,
        };
    }

    /**
     * The options an administrator typed, as a list.
     *
     * One per line, because a comma-separated box cannot hold an option with a
     * comma in it — and "Sunday, 9am" is a normal thing to want.
     *
     * @return list<string>
     */
    public static function parseOptions(string $raw): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = mb_substr($line, 0, 190);
            }
        }

        return $out;
    }
}
