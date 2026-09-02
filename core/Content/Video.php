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

    /**
     * Every value the column accepts, for whitelisting input against.
     *
     * Listed here rather than rebuilt at each call site, so that adding a
     * status means touching one place — and so a filter cannot silently accept
     * a value the ENUM would reject.
     */
    public const STATUSES = [self::STATUS_PROCESSING, self::STATUS_READY, self::STATUS_FAILED];

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
        public readonly ?string $unpublishAt = null,
        public readonly bool $premiere = false,
        public readonly ?string $recordedAt = null,
        public readonly ?string $providerCreatedAt = null,
        /**
         * What the provider last said about this video's downloadable MP4.
         *
         * A cache of two provider-owned facts, so deciding whether a video can
         * be downloaded costs no outbound call. Read them only when
         * $mp4CheckedAt is not null — before anything has asked, `false` and
         * `[]` are the column defaults rather than an answer, and reading them
         * as one tells every site that upgrades that none of its videos has a
         * file. `Mp4Locator` is where that rule lives; nothing else should be
         * touching these three directly.
         */
        public readonly bool $hasMp4 = false,
        /** @var list<int> Rendition heights, ascending. */
        public readonly array $mp4Heights = [],
        public readonly ?string $mp4CheckedAt = null,
    ) {
    }

    /** Has the provider ever been asked what renditions this video has? */
    public function mp4IsKnown(): bool
    {
        return $this->mp4CheckedAt !== null;
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
            unpublishAt:          $nullableString('unpublish_at'),
            premiere:             (bool) ($row['premiere'] ?? false),
            recordedAt:           $nullableString('recorded_at'),
            providerCreatedAt:    $nullableString('provider_created_at'),
            hasMp4:               (bool) ($row['has_mp4'] ?? false),
            mp4Heights:           self::parseHeights($row['mp4_heights'] ?? null),
            mp4CheckedAt:         $nullableString('mp4_checked_at'),
        );
    }

    /**
     * "360,720" back into [360, 720].
     *
     * The inverse of what the repository stores, kept here so the column's
     * format is written down in exactly two places that face each other.
     *
     * @return list<int>
     */
    private static function parseHeights(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $part) {
            $height = (int) trim($part);
            if ($height > 0) {
                $out[$height] = true;
            }
        }

        $heights = array_keys($out);
        sort($heights);

        return $heights;
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
        return $this->isReady()
            && $this->isPublished
            && !$this->hidden
            && !$this->isScheduled()
            && !$this->hasExpired();
    }

    public function isPublic(): bool
    {
        return $this->isVisible() && !$this->memberOnly;
    }

    /**
     * Is its publication date still in the future?
     *
     * Evaluated here and in SQL rather than by a job that flips a flag. A cron
     * job is optional on the hosts this ships to and the built-in pseudo-cron
     * only fires on traffic, so a scheduled video on a quiet site would appear
     * late or not at all. A comparison cannot be late.
     */
    public function isScheduled(?int $now = null): bool
    {
        if ($this->publishedAt === null) {
            return false;
        }

        return $this->timestamp($this->publishedAt) > ($now ?? time());
    }

    /** Has its end date passed? */
    public function hasExpired(?int $now = null): bool
    {
        if ($this->unpublishAt === null) {
            return false;
        }

        return $this->timestamp($this->unpublishAt) <= ($now ?? time());
    }

    /**
     * Should this be announced before it plays?
     *
     * A premiere is listed with its date and still refuses to play, which is
     * the difference between "we have not published this" and "this is coming
     * on Sunday". Only true while the date is still ahead: afterwards it is an
     * ordinary published video, and nothing has to clear the flag.
     */
    public function isPremiering(?int $now = null): bool
    {
        return $this->premiere
            && $this->isPublished
            && !$this->hidden
            && $this->isScheduled($now)
            && !$this->hasExpired($now);
    }

    /**
     * An unparseable date reads as the epoch.
     *
     * Which means the two callers fail in opposite directions: a corrupt
     * publication date is treated as already past, and a corrupt end date as
     * already reached. That asymmetry is deliberate and neither answer is
     * arbitrary — an unreadable end date hides the video, which is recoverable
     * by clearing the field, while an unreadable start date showing it is the
     * same outcome as having no start date at all. Throwing would let one bad
     * row take down a whole listing.
     *
     * The forms validate, so this only ever fires on a value written by hand.
     */
    private function timestamp(string $value): int
    {
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function url(): string
    {
        return '/watch/' . $this->slug;
    }
}
