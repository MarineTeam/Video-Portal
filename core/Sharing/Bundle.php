<?php

declare(strict_types=1);

namespace Portal\Sharing;

use DateTimeImmutable;
use DateTimeZone;
use Portal\Support\Crypto;
use Portal\Support\Str;

/**
 * One page listing everything currently shared with one person.
 *
 * Once someone has several links, sending them one email per video is
 * unkind — so past the second share they get a single page instead, and every
 * later notification points at it.
 *
 * The load-bearing property is what a bundle does NOT store: no titles, no
 * expiry per item, no live/dead flags. It is a recipient, an expiry, and a list
 * of share ids. Everything else is read from the shares themselves on every
 * render.
 *
 * That is why revoking or extending a share is reflected on the bundle page
 * instantly, with no write to the bundle and no possibility of the two
 * disagreeing. A cached title would eventually show a video that had been
 * revoked, which is precisely the failure a private-sharing feature cannot
 * afford.
 */
final class Bundle
{
    public function __construct(
        public readonly string $id,
        public readonly string $recipientEmail,
        public readonly string $accessMode,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $utc = new DateTimeZone('UTC');

        return new self(
            id:             (string) $row['id'],
            recipientEmail: Str::normalizeEmail((string) $row['recipient_email']),
            accessMode:     (string) ($row['access_mode'] ?? Share::MODE_ACCOUNT),
            createdAt:      new DateTimeImmutable((string) $row['created_at'], $utc),
            expiresAt:      new DateTimeImmutable((string) $row['expires_at'], $utc),
        );
    }

    public function hasExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $this->expiresAt <= $now;
    }

    public function requiresAccount(): bool
    {
        return $this->accessMode === Share::MODE_ACCOUNT;
    }

    public function url(): string
    {
        return '/b/' . $this->id;
    }

    public function isFor(string $email): bool
    {
        return $this->recipientEmail === Str::normalizeEmail($email);
    }

    public static function newId(): string
    {
        return Crypto::token(16);
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{16,64}$/', $id) === 1;
    }
}
