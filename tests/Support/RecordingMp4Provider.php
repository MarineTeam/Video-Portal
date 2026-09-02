<?php

declare(strict_types=1);

namespace Portal\Tests\Support;

use Portal\Video\Mp4Source;
use Portal\Video\SupportsMp4Downloads;
use Portal\Video\VideoMeta;

/**
 * A provider that can explain a missing MP4, and counts who asked.
 *
 * The counts are the assertion that matters. "The cached path returns the right
 * URL" is satisfied just as well by an implementation that asks the provider
 * every single time and then happens to agree — which is precisely the cost the
 * cache exists to avoid, and it would be invisible from the answer alone.
 */
final class RecordingMp4Provider extends RecordingVideoProvider implements SupportsMp4Downloads
{
    public const SIGNED = 'https://cdn.test/play_%dp.mp4?token=signed';

    public int $mp4SourceCalls = 0;
    public int $mp4SourceFromCalls = 0;

    /** The height cap, matching the provider setting it stands in for. */
    public int $cap = 720;

    public function mp4Source(
        string $providerId,
        int $ttlSeconds = 3600,
        ?callable $onAnswer = null
    ): Mp4Source {
        $this->mp4SourceCalls++;

        try {
            $meta = $this->getVideo($providerId);
        } catch (\Throwable) {
            // Could not ask. Sign the cap and let the CDN judge, as the real
            // one does — emphatically not a verdict that there is no file.
            return $this->sign($providerId, $this->cap);
        }

        if ($meta === null) {
            return Mp4Source::missing(Mp4Source::NOT_AT_PROVIDER);
        }

        if ($meta->hasMp4Fallback === null) {
            return $this->sign($providerId, $this->cap);
        }

        if ($onAnswer !== null) {
            $onAnswer($meta);
        }

        return $this->mp4SourceFrom($providerId, $meta->hasMp4Fallback, $meta->resolutions, $ttlSeconds);
    }

    /** @param list<int> $resolutions */
    public function mp4SourceFrom(
        string $providerId,
        bool $hasFallback,
        array $resolutions,
        int $ttlSeconds = 3600
    ): Mp4Source {
        $this->mp4SourceFromCalls++;

        if (!$hasFallback) {
            return Mp4Source::missing(Mp4Source::NO_FALLBACK);
        }

        $heights = array_map('intval', $resolutions);
        sort($heights);

        foreach (array_reverse($heights) as $height) {
            if ($height > 0 && $height <= $this->cap) {
                return $this->sign($providerId, $height);
            }
        }

        return Mp4Source::missing(Mp4Source::NO_RENDITION);
    }

    /** Convenience for staging what getVideo() will answer. */
    public function stage(string $providerId, ?bool $hasFallback, array $resolutions = []): void
    {
        $this->videos[$providerId] = new VideoMeta(
            id: $providerId,
            title: 'Staged',
            status: VideoMeta::STATUS_READY,
            hasMp4Fallback: $hasFallback,
            resolutions: $resolutions,
        );
    }

    private function sign(string $providerId, int $height): Mp4Source
    {
        return Mp4Source::found(sprintf(self::SIGNED, $height), $height);
    }
}
