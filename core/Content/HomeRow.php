<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * One row on the homepage.
 *
 * A pointer at content, not a copy of it. A row that names a playlist shows
 * whatever is on that playlist today — so curating the playlist curates the
 * homepage, rather than there being two places to edit that drift apart.
 */
final class HomeRow
{
    public const LATEST   = 'latest';
    public const FEATURED = 'featured';
    public const CATEGORY = 'category';
    public const SERIES   = 'series';
    public const PLAYLIST = 'playlist';
    public const CONTINUE = 'continue';

    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $sourceType,
        public readonly ?int $sourceId,
        public readonly int $maxItems,
        public readonly int $position,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:         (int) $row['id'],
            title:      (string) ($row['title'] ?? ''),
            sourceType: (string) ($row['source_type'] ?? self::LATEST),
            sourceId:   isset($row['source_id']) && $row['source_id'] !== null ? (int) $row['source_id'] : null,
            maxItems:   (int) ($row['max_items'] ?? 12),
            position:   (int) ($row['position'] ?? 0),
            isActive:   (bool) ($row['is_active'] ?? true),
        );
    }

    /** @return array<string, string> the sources, labelled for the admin screen */
    public static function sources(): array
    {
        return [
            self::LATEST   => 'Latest videos',
            self::FEATURED => 'Featured videos',
            self::CATEGORY => 'A category',
            self::SERIES   => 'A series',
            self::PLAYLIST => 'A playlist',
            self::CONTINUE => 'Continue watching',
        ];
    }

    /**
     * A submitted source, or null if it is not one.
     *
     * Refused rather than defaulted to "latest": a row silently pointing
     * somewhere other than where an editor aimed it is worse than one that
     * would not save.
     */
    public static function sanitizeSource(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return array_key_exists($value, self::sources()) ? $value : null;
    }

    /** Do the sources that name a thing actually have one? */
    public static function needsTarget(string $sourceType): bool
    {
        return in_array($sourceType, [self::CATEGORY, self::SERIES, self::PLAYLIST], true);
    }

    /**
     * Is this row the same for everybody?
     *
     * Continue-watching is not, which is the one thing about it that matters
     * to anything caching a page.
     */
    public function isPersonal(): bool
    {
        return $this->sourceType === self::CONTINUE;
    }
}
