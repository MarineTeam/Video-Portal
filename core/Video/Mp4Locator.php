<?php

declare(strict_types=1);

namespace Portal\Video;

use Portal\Content\Video;
use Portal\Content\VideoRepository;

/**
 * Where a video's MP4 comes from, and what it costs to find out.
 *
 * `BunnyStreamProvider::mp4Source()` answers correctly and spends an API call
 * doing it. That is the right trade for a podcast enclosure, fetched now and
 * then by a client that will wait. It is the wrong trade for the downloads
 * feature this precedes, where the question "can this be downloaded" is asked
 * once per video card — fifty outbound calls to render one listing, inside a
 * visitor's page view, on shared hosting with an execution limit.
 *
 * So the answer is cached on the video row and this class decides when to
 * believe it.
 *
 * THE RULE, AND WHY IT IS ONE LINE
 *
 *     mp4_checked_at IS NULL  ->  nobody has asked; go and ask
 *     otherwise               ->  use what we were told
 *
 * Every row in every existing install arrives with has_mp4 = 0 after the
 * migration, and that zero is not an answer. A caller that read it as one
 * would tell a site with MP4 Fallback plainly switched on that none of its
 * videos has a file, and name the dashboard setting to go and enable — the
 * setting already enabled. The timestamp is what separates "no" from "not
 * yet", and putting it in one class is what stops each new consumer of this
 * having to rediscover the difference.
 *
 * The first ask also writes the answer down, so a library that has never been
 * synced heals itself one video at a time under ordinary traffic rather than
 * waiting for a cron run that may never fire.
 */
final class Mp4Locator
{
    public function __construct(
        private readonly VideoProvider $provider,
        private readonly VideoRepository $videos,
    ) {
    }

    public function locate(Video $video, int $ttlSeconds = 3600): Mp4Source
    {
        /*
         * Another provider entirely. Only the interface's `?string` is
         * available, so there is one bit of information and no reason to
         * report: a URL, or nothing.
         *
         * Not cached, because nothing here knows what that provider's answer
         * depends on or when it changes — and a cache whose invalidation rules
         * are guesswork is worse than the call it saves.
         */
        if (!$this->provider instanceof SupportsMp4Downloads) {
            $url = $this->provider->downloadUrl($video->providerId, $ttlSeconds);

            return $url === null
                ? Mp4Source::missing(Mp4Source::NOT_CONFIGURED)
                : Mp4Source::found($url, 0);
        }

        if ($video->mp4IsKnown()) {
            return $this->provider->mp4SourceFrom(
                $video->providerId,
                $video->hasMp4,
                $video->mp4Heights,
                $ttlSeconds
            );
        }

        /*
         * Never asked. Ask, and keep the reply.
         *
         * The write happens through the callback rather than after the fact
         * because only the paths where the provider genuinely answered offer
         * one — an unreachable API and a response that omits the fields both
         * come back with a signed URL and nothing to store, and neither may
         * leave a timestamp behind claiming otherwise.
         */
        return $this->provider->mp4Source(
            $video->providerId,
            $ttlSeconds,
            function (VideoMeta $meta) use ($video): void {
                $this->videos->recordMp4Availability(
                    $video->id,
                    (bool) $meta->hasMp4Fallback,
                    $meta->resolutions
                );
            }
        );
    }
}
