<?php

declare(strict_types=1);

namespace Portal\Video;

use Portal\Content\Video;

/**
 * Where one video's player comes from.
 *
 * Two callers mint an embed URL — the watch page and the share page — and until
 * now both went straight to the configured `VideoProvider`. That is right for
 * every video the site hosts and wrong for an imported one, which is not at the
 * provider at all: asking bunny.net to sign a YouTube id produces a signed URL
 * for a video that does not exist, and the failure arrives as a player that
 * will not load.
 *
 * So the question "where does this video play from" is asked HERE, once, by
 * both. A third caller — a plugin, a theme, whatever comes next — gets the
 * right answer without knowing that imported videos exist.
 *
 * The provider is passed in rather than resolved, because an imported video
 * must not need one: a site with no video service configured can still import a
 * YouTube link and play it, and constructing the provider to find out we do not
 * need it would take that away.
 */
final class EmbedResolver
{
    /**
     * @param callable(): VideoProvider $provider resolved only when needed
     */
    public function __construct(private $provider)
    {
    }

    /**
     * The address for the iframe.
     *
     * External first. An imported video has no provider id the provider would
     * recognise, so reaching the provider at all on this path is already the
     * bug — and asking about the source before asking the provider anything is
     * what makes an unconfigured site able to play imported videos.
     */
    public function embedUrl(Video $video, int $ttlSeconds): string
    {
        if (ExternalVideo::isExternal($video->provider)) {
            /*
             * Built from the stored source and id, which ARE the parse result —
             * the address that was pasted is not kept, because keeping it would
             * mean two things that have to agree about which video this is.
             */
            return self::externalEmbed($video->provider, $video->providerId);
        }

        return ($this->provider)()->embedUrl($video->providerId, $ttlSeconds);
    }

    /**
     * The embed address for a source and id that have already been validated.
     *
     * Static, and it goes back through ExternalVideo so the rules about which
     * player and which privacy flags live in one place. An id that no longer
     * parses — a row edited by hand, a source this build does not know — gives
     * an empty string, which every template already treats as "no player"
     * because that is what a premiere looks like.
     */
    public static function externalEmbed(string $source, string $id): string
    {
        $parsed = match ($source) {
            ExternalVideo::YOUTUBE => ExternalVideo::parse('https://www.youtube.com/watch?v=' . $id),
            ExternalVideo::VIMEO => ExternalVideo::parse('https://vimeo.com/' . $id),
            default => null,
        };

        return $parsed?->embedUrl() ?? '';
    }
}
