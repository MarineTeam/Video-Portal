<?php

declare(strict_types=1);

namespace Portal\Sharing;

/**
 * The passphrase on a share link.
 *
 * Separate from PasswordPolicy, which governs the credential somebody signs in
 * with. These are different things with different threat models and deserve
 * different rules: an account password protects everything that account can
 * ever do and is typed by its owner, while a share passphrase protects one
 * video for a few days and is typed by somebody who was told it over the phone.
 * Holding it to twelve characters and a common-password blocklist would mean
 * nobody could dictate one, so the feature would go unused.
 *
 * Six characters, which is Marine-team's number and is deliberately modest.
 * The real protection is that the link itself is a 22-character random id, the
 * passphrase only narrows who among the people holding that link may open it,
 * and ten wrong guesses locks the link for fifteen minutes.
 */
final class SharePassword
{
    public const MINIMUM = 6;
    public const MAXIMUM = 200;

    /** Wrong guesses allowed, and the window they are counted in. */
    public const MAX_ATTEMPTS = 10;
    public const LOCKOUT_SECONDS = 900;

    /**
     * Is this usable as a passphrase?
     *
     * Whitespace is not trimmed away before counting, but a passphrase that is
     * ONLY whitespace is refused: it cannot be dictated, cannot be typed
     * reliably, and would look to whoever set it like the field had been left
     * empty.
     */
    public static function isAcceptable(string $passphrase): bool
    {
        if (trim($passphrase) === '') {
            return false;
        }

        $length = mb_strlen($passphrase);

        return $length >= self::MINIMUM && $length <= self::MAXIMUM;
    }

    /**
     * Hash a passphrase for storage, or null for "no passphrase".
     *
     * PASSWORD_DEFAULT rather than a pinned algorithm, so a PHP upgrade
     * improves this without a migration. The column is wide enough for
     * whatever it becomes.
     */
    public static function hash(?string $passphrase): ?string
    {
        if ($passphrase === null || !self::isAcceptable($passphrase)) {
            return null;
        }

        return password_hash($passphrase, PASSWORD_DEFAULT);
    }

    /**
     * Does this passphrase open that link?
     *
     * password_verify() is constant-time with respect to the hash, which is
     * what stops the comparison itself leaking how much of a guess was right.
     *
     * An empty stored hash is NOT a link anybody can open with anything — it
     * is a link with no passphrase, and calling this on one is a caller error
     * that must answer false rather than true. Getting this backwards would
     * turn every unprotected link into one that refuses everybody, or worse,
     * make a protected one open for an empty string.
     */
    public static function matches(?string $storedHash, string $attempt): bool
    {
        if ($storedHash === null || $storedHash === '') {
            return false;
        }

        return password_verify($attempt, $storedHash);
    }

    /**
     * The rate-limit bucket for guesses against one link.
     *
     * Per link rather than per IP. A link is the thing being attacked and the
     * thing that can be locked without collateral: locking an IP would stop a
     * whole office opening their own links because one person mistyped, and
     * locking nothing at all leaves a six-character passphrase to be guessed
     * at network speed.
     *
     * RateLimit hashes the bucket before storing it, so the share id does not
     * sit in a table in the clear.
     */
    public static function bucket(string $shareId): string
    {
        return 'share-passphrase:' . $shareId;
    }
}
