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
use Portal\Support\ServiceWorker;
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
 * This also serves AUDIO MODE, which wants the same file for a different
 * reason, and gates 2 and 3 are the only difference: a download is a capability
 * somebody holds, a listen is a switch the site turned on. Gates 1 and 4 are
 * identical and run in the same order, so there is no purpose that can reach a
 * file the watch page would refuse. See listen().
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

    /**
     * What the file is being handed over FOR.
     *
     * Gates 1 and 4 are the same either way — can this person watch it, and is
     * there a file. Gates 2 and 3 are not, and they are the whole difference:
     * a download is a capability somebody holds, a listen is a setting the site
     * turned on.
     */
    private const FOR_DOWNLOAD = 'download';
    private const FOR_LISTENING = 'listen';

    /** @param array<string, string> $params */
    public function media(Request $request, array $params): Response
    {
        $resolved = $this->resolve($request, (string) ($params['slug'] ?? ''), self::FOR_DOWNLOAD);
        $video = $resolved['video'];
        $source = $resolved['source'];

        /*
         * 302 and never cacheable, for the same reason as the podcast route: a
         * permanent redirect would be stored by the browser and by anything
         * between, which is exactly the expiry problem signing on demand exists
         * to avoid.
         */
        return Response::redirect((string) $source->url, 302)
            ->header('Cache-Control', 'private, max-age=0, no-store');
    }

    /**
     * The same file, for listening rather than keeping.
     *
     * Audio mode exists because the video player is a cross-origin iframe: this
     * site cannot change its speed, cannot put anything on a lock screen, and
     * cannot keep playing when the phone is locked. An <audio> element on this
     * origin can do all three, and the file it needs is the MP4 the podcast
     * feed and the download route already serve.
     *
     * SO IT HANDS OUT A SAVABLE FILE, AND THAT IS SAID PLAINLY on the settings
     * screen rather than glossed. Anybody who can reach this URL can save what
     * it points at — an <audio src> is one long-press from a download on every
     * phone. Gating it behind DOWNLOAD_CONTENT instead would be honest about
     * that and would also mean nobody gets it, because "listen to the sermon in
     * the car" is the mainstream case and downloading is the rare grant. So it
     * is a site-wide setting, OFF by default, and the screen says what turning
     * it on means.
     *
     * The narrower reading is worth stating too: for a PUBLIC video the file is
     * already reachable without any of this, through the podcast enclosure at
     * /media/{slug}.mp4. What this setting actually widens is members-only
     * content, to people who may already watch it.
     *
     * @param array<string, string> $params
     */
    public function listen(Request $request, array $params): Response
    {
        $resolved = $this->resolve($request, (string) ($params['slug'] ?? ''), self::FOR_LISTENING);

        return Response::redirect((string) $resolved['source']->url, 302)
            ->header('Cache-Control', 'private, max-age=0, no-store');
    }

    /**
     * The four gates, in one place.
     *
     * Both entry points run this. A second copy of an access decision is how
     * the two come to disagree, and the disagreement here would be a JSON
     * endpoint handing out a signed URL for something the redirect refuses —
     * which is the same file, without the refusal.
     *
     * @param self::FOR_* $purpose which pair of middle gates to run
     * @return array{video: \Portal\Content\Video, source: \Portal\Video\Mp4Source}
     */
    private function resolve(Request $request, string $slug, string $purpose): array
    {
        $user = $this->user();
        if ($user === null) {
            /*
             * Not a redirect, because one of the two callers is fetch(). A
             * sign-in page delivered as the answer to an API call is parsed as
             * JSON, fails, and reports as a broken feature rather than a
             * signed-out session.
             */
            throw HttpException::forbidden('Sign in first.');
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);
        $video = $videos->findBySlug($slug);

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
         * Gates 2 and 3, which are the only ones that differ by purpose.
         *
         * Written as a match over every case rather than an `if ($download)`,
         * so a third purpose cannot inherit the download rules by falling
         * through a default — which on this method would mean handing out a
         * file under a check nobody wrote for it.
         */
        match ($purpose) {
            self::FOR_DOWNLOAD => $this->refuseUnlessDownloadable($video, $videos),
            self::FOR_LISTENING => $this->refuseUnlessListenable(),
        };

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
         *
         * Listening is NOT logged, and the line is worth drawing where it is.
         * A download is a person exercising a grant somebody gave them, which
         * is rare and consequential. A listen is an ordinary way of playing a
         * sermon, and one row per press would bury the entries this log exists
         * for under the ones it does not. That the file is fetchable either way
         * is a property of the SETTING, decided once on the settings screen,
         * not of each person who plays something.
         */
        if ($purpose === self::FOR_DOWNLOAD) {
            Audit::log(
                $this->db(),
                $user->email,
                'video.download',
                'video',
                (string) $video->id,
                $video->title . ' (' . $source->height . 'p)'
            );
        }

        return ['video' => $video, 'source' => $source];
    }

    /**
     * Gates 2 and 3 for a download: a scoped capability, then the policy.
     *
     * A 403 rather than a 404, because by this point the person is known to be
     * able to watch the video — they can see it exists, so hiding the refusal
     * would only confuse.
     */
    private function refuseUnlessDownloadable(\Portal\Content\Video $video, VideoRepository $videos): void
    {
        /*
         * Checked against THIS video. Site-wide holders pass, and so does
         * somebody granted it on the video's category or series — the resolver
         * walks that chain. A holder scoped to one section is refused
         * everywhere else, which is the whole reason it is scopable.
         */
        if (!$this->guard()->can(Capability::DOWNLOAD_CONTENT, 'video', $video->id)) {
            throw HttpException::forbidden('You do not have permission to download videos.');
        }

        $mode = $videos->downloadModeFor($video, $this->siteDefault());

        if (!DownloadPolicy::allows($mode)) {
            throw HttpException::forbidden('Downloads are turned off for this video.');
        }
    }

    /**
     * Gate 2 for listening: one site-wide switch, off by default.
     *
     * There is no per-video question here on purpose. `DownloadPolicy` exists
     * because handing somebody a file they keep is a decision worth making per
     * series — but audio mode is a way of PLAYING what they are already allowed
     * to play, and a tri-state inherited rule for it would be a second policy
     * chain to learn, set, and get wrong, governing something much closer to
     * pressing play than to taking a copy.
     *
     * A 403 with the reason: the video is watchable and the person can see it,
     * so a 404 would be a lie about why the control did not work.
     */
    private function refuseUnlessListenable(): void
    {
        if (!$this->config()->settingBool('audio_mode_enabled', false)) {
            throw HttpException::forbidden('Audio mode is turned off on this site.');
        }
    }

    /**
     * The same decision, as JSON, for the code that saves a video offline.
     *
     * The browser cannot use the redirect above for this. `fetch()` following a
     * cross-origin 302 gives back a response whose URL it will not reveal, and
     * saving into Cache Storage needs the URL as well as the bytes. So the
     * signed URL is handed over directly, after exactly the same four gates —
     * `media()` and this call the same method, so there is no arrangement in
     * which one of them permits something the other refuses.
     *
     * @param array<string, string> $params
     */
    public function meta(Request $request, array $params): Response
    {
        $resolved = $this->resolve($request, (string) ($params['slug'] ?? ''), self::FOR_DOWNLOAD);
        $video = $resolved['video'];
        $source = $resolved['source'];

        return $this->json([
            'id'       => $video->id,
            'title'    => $video->title,
            'slug'     => $video->slug,
            'duration' => $video->duration,
            'height'   => $source->height,
            'url'      => $source->url,
            /*
             * Where the worker will serve it from. Sent by the server rather
             * than assembled in JavaScript so the path exists in exactly one
             * place — a client that built its own would keep working until
             * ServiceWorker::VIDEO_PREFIX changed, and then fail by silently
             * saving to a URL nothing reads.
             */
            'cacheKey' => ServiceWorker::VIDEO_PREFIX . $video->id . '.mp4',
        ]);
    }

    /**
     * The signed URL itself, for casting.
     *
     * A television is not this browser. The <audio> element can use the
     * redirect above because the browser sends its session cookie and follows
     * the 302 for it; a Chromecast fetches the URL on its own, from its own
     * network stack, with no session at all — so it would be handed the
     * sign-in page and report the cast as failed.
     *
     * So the receiver is given the signed CDN URL directly, which needs no
     * session because the signature IS the permission. Exactly the same
     * reasoning as meta() beside it, which exists because fetch() following a
     * cross-origin 302 will not reveal where it landed.
     *
     * The gates are the listening gates, run by the same method as the
     * redirect. There is no arrangement in which this hands out a file that
     * /listen/{slug}.mp4 would refuse.
     *
     * @param array<string, string> $params
     */
    public function listenMeta(Request $request, array $params): Response
    {
        $resolved = $this->resolve($request, (string) ($params['slug'] ?? ''), self::FOR_LISTENING);

        return $this->json([
            'title'  => $resolved['video']->title,
            'height' => $resolved['source']->height,
            'url'    => $resolved['source']->url,
        ]);
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
