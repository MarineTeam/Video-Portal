<?php
/**
 * Plugin Name: Playback
 * Slug: playback
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Skip to the sermon, and roll into the next episode when one finishes.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Container;
use Portal\Content\ChapterRepository;
use Portal\Content\VideoRepository;
use Portal\Plugins\Playback\PlaybackPage;
use Portal\Plugins\Playback\PlaybackPolicy;
use Portal\Plugins\Playback\PlaybackView;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/PlaybackPolicy.php';
require_once __DIR__ . '/src/PlaybackView.php';
require_once __DIR__ . '/src/PlaybackPage.php';

/**
 * The next episode, or null.
 *
 * SERIES ONLY, deliberately. In a series "next" is a fact somebody decided
 * when they set the running order; for a standalone video it would be a
 * recommendation, and a recommendation that plays itself in ten seconds is a
 * different and much ruder thing. "More like this" already offers those, and
 * it waits to be clicked.
 *
 * @return array{title: string, url: string}|null
 */
$nextInSeries = static function (int $videoId) use ($plugin): ?array {
    /** @var VideoRepository $videos */
    $videos = Container::instance()->get(VideoRepository::class);

    $current = $videos->find($videoId);
    if ($current === null || $current->seriesId === null) {
        return null;
    }

    /*
     * Through the ordinary listing query, NOT forSeries().
     *
     * forSeries() filters published, ready and hidden — and not member-only,
     * and not the schedule window. Using it here would offer a signed-out
     * visitor the title of a members-only episode, or one whose publish date
     * has not arrived. This is the same rule the tag page follows: a new way
     * to reach content must not be a second way to see what the listing hides.
     *
     * includeMemberOnly matches what the watch page itself decides, so the
     * next episode is offered to exactly the people who could open it.
     */
    $user = $plugin->user();
    $mayWatch = $user !== null && ($user->isAdmin() || $user->authorized);

    $result = $videos->query([
        'seriesId'          => $current->seriesId,
        'includeMemberOnly' => $mayWatch,
    ], 1, 200);

    /*
     * The query decides WHICH episodes may be named; the series decides in
     * WHAT ORDER.
     *
     * Those are two different questions and the listing only answers the
     * first. Its ORDER BY is pinned-then-position-then-newest, which is right
     * for a page of videos and wrong for "what comes after this one" — walking
     * that order gave the previous episode, or nothing at all when the current
     * video happened to sort last. Sorting the permitted set by
     * series_position here keeps the visibility rules in the one place that
     * owns them and puts the running order back.
     */
    $episodes = $result['items'];
    usort(
        $episodes,
        static fn ($a, $b): int => [$a->seriesPosition, $a->id] <=> [$b->seriesPosition, $b->id]
    );

    $found = false;
    foreach ($episodes as $candidate) {
        if ($found) {
            return [
                'title' => $candidate->title,
                'url'   => '/watch/' . $candidate->slug,
            ];
        }

        if ($candidate->id === $videoId) {
            $found = true;
        }
    }

    // Either this is the last episode, or the current video is itself hidden
    // from the query — an admin previewing a draft, say. Both mean there is
    // nothing honest to offer.
    return null;
};

/*
 * ONE plugin for two features, because they are one piece of plumbing.
 *
 * Skipping to a chapter and knowing when a video ended are both the same
 * conversation with the same cross-origin iframe over the same postMessage
 * protocol. Two plugins would each ship their own copy of the fragile half —
 * finding the player, waiting for `ready`, tolerating a player that never
 * answers — and the copy that got missed would be the one that broke.
 *
 * They are independently switchable, which is what somebody actually wants
 * when they want one and not the other.
 */
$plugin->addAction('after_video', static function (array $video) use ($plugin, $nextInSeries): void {
    $videoId = isset($video['id']) ? (int) $video['id'] : 0;
    if ($videoId <= 0) {
        return;
    }

    $skip = null;
    $next = null;

    try {
        if ((bool) $plugin->setting('skip_enabled', true)) {
            $chapters = Container::instance()->get(ChapterRepository::class)->forVideo($videoId);
            $skip = PlaybackPolicy::skipTarget(
                $chapters,
                (string) $plugin->setting('skip_titles', PlaybackPolicy::DEFAULT_TITLES)
            );
        }

        if ((bool) $plugin->setting('next_enabled', true)) {
            $next = $nextInSeries($videoId);
        }
    } catch (Throwable $e) {
        // A broken lookup must not take the video page with it. The player
        // still plays; only the conveniences are lost.
        error_log('Playback: could not work out what comes next. ' . $e->getMessage());

        return;
    }

    if ($skip === null && $next === null) {
        return;
    }

    echo PlaybackView::widget(
        $skip,
        $next,
        PlaybackPolicy::countdown($plugin->setting('next_countdown', PlaybackPolicy::DEFAULT_COUNTDOWN)),
        $plugin->assetUrl('playback.js')
    );
}, 3);

$plugin->addAdminPage(
    'Playback',
    'playback',
    Capability::MANAGE_SETTINGS,
    static fn ($request, $params) => (new PlaybackPage($plugin))->show($request, $params),
    position: 32
);
