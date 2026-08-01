<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * A video as this application knows it.
 *
 * The provider (bunny.net) remains the source of truth for the media itself —
 * bytes, encoding state, duration. This row is the source of truth for
 * everything about how the media is organised and presented: title, slug,
 * categories, series, publication state.
 *
 * That split is why `provider_collection_id` is recorded but never consulted
 * once a local category exists. Imported collections seed the taxonomy; after
 * that, local wins.
 */
final class Video
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY      = 'ready';
    public const STATUS_FAILED     = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly string $providerId,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly string $provider = 'bunny',
        public readonly ?string $providerCollectionId = null,
        public readonly ?int $duration = null,
        public readonly ?string $thumbnailFile = null,
        public readonly string $status = self::STATUS_PROCESSING,
        public readonly int $encodeProgress = 0,
        public readonly ?int $speakerId = null,
        public readonly ?int $seriesId = null,
        public readonly int $seriesPosition = 0,
        public readonly int $position = 0,
        public readonly bool $featured = false,
        public readonly bool $pinned = false,
        public readonly bool $isPublished = true,
        public readonly bool $memberOnly = false,
        public readonly bool $hidden = false,
        public readonly string $watermarkMode = 'default',
        public readonly string $thumbnailMode = 'default',
        public readonly ?string $publishedAt = null,
        public readonly ?string $recordedAt = null,
        public readonly ?string $providerCreatedAt = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $nullableString = static fn (string $key): ?string =>
            isset($row[$key]) && $row[$key] !== null ? (string) $row[$key] : null;

        $nullableInt = static fn (string $key): ?int =>
            isset($row[$key]) && $row[$key] !== null ? (int) $row[$key] : null;

        return new self(
            id:                   (int) $row['id'],
            providerId:           (string) $row['provider_id'],
            slug:                 (string) $row['slug'],
            title:                (string) $row['title'],
            description:          $nullableString('description'),
            provider:             (string) ($row['provider'] ?? 'bunny'),
            providerCollectionId: $nullableString('provider_collection_id'),
            duration:             $nullableInt('duration'),
            thumbnailFile:        $nullableString('thumbnail_file'),
            status:               (string) ($row['status'] ?? self::STATUS_PROCESSING),
            encodeProgress:       (int) ($row['encode_progress'] ?? 0),
            speakerId:            $nullableInt('speaker_id'),
            seriesId:             $nullableInt('series_id'),
            seriesPosition:       (int) ($row['series_position'] ?? 0),
            position:             (int) ($row['position'] ?? 0),
            featured:             (bool) ($row['featured'] ?? false),
            pinned:               (bool) ($row['pinned'] ?? false),
            isPublished:          (bool) ($row['is_published'] ?? true),
            memberOnly:           (bool) ($row['member_only'] ?? false),
            hidden:               (bool) ($row['hidden'] ?? false),
            watermarkMode:        (string) ($row['watermark_mode'] ?? 'default'),
            thumbnailMode:        (string) ($row['thumbnail_mode'] ?? 'default'),
            publishedAt:          $nullableString('published_at'),
            recordedAt:           $nullableString('recorded_at'),
            providerCreatedAt:    $nullableString('provider_created_at'),
        );
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Should this appear in a public listing?
     *
     * A video that is still encoding is deliberately excluded: showing it
     * produces a player that fails to start, which reads as a broken site
     * rather than a video that is not ready yet.
     */
    public function isVisible(): bool
    {
        return $this->isReady() && $this->isPublished && !$this->hidden;
    }

    public function isPublic(): bool
    {
        return $this->isVisible() && !$this->memberOnly;
    }

    public function url(): string
    {
        return '/watch/' . $this->slug;
    }
}
