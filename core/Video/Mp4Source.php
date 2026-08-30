<?php

declare(strict_types=1);

namespace Portal\Video;

/**
 * A downloadable MP4, or the specific reason there isn't one.
 *
 * `downloadUrl()` answers `?string`, and the null is the problem: four
 * completely different situations produce it, they need four different actions,
 * and the caller cannot tell them apart.
 *
 *   - the library has never had MP4 Fallback switched on
 *   - it is on now, but this video predates it — bunny.net does not backfill
 *   - it is on and this video has renditions, but none at or under the cap
 *   - the pull zone rejected the request, which is a token or zone setting
 *
 * The first is a dashboard setting. The second means re-uploading that video.
 * The third means lowering the cap. The fourth is credentials. Answering "no"
 * to all four sends somebody looking in the wrong place, and this is the URL a
 * podcast client fetches and an offline download would save — so it is also the
 * URL most likely to be reported as "broken" by somebody who cannot see a log.
 */
final class Mp4Source
{
    public const NO_FALLBACK   = 'no_fallback';
    public const NO_RENDITION  = 'no_rendition';
    public const NOT_CONFIGURED = 'not_configured';
    public const NOT_AT_PROVIDER = 'not_at_provider';

    private function __construct(
        public readonly ?string $url,
        public readonly ?string $reason,
        /** The rendition actually chosen, so a caller can say which it served. */
        public readonly ?int $height = null,
    ) {
    }

    public static function found(string $url, int $height): self
    {
        return new self($url, null, $height);
    }

    public static function missing(string $reason): self
    {
        return new self(null, $reason);
    }

    public function ok(): bool
    {
        return $this->url !== null;
    }

    /**
     * Something an administrator can act on.
     *
     * Written for the person who has to fix it rather than for a log: each one
     * names the setting or the action, because "no MP4 available" is true of
     * all of them and useful for none.
     */
    public function explain(): string
    {
        return match ($this->reason) {
            self::NO_FALLBACK => 'This video has no MP4 version. Turn on MP4 Fallback for the '
                . 'library in the bunny.net dashboard — and note it is not retroactive, so videos '
                . 'uploaded before that was switched on have to be re-uploaded to get one.',
            self::NO_RENDITION => 'This video has MP4 versions, but none at or under the configured '
                . 'download height. Lower the download height, or re-encode the video larger.',
            self::NOT_CONFIGURED => 'No pull zone is configured, so no file URL can be signed.',
            self::NOT_AT_PROVIDER => 'The video service does not have this video. It may have been '
                . 'deleted there, or this site may be pointed at a different library.',
            default => 'No MP4 version of this video is available.',
        };
    }
}
