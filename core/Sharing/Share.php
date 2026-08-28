<?php

declare(strict_types=1);

namespace Portal\Sharing;

use DateTimeImmutable;
use DateTimeZone;
use Portal\Support\Str;

/**
 * One private link to one video for one recipient.
 *
 * Three rules govern this object, all carried over from the apps this replaces
 * and all learned the hard way:
 *
 *  1. Expiry is a COMPARISON, never a deletion. Rows outlive their expiry, so
 *     Extend and Restore still work on a lapsed link. A cron job removes them
 *     long afterwards, deliberately.
 *  2. Revocation is a soft, idempotent flag. Permanent deletion is a separate,
 *     explicit action, so an accidental revoke is always recoverable.
 *  3. Expired and revoked are indistinguishable to a recipient. Both show the
 *     same words, because telling someone their link was revoked rather than
 *     expired leaks a decision that is none of their business.
 */
final class Share
{
    public const MODE_ACCOUNT = 'account';
    public const MODE_GATE    = 'gate';

    /** Chosen at creation, capped below. */
    public const DEFAULT_HOURS = 72;
    public const MAX_HOURS     = 720;

    /**
     * How long a row survives past its expiry.
     *
     * Sixty days, so a link that lapsed weeks ago can still be extended or
     * restored. Without a grace period, "extend this expired link" would be
     * impossible to implement — the row would already be gone.
     */
    public const GRACE_DAYS = 60;

    public function __construct(
        public readonly string $id,
        public readonly int $videoId,
        public readonly string $videoTitle,
        public readonly string $recipientEmail,
        public readonly string $accessMode,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $revokedAt = null,
        public readonly ?DateTimeImmutable $previousExpiresAt = null,
        public readonly string $watermarkMode = 'default',
        public readonly ?string $bundleId = null,
        public readonly bool $viaPrivateList = false,
        public readonly ?DateTimeImmutable $emailedAt = null,
        public readonly ?string $emailError = null,
        public readonly int $viewCount = 0,
        public readonly ?DateTimeImmutable $firstViewedAt = null,
        public readonly ?DateTimeImmutable $lastViewedAt = null,
        public readonly int $playCount = 0,
        public readonly int $furthestPercent = 0,
        public readonly ?DateTimeImmutable $completedAt = null,
        public readonly ?string $createdBy = null,

        /**
         * Whether a passphrase is set — NOT the hash.
         *
         * The model is what every listing, view and JSON response is built
         * from, so carrying the hash here is how it eventually reaches a page.
         * The resolver reads the column directly, which is the one place that
         * needs it, and nothing else can leak what it never holds.
         */
        public readonly bool $passwordProtected = false,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $date = static function (string $key) use ($row): ?DateTimeImmutable {
            if (!isset($row[$key]) || $row[$key] === null || $row[$key] === '') {
                return null;
            }
            try {
                return new DateTimeImmutable((string) $row[$key], new DateTimeZone('UTC'));
            } catch (\Throwable) {
                return null;
            }
        };

        return new self(
            id:                (string) $row['id'],
            videoId:           (int) $row['video_id'],
            videoTitle:        (string) $row['video_title'],
            recipientEmail:    Str::normalizeEmail((string) $row['recipient_email']),
            accessMode:        (string) ($row['access_mode'] ?? self::MODE_ACCOUNT),
            createdAt:         $date('created_at') ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            expiresAt:         $date('expires_at') ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            revokedAt:         $date('revoked_at'),
            previousExpiresAt: $date('previous_expires_at'),
            watermarkMode:     (string) ($row['watermark_mode'] ?? 'default'),
            bundleId:          isset($row['bundle_id']) && $row['bundle_id'] !== null ? (string) $row['bundle_id'] : null,
            viaPrivateList:    (bool) ($row['via_private_list'] ?? false),
            emailedAt:         $date('emailed_at'),
            emailError:        isset($row['email_error']) && $row['email_error'] !== null ? (string) $row['email_error'] : null,
            viewCount:         (int) ($row['view_count'] ?? 0),
            firstViewedAt:     $date('first_viewed_at'),
            lastViewedAt:      $date('last_viewed_at'),
            playCount:         (int) ($row['play_count'] ?? 0),
            furthestPercent:   (int) ($row['furthest_percent'] ?? 0),
            completedAt:       $date('completed_at'),
            createdBy:         isset($row['created_by']) && $row['created_by'] !== null ? (string) $row['created_by'] : null,
            /*
             * Reduced to a boolean the moment it enters the model. Whatever
             * SELECT produced this row, the hash stops here.
             */
            passwordProtected: isset($row['password_hash']) && (string) $row['password_hash'] !== '',
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function hasExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $this->expiresAt <= $now;
    }

    /**
     * Can this link be used right now?
     *
     * The single question every recipient-facing path asks. Note it is NOT
     * "does the row exist" — a row can exist for two months past its usefulness
     * so that Extend and Restore have something to work with.
     */
    public function isLive(?DateTimeImmutable $now = null): bool
    {
        return !$this->isRevoked() && !$this->hasExpired($now);
    }

    /**
     * Is the row old enough to delete?
     *
     * The rule as PHP. The rule that actually runs is the WHERE clause in
     * ShareRepository::purgeExpired(), because deleting a year of rows one
     * object at a time is not a thing to do on a shared host — so this exists
     * to be the readable statement of it, and a test asserts the two agree.
     *
     * They did not always. The scheduled job used to carry a third version
     * that only removed revoked rows, so a link that simply lapsed was never
     * cleaned up unless somebody pressed the button on the sharing screen.
     * Two encodings of one rule is a maintenance cost; three is a bug waiting
     * for the least-used one to drift.
     *
     * Nothing user-facing may use this: being past the grace period is a
     * storage concern, not an access one.
     */
    public function isPastGrace(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = ($this->revokedAt ?? $this->expiresAt)->modify('+' . self::GRACE_DAYS . ' days');

        return $cutoff <= $now;
    }

    public function requiresAccount(): bool
    {
        return $this->accessMode === self::MODE_ACCOUNT;
    }

    public function url(): string
    {
        return '/s/' . $this->id;
    }

    /**
     * Does this session's email match the intended recipient?
     *
     * Both sides normalized, because the address may have been typed by an
     * admin in one case and issued by an identity provider in another.
     */
    public function isFor(string $email): bool
    {
        return $this->recipientEmail === Str::normalizeEmail($email);
    }

    /**
     * Clamp a requested lifetime.
     *
     * At least an hour — a link that expires on arrival helps nobody — and at
     * most thirty days.
     */
    public static function clampHours(int $hours): int
    {
        return max(1, min(self::MAX_HOURS, $hours));
    }

    /**
     * A new share id.
     *
     * 16 random bytes, base64url. Not sequential: an incrementing id would let
     * anyone who received one link enumerate every share ever created.
     */
    public static function newId(): string
    {
        return \Portal\Support\Crypto::token(16);
    }

    /**
     * Is this a plausible share id?
     *
     * Checked before any lookup, so a malformed id costs a regex rather than a
     * database round trip, and nothing strange reaches the query.
     */
    public static function isValidId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $id) === 1;
    }
}
