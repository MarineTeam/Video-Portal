<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\DownloadPolicy;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;
use Portal\Video\Mp4Locator;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * Handing somebody the file.
 *
 * Four gates, and all four have to say yes. They are separate because they
 * answer separate questions and are set on separate screens, and collapsing any
 * two of them means one gets forgotten:
 *
 *   1. Can this person watch this video at all? The ordinary listing query,
 *      which owns publication, scheduling, hidden and members-only.
 *   2. May they download things — `Capability::DOWNLOAD_CONTENT`, scoped to
 *      this video, so a grant on one category does not reach the rest.
 *   3. May THIS video be downloaded — `DownloadPolicy`, resolved video →
 *      series → categories → site setting.
 *   4. Is there a file to hand over — `Mp4Locator`, which now answers from the
 *      video row rather than an API call.
 *
 * THE RULE THIS INHERITS FROM THE APP IT IS PORTED FROM: downloads may only
 * NARROW view access, never widen it. Gate 1 runs first and is the same query
 * that decides whether the watch page renders, so there is no arrangement of
 * download settings that makes something downloadable which was not already
 * watchable. Allowing downloads on a category cannot publish an unpublished
 * video in it.
 *
 * Separate from FeedController::media, which is the podcast enclosure and is
 * deliberately anonymous — a feed reader has no session. That route serves
 * PUBLIC videos only and is governed by whether a video is in the feed. This
 * one serves a signed-in person and is governed by what they hold. Sharing a
 * handler between them is how the anonymous one eventually inherits a branch
 * that trusts a capability nobody checked.
 */
final class DownloadController extends Controller
{
    /**
     * Three hours, matching the embed. Long enough for a large file on a slow
     * connection, short enough that a URL copied out of a network tab is not a
     * lasting way in.
     */
    private const TTL = 10800;

    /** @param array<string, string> $params */
    public function media(Request $request, array $params): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);
        $video = $videos->findBySlug((string) ($params['slug'] ?? ''));

        /*
         * A 404 rather than a 403 for a video that is not there or not
         * visible, matching /watch and /media. Telling somebody a video exists
         * but is out of reach is itself a leak, and it is the same answer for a
         * slug that was never a video.
         */
        if ($video === null) {
            throw HttpException::notFound('There is no video at that address.');
        }

        $mayWatch = $user->isAdmin() || $user->authorized;
        $visible = $videos->query(
            ['ids' => [$video->id], 'includeMemberOnly' => $mayWatch],
            1,
            1
        );

        if ($visible['items'] === []) {
            throw HttpException::notFound('There is no video at that address.');
        }

        /*
         * The capability, checked against THIS video. Site-wide holders pass,
         * and so does somebody granted it on the video's category or series —
         * the resolver walks that chain. A holder scoped to one section is
         * refused everywhere else, which is the whole reason it is scopable.
         *
         * A 403 here rather than a 404, because at this point the person is
         * known to be able to watch the video: they can see it exists, so
         * hiding the refusal would only be confusing.
         */
        if (!$this->guard()->can(Capability::DOWNLOAD_CONTENT, 'video', $video->id)) {
            throw HttpException::forbidden('You do not have permission to download videos.');
        }

        $mode = $videos->downloadModeFor($video, $this->siteDefault());

        if (!DownloadPolicy::allows($mode)) {
            throw HttpException::forbidden('Downloads are turned off for this video.');
        }

        try {
            /** @var VideoProvider $provider */
            $provider = $this->container->get(VideoProvider::class);
            $source = (new Mp4Locator($provider, $videos))->locate($video, self::TTL);
        } catch (Throwable $e) {
            throw HttpException::upstream('The video service is not responding: ' . $e->getMessage());
        }

        if ($source->url === null) {
            /*
             * The reason, not "unavailable". Four different situations produce
             * a missing MP4 and they need four different fixes; this is the
             * page somebody reports as broken, and an administrator reading
             * over their shoulder should be able to act on it.
             */
            throw HttpException::notFound($source->explain());
        }

        /*
         * Logged, unlike playback. A download is the one action here whose
         * effect outlives the session, so "who has a copy of this" is a
         * question somebody will eventually need answered — and it cannot be
         * reconstructed later from anything else.
         */
        Audit::log(
            $this->db(),
            $user->email,
            'video.download',
            'video',
            (string) $video->id,
            $video->title . ' (' . $source->height . 'p)'
        );

        /*
         * 302 and never cacheable, for the same reason as the podcast route: a
         * permanent redirect would be stored by the browser and by anything
         * between, which is exactly the expiry problem signing on demand exists
         * to avoid.
         */
        return Response::redirect($source->url, 302)
            ->header('Cache-Control', 'private, max-age=0, no-store');
    }

    /**
     * The site-wide default the inheritance chain falls back to.
     *
     * OFF unless somebody turned it on. A download is the one thing this
     * application hands out that it cannot take back, so it must not begin
     * happening because a site was upgraded.
     */
    private function siteDefault(): bool
    {
        return $this->config()->settingBool('downloads_enabled', false);
    }
}
