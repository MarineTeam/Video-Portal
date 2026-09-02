<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * A series: an ordered run of videos meant to be watched in sequence.
 *
 * Distinct from a category, and the distinction is worth stating because the
 * two look similar in a database and behave nothing alike. A category is a
 * PLACE — arbitrarily nested, and a video can sit in several. A series is an
 * ORDER — flat, and a video belongs to at most one, because "episode 3" cannot
 * mean two different things at once.
 *
 * That is why series_id is a column on the video while categories are a join
 * table: the schema enforces the difference rather than relying on anyone
 * remembering it.
 */
final class Series
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?int $categoryId = null,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly int $position = 0,
        public readonly bool $isPublished = true,
        public readonly bool $memberOnly = false,
        public readonly bool $hidden = false,
        public readonly bool $featured = false,
        public readonly int $videoCount = 0,

        /**
         * Locked in order: an episode opens when the one before it has been
         * watched. Off by default and opt-in per series — most series are a
         * collection people dip into, and locking those would be wrong.
         */
        public readonly bool $sequential = false,

        /**
         * Tri-state download rule for every episode.
         *
         * The level videos and categories do not have between them, and the one
         * people actually reach for: a course is the unit somebody wants
         * available offline, and saying it once beats ticking forty videos.
         */
        public readonly string $downloadMode = DownloadPolicy::INHERIT,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $nullableString = static fn (string $key): ?string =>
            isset($row[$key]) && $row[$key] !== null && $row[$key] !== '' ? (string) $row[$key] : null;

        return new self(
            id:          (int) $row['id'],
            slug:        (string) $row['slug'],
            title:       (string) $row['title'],
            categoryId:  isset($row['category_id']) && $row['category_id'] !== null
                ? (int) $row['category_id']
                : null,
            description: $nullableString('description'),
            imageUrl:    $nullableString('image_url'),
            position:    (int) ($row['position'] ?? 0),
            isPublished: (bool) ($row['is_published'] ?? true),
            memberOnly:  (bool) ($row['member_only'] ?? false),
            hidden:      (bool) ($row['hidden'] ?? false),
            featured:    (bool) ($row['featured'] ?? false),
            // Only present when the query asked for it.
            videoCount:  (int) ($row['video_count'] ?? 0),
            sequential:  (bool) ($row['sequential'] ?? false),
            downloadMode: (string) ($row['download_mode'] ?? DownloadPolicy::INHERIT),
        );
    }

    public function url(): string
    {
        return '/series/' . $this->slug;
    }

    /** Visible to someone with no account. */
    public function isPublic(): bool
    {
        return $this->isPublished && !$this->memberOnly && !$this->hidden;
    }
}
