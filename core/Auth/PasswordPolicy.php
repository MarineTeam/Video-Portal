<?php

declare(strict_types=1);

namespace Portal\Auth;

/**
 * What counts as an acceptable password.
 *
 * The rule itself is not new — LocalProvider has carried it since Phase 1. What
 * is new is that anything calls it. `validatePassword()` was declared, tested
 * by nobody, and invoked from zero places, so the only password this product
 * has ever had a person choose — the administrator account, set during install
 * — was accepted whatever it was. "1234" would have gone in.
 *
 * That is the fourteenth thing found built and unreachable in this project, and
 * the worst of them, because the account it guards is the break-glass path: on
 * a host with no shell, the local password is the only way back in when the
 * identity provider is misconfigured.
 *
 * Pure and static so it can be enforced at the LOWEST level rather than only at
 * a form. A rule that lives in a controller is a rule the next controller
 * forgets; this one is checked inside UserRepository, where every write path
 * goes through it and no caller can skip it.
 *
 * A length floor plus a blocklist rather than composition rules — no "must
 * contain a symbol". Composition rules push people towards Passw0rd! and are
 * measurably worse than length at the thing they claim to do.
 */
final class PasswordPolicy
{
    /**
     * The minimum a site gets unless it configures otherwise.
     *
     * Twelve rather than eight: the account this most often protects is an
     * administrator on a public host, and eight is inside reach of an offline
     * attack on a leaked hash.
     */
    public const DEFAULT_MINIMUM = 12;

    /**
     * The shortest minimum a site may configure.
     *
     * A site that sets `min_password_length: 4` has not turned the rule off,
     * it has misunderstood it. This is the point below which the answer is no
     * regardless of configuration.
     */
    public const FLOOR = 8;

    /**
     * Passwords common enough that length alone does not save them.
     *
     * Deliberately short. A serious blocklist is a megabyte of leaked
     * credentials and belongs in a plugin with a file behind it; this catches
     * the handful somebody types when they intend to change it later and then
     * does not.
     */
    private const COMMON = [
        'password', 'password1', 'password123', '123456789', '1234567890',
        'qwertyuiop', 'letmein', 'welcome123', 'administrator', 'changeme',
        'passw0rd', 'iloveyou', 'admin1234', 'videoportal',
    ];

    /**
     * Resolve a configured minimum into one that is actually usable.
     */
    public static function minimum(mixed $configured): int
    {
        $length = is_numeric($configured) ? (int) $configured : self::DEFAULT_MINIMUM;

        return max(self::FLOOR, $length);
    }

    /**
     * Everything wrong with this password, in the order worth fixing.
     *
     * A list rather than a boolean, and a list rather than the first problem,
     * because somebody who fixes the length and is then told about the
     * blocklist has been made to guess twice.
     *
     * @return list<string> empty when the password is acceptable
     */
    public static function problems(string $password, ?int $minimum = null): array
    {
        $minimum = self::minimum($minimum ?? self::DEFAULT_MINIMUM);
        $problems = [];

        /*
         * mb_strlen, not strlen. A passphrase in any non-Latin script would
         * otherwise be measured in bytes and counted as far longer than it is —
         * the rule would pass a four-character password written in Japanese
         * while refusing an eleven-character one written in English.
         */
        if (mb_strlen($password) < $minimum) {
            $problems[] = sprintf('Use at least %d characters.', $minimum);
        }

        if (in_array(mb_strtolower(trim($password)), self::COMMON, true)) {
            $problems[] = 'That password is too common — pick something else.';
        }

        // Whitespace at either end is invisible in a password field and
        // survives into the hash, so somebody who cannot sign in tomorrow has
        // no way to see why.
        if ($password !== trim($password)) {
            $problems[] = 'Remove the space at the start or end.';
        }

        return $problems;
    }

    /** Convenience for the many callers that only need yes or no. */
    public static function isAcceptable(string $password, ?int $minimum = null): bool
    {
        return self::problems($password, $minimum) === [];
    }
}
