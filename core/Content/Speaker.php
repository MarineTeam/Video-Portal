<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * A speaker: whoever is talking.
 *
 * Deliberately not a user account. The person on screen usually has no login,
 * frequently no email address on file, and sometimes no longer exists — a guest
 * from four years ago still needs a name under their video. Tying the two
 * together would mean creating dormant accounts for people who never asked for
 * one, and would make deleting a user destroy attribution on their content.
 */
final class Speaker
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $bio = null,
        public readonly ?string $imageUrl = null,
        public readonly int $videoCount = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $nullableString = static fn (string $key): ?string =>
            isset($row[$key]) && $row[$key] !== null && $row[$key] !== '' ? (string) $row[$key] : null;

        return new self(
            id:         (int) $row['id'],
            slug:       (string) $row['slug'],
            name:       (string) $row['name'],
            bio:        $nullableString('bio'),
            imageUrl:   $nullableString('image_url'),
            videoCount: (int) ($row['video_count'] ?? 0),
        );
    }

    public function url(): string
    {
        return '/speaker/' . $this->slug;
    }
}
