<?php

declare(strict_types=1);

namespace Portal\Video;

/**
 * One page of provider videos, plus enough to know whether to fetch more.
 */
final class VideoPage
{
    /** @param list<VideoMeta> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalItems,
    ) {
    }

    public function totalPages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->totalItems / $this->perPage) : 1;
    }

    public function hasMore(): bool
    {
        return $this->page < $this->totalPages();
    }
}
