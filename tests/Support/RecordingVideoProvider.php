<?php

declare(strict_types=1);

namespace Portal\Tests\Support;

use Portal\Providers\TestResult;
use Portal\Video\UploadTicket;
use Portal\Video\VideoMeta;
use Portal\Video\VideoPage;
use Portal\Video\VideoProvider;

/**
 * A video provider that answers instantly and remembers what it was asked.
 *
 * The call count is the point. Asserting that a locked card carries no
 * thumbnail URL is weaker than asserting that no URL was ever minted: a URL
 * that gets created and then discarded still existed, and the next refactor
 * puts it back into the array.
 */
final class RecordingVideoProvider implements VideoProvider
{
    public const THUMBNAIL = 'https://cdn.test/thumbnail.jpg?token=signed';
    public const EMBED = 'https://iframe.test/embed?token=signed';

    public int $thumbnailCalls = 0;
    public int $embedCalls = 0;

    // ------------------------------------------- the shared Provider contract

    public static function slug(): string
    {
        return 'recording';
    }

    public static function label(): string
    {
        return 'Recording (test double)';
    }

    public static function description(): string
    {
        return 'Answers instantly and counts what it was asked. Never registered.';
    }

    /** @return list<\Portal\Providers\SettingField> */
    public static function fields(): array
    {
        return [];
    }

    /** @return list<string> */
    public static function requiredExtensions(): array
    {
        return [];
    }

    public function test(): TestResult
    {
        return TestResult::pass();
    }

    // ------------------------------------------------------------- the video

    public function listVideos(int $page = 1, int $perPage = 100): VideoPage
    {
        return new VideoPage([], $page, $perPage, 0);
    }

    public function getVideo(string $providerId): ?VideoMeta
    {
        return null;
    }

    public function embedUrl(string $providerId, int $ttlSeconds = 10800): string
    {
        $this->embedCalls++;

        return self::EMBED;
    }

    public function thumbnailUrl(string $providerId, ?string $thumbnailFile, int $ttlSeconds = 21600): ?string
    {
        $this->thumbnailCalls++;

        return self::THUMBNAIL;
    }

    public function createUploadTicket(string $title, ?string $collectionId = null): UploadTicket
    {
        return new UploadTicket('provider-id', 'https://upload.test', [], time() + 3600);
    }

    public function deleteVideo(string $providerId): void
    {
    }

    /** @param array<string, mixed> $fields */
    public function updateVideo(string $providerId, array $fields): void
    {
    }

    /** @return list<array{id: string, name: string, videoCount: int}> */
    public function listCollections(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function statistics(int $days = 30): array
    {
        return [];
    }
}
