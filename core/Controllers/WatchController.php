<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * Playback and watch progress.
 *
 * The embed URL is minted per request and never stored. Every predecessor app
 * did the same, for the same reason: a stored URL outlives its expiry and then
 * produces a 403 that looks to a viewer like the video is broken.
 */
final class WatchController extends Controller
{
    /** Playback URLs last three hours — long enough for any single sitting. */
    private const EMBED_TTL = 10800;

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $slug = $params['slug'] ?? '';
        $video = $videos->findBySlug($slug);

        if ($video === null) {
            // Honour an old slug with a permanent redirect.
            $aliased = $videos->findByAlias($slug);
            if ($aliased !== null) {
                return Response::redirect($this->config()->url($aliased->url()), 301);
            }
            throw HttpException::notFound('There is no video at that address.');
        }

        $canManage = $this->guard()->can(\Portal\Auth\Capability::MANAGE_VIDEOS);

        /*
         * A premiere is the one thing that is visible and not playable, so it
         * is checked before the generic visibility rule — which would 404 it,
         * that being what "scheduled" means for everything else.
         */
        $premiering = $video->isPremiering();

        if (!$premiering && !$video->isVisible() && !$canManage) {
            // Deliberately a 404 rather than a 403: telling an unauthorised
            // visitor that a video exists but is hidden is itself a leak.
            throw HttpException::notFound('There is no video at that address.');
        }

        $embedUrl = '';

        if (!$premiering || $canManage) {
            $provider = $this->container->get(VideoProvider::class);

            try {
                $embedUrl = $provider->embedUrl($video->providerId, self::EMBED_TTL);
            } catch (Throwable $e) {
                throw HttpException::upstream('The video service is not responding: ' . $e->getMessage());
            }

            /** @var string $embedUrl */
            $embedUrl = apply_filters('player_embed_url', $embedUrl, $video);
        }

