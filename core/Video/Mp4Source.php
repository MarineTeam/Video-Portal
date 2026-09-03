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
     * What the CDN said when the signed URL was actually fetched.
     *
     * The four reasons above are all answers this site works out for itself,
     * from what the provider's API said. There is a fifth situation none of
     * them covers: everything looks right here, the URL is signed and handed
     * over, and the CDN refuses it. That refusal reaches the BROWSER, so the
     * site never sees it — the person reports "the download does not work" and
     * every screen here says it should.
     *
     * 403 and 404 mean opposite things and are indistinguishable from the
     * outside: a rejected token is the wrong pull-zone key, which is a
     * credentials problem on the Services screen; a 404 is a file that is not
     * there, which is an encoding problem at the provider. Guessing between
     * them is how somebody spends an afternoon re-entering a key that was
     * always correct.
     *
     * Deliberately NOT run on the download path. It is one extra round trip per
     * download, paid on every request, to answer a question that is only asked
     * when something is already wrong. It belongs on a button.
     */
    public static function diagnose(int $httpStatus, ?string $transportError): string
    {
        if ($transportError !== null) {
            return 'The CDN could not be reached at all: ' . $transportError
                . '. That is a network or DNS problem rather than a setting.';
        }

        return match (true) {
            $httpStatus >= 200 && $httpStatus < 300 => 'The file is there and the signature was accepted. '
                . 'Downloads of this video work.',
            $httpStatus === 403 => 'The CDN rejected the signature (403). The pull zone URL token key is '
                . 'wrong — note it is a DIFFERENT key from the Token Authentication Key that signs '
                . 'playback, and pasting one into both fields is the usual cause. Services → Video.',
            $httpStatus === 404 => 'The CDN has no file at that address (404). The signature was fine, so '
                . 'this is the MP4 itself missing — the rendition was never encoded, or the video was '
                . 'uploaded before MP4 Fallback was switched on and needs re-uploading.',
            $httpStatus === 401 => 'The CDN refused the request as unauthenticated (401), which usually '
                . 'means token authentication is enabled on the pull zone but no key is configured here.',
            default => sprintf('The CDN answered %d, which is neither a refusal nor a file. '
                . 'That is usually the pull zone itself being misconfigured.', $httpStatus),
        };
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
