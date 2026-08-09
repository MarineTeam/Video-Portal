<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * A playlist: somebody's selection, in the order they chose.
 *
 * The third organising idea in this codebase, and worth naming against the
 * other two because all three are "a group of videos" and they are not
 * interchangeable:
 *
 *   category  a PLACE      nested, a video sits in several, permanent
 *   series    an ORDER     flat, a video belongs to at most one, intrinsic
 *   playlist  a SELECTION  flat, a video is in any number, editorial
 *
 * "Episode 3" cannot mean two things, so series is a column on the video.
 * "Also on the Advent playlist" can be true of a video that is episode 3 of
 * something else, so playlist membership is a join table. The schema carries
 * the distinction rather than a convention nobody reads.
 */
final class Playlist
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $imageUrl = null,
        public readonly int $position = 0,
        public readonly bool $isPublished = true,
        public readonly bool $memberOnly = false,
        public readonly bool $hidden = false,
        public readonly bool $featured = false,
        public readonly int $videoCount = 0,
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
            description: $nullableString('description'),
            imageUrl:    $nullableString('image_url'),
            position:    (int) ($row['position'] ?? 0),
            isPublished: (bool) ($row['is_published'] ?? true),
            memberOnly:  (bool) ($row['member_only'] ?? false),
            hidden:      (bool) ($row['hidden'] ?? false),
            featured:    (bool) ($row['featured'] ?? false),
            // Only present when the query asked for it.
            videoCount:  (int) ($row['video_count'] ?? 0),
        );
    }

    public function url(): string
    {
        return '/playlist/' . $this->slug;
    }

    /** Visible to someone with no account. */
    public function isPublic(): bool
    {
        return $this->isPublished && !$this->memberOnly && !$this->hidden;
    }
}
