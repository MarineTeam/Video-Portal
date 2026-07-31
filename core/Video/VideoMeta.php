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
