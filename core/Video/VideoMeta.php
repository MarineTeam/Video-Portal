<?php

declare(strict_types=1);

namespace Portal\Video;

/**
 * Provider-neutral description of one video.
 *
 * Only what the app actually uses. Anything provider-specific stays in the
 * provider, so a second implementation isn't forced to invent a `guid`.
 */
final class VideoMeta
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY      = 'ready';
    public const STATUS_FAILED     = 'failed';

    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $status = self::STATUS_PROCESSING,
        public readonly int $encodeProgress = 0,
        public readonly ?int $duration = null,
        public readonly ?string $thumbnailFile = null,
        public readonly ?string $collectionId = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly int $views = 0,
        /**
         * Whether the provider generated a downloadable MP4 at all.
         *
         * bunny.net only makes one when MP4 Fallback is switched on for the
         * library, and it does not backfill: a video uploaded before that
         * setting changed never gets one. Carried so a caller can say which of
         * those two it is rather than answering "no file".
         *
         * NULL is a third answer and it is load-bearing: the provider's payload
         * did not mention the field, so nothing is known. False means the
         * provider was asked and said no.
         *
         * The two are one `false` apart and lead to opposite actions — one is a
         * dashboard setting to change, the other is a reason to go and ask.
         * They are kept apart because the alternative is what this codebase has
         * already got wrong twice: rounding "could not tell" off to a verdict
         * and then acting on it. Here the cost would land in the cache, where a
         * wrong `false` is written to every row and then repeated forever
         * without anything ever asking again.
         */
        public readonly ?bool $hasMp4Fallback = null,
        /**
         * The rendition heights that actually exist, largest last.
         *
         * Read rather than assumed. Building `play_720p.mp4` from a configured
         * default produces a URL that 404s on any library capped lower, and the
         * 404 is indistinguishable from a rejected token or a missing video.
         *
         * @var list<int>
         */
        public readonly array $resolutions = [],
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
