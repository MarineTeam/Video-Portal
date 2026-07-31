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

        if (!$video->isVisible() && !$this->guard()->can(\Portal\Auth\Capability::MANAGE_VIDEOS)) {
            // Deliberately a 404 rather than a 403: telling an unauthorised
            // visitor that a video exists but is hidden is itself a leak.
            throw HttpException::notFound('There is no video at that address.');
        }

        $provider = $this->container->get(VideoProvider::class);

        try {
            $embedUrl = $provider->embedUrl($video->providerId, self::EMBED_TTL);
        } catch (Throwable $e) {
            throw HttpException::upstream('The video service is not responding: ' . $e->getMessage());
        }

        /** @var string $embedUrl */
        $embedUrl = apply_filters('player_embed_url', $embedUrl, $video);

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
                    'series'      => $this->seriesLink($video),
                    'recordedAt'  => $this->formatDate($video->recordedAt),
                    'resumeAt'    => $this->resumePosition($video->id),
                ],
                'related' => [],
                'backUrl' => '/',
            ]
        );
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