        return $this->view(
            $this->themeManager()->loader()->hierarchy('video', ['slug' => $video->slug]),
            [
                'title' => $video->title,
                'video' => [
                    'id'          => $video->id,
                    'title'       => $video->title,
                    'description' => $video->description,
                    'embedUrl'    => $embedUrl,
                    'duration'    => $video->duration,
                    'speaker'     => $this->speakerName($video),
                    'speakerLink' => $this->speakerLink($video),
                    'series'      => $this->seriesLink($video),
                    'recordedAt'  => $this->formatDate($video->recordedAt),
                    'resumeAt'    => $this->resumePosition($video->id),
                    /*
                     * The embed URL is empty for a premiere, so a theme that
                     * ignores this flag renders an iframe with no source rather
                     * than a playable video. The failure is visible and inert,
                     * which is the right way round for something whose whole
                     * job is to not play yet.
                     */
                    'premiering'  => $premiering && !$canManage,
                    'premiereAt'  => $premiering ? $this->formatDate($video->publishedAt) : null,
                ],
                // Which of this viewer's lists the video is already on, so the
                // buttons can say "Saved" rather than offering to save
                // something that is already there.
                'transcript' => $this->transcriptCues($video->id),
                'savedLists' => $this->savedLists($video->id),
                'saveAction' => '/saved',
                'csrfField'  => '<input type="hidden" name="_token" value="'
                    . e($this->csrfToken()) . '">',
                'related' => [],
                'backUrl' => '/',
            ]
        );
    }

    /**
     * The transcript, if there is one.
     *
     * @return list<array{start: int, end: int, text: string}>
     */
    private function transcriptCues(int $videoId): array
    {
        try {
            return $this->container
                ->get(\Portal\Content\TranscriptRepository::class)
                ->cues($videoId);
        } catch (Throwable $e) {
            // A transcript is an addition to the page, not the page. Losing it
            // must never cost somebody the video.
            error_log('Could not read the transcript: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return list<string> the saved lists this video is on for this viewer
     */
    private function savedLists(int $videoId): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        try {
            return $this->container
                ->get(\Portal\Content\SavedVideoRepository::class)
                ->listsFor($user->id, $videoId);
        } catch (Throwable $e) {
            // A failure here must not take the player with it: not knowing
            // whether something is saved is an inconvenience, not being able to
            // watch it is the feature breaking.
            error_log('Could not read saved lists: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Record how far someone has watched.
     *
     * Called every few seconds by the player, so it is deliberately cheap: one
     * upsert, no reads, and a unique index doing the deduplication rather than
     * a read-then-write that would race between browser tabs.
     */
    public function saveProgress(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $videoId = (int) ($request->data('videoId') ?? 0);
        $position = (int) ($request->data('position') ?? 0);
        $duration = (int) ($request->data('duration') ?? 0);

        if ($videoId <= 0 || $position < 0 || $duration <= 0) {
            throw HttpException::badRequest('A video id, position, and duration are all required.');
        }

        // Clamp rather than reject: a player reporting a position slightly past
        // the end is normal, and failing the request would lose the fact that
        // the video was finished.
        $position = min($position, $duration);

        // Under ten seconds is not "watched" — it is someone clicking away.
        // Storing it would fill the continue-watching row with noise.
        if ($position < 10) {
            return $this->json(['saved' => false]);
        }

        $completed = $position >= $duration * 0.95;

        try {
            $this->db()->execute(
                'INSERT INTO {watch_progress}
                    (user_id, video_id, position_seconds, duration_seconds, completed_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    position_seconds = VALUES(position_seconds),
                    duration_seconds = VALUES(duration_seconds),
                    completed_at = COALESCE({watch_progress}.completed_at, VALUES(completed_at)),
                    updated_at = NOW()',
                [$user->id, $videoId, $position, $duration, $completed ? date('Y-m-d H:i:s') : null]
            );
        } catch (Throwable $e) {
            error_log('Portal: could not save watch progress: ' . $e->getMessage());
            return $this->json(['saved' => false], 200);
        }

        return $this->json(['saved' => true, 'completed' => $completed]);
    }

    public function getProgress(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            throw HttpException::unauthorized();
        }

        $videoId = (int) ($request->query('videoId') ?? 0);
        if ($videoId <= 0) {
            throw HttpException::badRequest('A video id is required.');
        }

        return $this->json(['position' => $this->resumePosition($videoId)]);
    }

    // -------------------------------------------------------------- helpers

    /**
     * Where to resume from.
     *
     * Zero when the video was finished or barely started, so someone who
     * watched to the end gets a fresh start rather than being dropped back at
     * the closing credits.
     */
    private function resumePosition(int $videoId): int
    {
        $user = $this->user();
        if ($user === null) {
            return 0;
        }

        try {
            $row = $this->db()->first(
                'SELECT position_seconds, duration_seconds, completed_at
                   FROM {watch_progress} WHERE user_id = ? AND video_id = ?',
                [$user->id, $videoId]
            );
        } catch (Throwable) {
            return 0;
        }

        if ($row === null || $row['completed_at'] !== null) {
            return 0;
        }

        $position = (int) $row['position_seconds'];
        $duration = (int) $row['duration_seconds'];

        if ($position < 10 || ($duration > 0 && $position >= $duration * 0.95)) {
            return 0;
        }

        return $position;
    }

    private function speakerName(Video $video): ?string
    {
        if ($video->speakerId === null) {
            return null;
        }

        try {
            $name = $this->db()->value('SELECT name FROM {speakers} WHERE id = ?', [$video->speakerId]);
            return is_string($name) ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The speaker as a link to everything else they have said.
     *
     * Separate from speakerName() so the plain string stays available: a theme
     * that only wants to print a name should not have to strip markup, and a
     * name is safe to escape where a link is not.
     *
     * @return array{name: string, url: string}|null
     */
    private function speakerLink(Video $video): ?array
    {
        if ($video->speakerId === null) {
            return null;
        }

        try {
            $row = $this->db()->first('SELECT name, slug FROM {speakers} WHERE id = ?', [$video->speakerId]);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : [
            'name' => (string) $row['name'],
            'url'  => '/speaker/' . $row['slug'],
        ];
    }

    /** @return array{title: string, url: string}|null */
    private function seriesLink(Video $video): ?array
    {
        if ($video->seriesId === null) {
            return null;
        }

        try {
            $row = $this->db()->first('SELECT title, slug FROM {series} WHERE id = ?', [$video->seriesId]);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : [
            'title' => (string) $row['title'],
            'url'   => '/series/' . $row['slug'],
        ];
    }

    private function formatDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($date))->format('j F Y');
        } catch (Throwable) {
            return null;
        }
    }
}
