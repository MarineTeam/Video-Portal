<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * A banner across the top of the site.
 *
 * The audience field decides who is BOTHERED by a message, not who is allowed
 * to know it. That distinction matters: nothing here is a security boundary,
 * and an announcement is not the place to put something that would be damaging
 * to read. The admin screen says so.
 */
final class Announcement
{
    public const EVERYONE = 'everyone';
    public const MEMBERS  = 'members';
    public const ADMINS   = 'admins';

    public const INFO    = 'info';
    public const SUCCESS = 'success';
    public const WARNING = 'warning';

    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $body,
        public readonly string $level = self::INFO,
        public readonly string $audience = self::EVERYONE,
        public readonly ?string $startsAt = null,
        public readonly ?string $endsAt = null,
        public readonly bool $dismissible = true,
        public readonly bool $isActive = true,
        public readonly int $position = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $nullableString = static fn (string $key): ?string =>
            isset($row[$key]) && $row[$key] !== null && $row[$key] !== '' ? (string) $row[$key] : null;

        return new self(
            id:          (int) $row['id'],
            title:       (string) ($row['title'] ?? ''),
            body:        (string) ($row['body'] ?? ''),
            level:       (string) ($row['level'] ?? self::INFO),
            audience:    (string) ($row['audience'] ?? self::EVERYONE),
            startsAt:    $nullableString('starts_at'),
            endsAt:      $nullableString('ends_at'),
            dismissible: (bool) ($row['dismissible'] ?? true),
            isActive:    (bool) ($row['is_active'] ?? true),
            position:    (int) ($row['position'] ?? 0),
        );
    }

    /** @return array<string, string> */
    public static function audiences(): array
    {
        return [
            self::EVERYONE => 'Everybody',
            self::MEMBERS  => 'Approved accounts only',
            self::ADMINS   => 'People who can open the admin area',
        ];
    }

    /** @return array<string, string> */
    public static function levels(): array
    {
        return [
            self::INFO    => 'Information',
            self::SUCCESS => 'Good news',
            self::WARNING => 'Warning',
        ];
    }

    public static function sanitizeAudience(mixed $raw): string
    {
        $value = is_string($raw) ? trim($raw) : '';

        // Falls back to the narrowest useful answer rather than the widest.
        // Getting this wrong should under-share, not over-share.
        return array_key_exists($value, self::audiences()) ? $value : self::ADMINS;
    }

    public static function sanitizeLevel(mixed $raw): string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return array_key_exists($value, self::levels()) ? $value : self::INFO;
    }

    /**
     * Should this be shown to somebody with these two facts about them?
     *
     * Kept here, pure, so the rule can be tested against every combination
     * rather than inferred from a page.
     */
    public function isFor(bool $isApproved, bool $canSeeAdmin): bool
    {
        return match ($this->audience) {
            self::ADMINS  => $canSeeAdmin,
            // An administrator is an approved account for this purpose, even
            // if their own account was never explicitly approved — otherwise a
            // member-facing notice would be invisible to the person who wrote
            // it, which is how a broken banner goes unnoticed.
            self::MEMBERS => $isApproved || $canSeeAdmin,
            default       => true,
        };
    }
}
