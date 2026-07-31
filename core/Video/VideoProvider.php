<?php

declare(strict_types=1);

namespace Portal\Video;

use Portal\Providers\Provider;

/**
 * Contract for a video hosting service.
 *
 * bunny.net Stream is the only implementation today. The interface is shaped
 * around what the app genuinely needs rather than around bunny's API surface,
 * so Cloudflare Stream or a self-hosted HLS origin can be added later without
 * touching a single caller:
 *
 *  - URLs are requested with a TTL and minted fresh every time. No caller ever
 *    stores one, because every implementation is expected to sign them.
 *  - Upload is expressed as "give me a ticket the browser can use", not as
 *    "here are some bytes". The file must never pass through the app server;
 *    shared hosting has neither the memory nor the request timeout for it.
 */
interface VideoProvider extends Provider
{
    /**
     * One page of videos from the provider.
     *
     * @param int $page 1-based
     */
    public function listVideos(int $page = 1, int $perPage = 100): VideoPage;

    public function getVideo(string $providerId): ?VideoMeta;

    /**
     * A playable, time-limited embed URL.
     *
     * @param int $ttlSeconds how long the URL stays valid
     */
    public function embedUrl(string $providerId, int $ttlSeconds = 10800): string;

    /**
     * A signed thumbnail URL, or null when the provider isn't configured for
     * thumbnails. Callers must degrade gracefully rather than showing a broken
     * image — the homepage falls back to a title list.
     */
    public function thumbnailUrl(string $providerId, ?string $thumbnailFile, int $ttlSeconds = 21600): ?string;

    /**
     * Create a video record and return everything the browser needs to upload
     * directly to the provider.
     */
    public function createUploadTicket(string $title, ?string $collectionId = null): UploadTicket;

    public function deleteVideo(string $providerId): void;

    /** @param array<string, mixed> $fields */
    public function updateVideo(string $providerId, array $fields): void;

    /**
     * Collections as the provider sees them. These seed the local category
     * tree on import; afterwards local categories win.
     *
     * @return list<array{id: string, name: string, videoCount: int}>
     */
    public function listCollections(): array;

    /**
     * Aggregate view statistics for the analytics screen.
     *
     * @return array{views: int, watchTime: int, chart: array<string, int>}
     */
    public function statistics(int $days = 30): array;
}
