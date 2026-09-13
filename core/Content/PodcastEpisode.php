<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Whether a video is an episode of the podcast, and if not, why not.
 *
 * # PUBLISHING TO A PODCAST IS OPT-IN, PER VIDEO
 *
 * Until now every public video was a podcast episode with nobody having chosen
 * that. The reason it should be a choice is that it is the one publication in
 * this product that cannot be taken back: un-ticking a video removes it from the
 * feed and stops NEW downloads, but every podcast app that already fetched the
 * file keeps it, and nothing anywhere can recall that. Everything else here —
 * members-only, hiding, scheduling out — takes effect on the next request.
 * This does not, so it happens only when somebody ticks the box.
 *
 * # THE INTENT IS STORED APART FROM WHETHER IT CURRENTLY QUALIFIES
 *
 * `in_podcast` records what an editor decided. Whether the video is actually in
 * the feed right now is decided at request time by the ordinary visibility
 * rules. So a ticked video that becomes members-only leaves the feed on its own,
 * and comes back on its own when it is public again — without anybody having to
 * remember to re-tick it, and without the tick being silently lost.
 *
 * That gap between intent and state is what PENDING is for: ticked, and held
 * back, with the reason said out loud. A box that is ticked while the video is
 * nowhere in the feed, with nothing explaining it, reads as the feature being
 * broken.
 */
final class PodcastEpisode
{
    /** Not ticked. */
    public const OUT = 'out';

    /** Ticked, and in the feed. */
    public const IN = 'in';

    /** Ticked, and currently held back by something else. */
    public const PENDING = 'pending';

    /**
     * The state of one video.
     *
     * @param bool $publiclyVisible whether query() with the PUBLIC filter set
     *                              returns this video — the feed's own rule,
     *                              asked rather than re-derived here
     */
    public static function state(Video $video, bool $publiclyVisible): string
    {
        if (!$video->inPodcast) {
            return self::OUT;
        }

        return $publiclyVisible ? self::IN : self::PENDING;
    }

    /**
     * Why a ticked video is not in the feed, in words an editor can act on.
     *
     * The ORDER of these is the order somebody would fix them in, most specific
     * first — a video that is both members-only and in a members-only series is
     * told about itself before its series, since that is the one on this
     * screen.
     *
     * The last answer is deliberately vague. query() decides visibility and can
     * refuse a video for a reason this list does not name — a group restriction,
     * an encode that has not finished — and inventing a precise-sounding reason
     * would send somebody to change the wrong setting.
     *
     * @param ?Series $series the video's series, if it has one
     */
    public static function heldBackBecause(Video $video, ?Series $series, ?int $now = null): string
    {
        $now ??= time();

        if (!$video->isPublished) {
            return 'it is a draft';
        }

        if ($video->hidden) {
            return 'it is hidden';
        }

        if ($video->memberOnly) {
            return 'it is members-only';
        }

        if ($video->publishedAt !== null && strtotime($video->publishedAt) > $now) {
            return 'it is not published until ' . $video->publishedAt;
        }

        if ($video->unpublishAt !== null && strtotime($video->unpublishAt) <= $now) {
            return 'its run ended on ' . $video->unpublishAt;
        }

        if ($series !== null && $series->memberOnly) {
            return 'its series is members-only';
        }

        return 'it is not publicly visible';
    }
}
