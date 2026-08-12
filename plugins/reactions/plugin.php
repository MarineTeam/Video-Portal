<?php
/**
 * Plugin Name: Reactions
 * Slug: reactions
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Lets approved viewers respond to a video — Amen, moved, helpful, thankful — several at once.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Container;
use Portal\Db;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\Reactions\ReactionPolicy;
use Portal\Plugins\Reactions\ReactionRepository;
use Portal\Plugins\Reactions\ReactionView;
use Portal\Support\RateLimit;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/ReactionPolicy.php';
require_once __DIR__ . '/src/ReactionRepository.php';
require_once __DIR__ . '/src/ReactionView.php';

/**
 * Who may react.
 *
 * The same bar as commenting and rating. An anonymous reaction is one anybody
 * can leave as often as they can clear a cookie, and a count assembled from
 * those says nothing. Everybody can READ them.
 */
$canReact = static function () use ($plugin): bool {
    $user = $plugin->user();

    return $user !== null && ($user->isAdmin() || $user->authorized);
};

$repository = static fn (): ReactionRepository => new ReactionRepository(Container::instance()->get(Db::class));

/*
 * The row, under the video.
 *
 * Priority 4 — above ratings at 5 and comments at 10. Reactions are the
 * cheapest thing to do and the least committal, so they come first; a thread
 * that can run to a screenful comes last.
 *
 * @param array<string, mixed> $video the template's video data
 */
$plugin->addAction('after_video', static function (array $video) use ($plugin, $canReact, $repository): void {
    $videoId = isset($video['id']) ? (int) $video['id'] : 0;
    if ($videoId <= 0) {
        return;
    }

    // Per-category override, as comments and ratings do: reactions can be
    // switched off for one section without deactivating the plugin everywhere.
    try {
        $videos = Container::instance()->get(\Portal\Content\VideoRepository::class);
        $model = $videos->find($videoId);

        if ($model !== null && !$plugin->isEnabledFor($videos->effectiveCategoryId($model))) {
            return;
        }
    } catch (Throwable $e) {
        error_log('Reactions: could not resolve the category; showing the widget. ' . $e->getMessage());
    }

    $user = $plugin->user();

    try {
        $counts = $repository()->forVideo($videoId);
        $yours = $user === null ? [] : $repository()->byPerson($videoId, $user->email);
    } catch (Throwable $e) {
        // A broken count must not take the video page with it.
        error_log('Reactions: could not load the counts: ' . $e->getMessage());

        return;
    }

    $mayReact = $canReact();

    // Nothing to say and nobody able to say it: render nothing rather than a
    // row of zeroes, which reads as an empty site rather than a new feature.
    if (!ReactionPolicy::worthShowing($counts, $mayReact)) {
        return;
    }

    echo ReactionView::widget(
        $counts,
        $yours,
        $mayReact ? '/reactions/' . $videoId : '',
        $plugin->csrfField()
    );
}, 4);

/*
 * Leaving or withdrawing a reaction.
 *
 * One route for both, because they are one gesture: the button is the only
 * thing that shows the state, so pressing it again is the obvious way to undo
 * it and splitting them would mean two places for the rate limit and the
 * permission check to drift apart.
 *
 * The {video:\d+} constraint is not decoration. An unconstrained {video} would
 * match any single segment, so a later literal route under /reactions/ would be
 * swallowed here with video cast to 0 — a silent no-op that still answers 302.
 * The comments plugin shipped exactly that bug.
 */
$plugin->addRoute(
    'POST',
    '/reactions/{video:\d+}',
    static function (Request $request, array $params) use ($plugin, $canReact, $repository): Response {
        $plugin->verifyCsrf($request);

        $videoId = (int) ($params['video'] ?? 0);
        $user = $plugin->user();

        if (!$canReact() || $user === null) {
            return Response::redirect($plugin->config()->url('/auth/login'));
        }

        $db = Container::instance()->get(Db::class);

        // Looked up rather than taken from the form. A redirect target a
        // visitor controls is an open redirect waiting to happen, and it also
        // means a reaction to a video that no longer exists goes nowhere rather
        // than writing a row the foreign key would reject.
        $slug = (string) ($db->value('SELECT slug FROM {videos} WHERE id = ?', [$videoId]) ?? '');
        if ($slug === '') {
            return Response::redirect($plugin->config()->url('/'));
        }

        $back = static fn (): Response => Response::redirect(
            $plugin->config()->url('/watch/' . $slug . '#reactions')
        );

        /*
         * Generous, and per person rather than per video. Leaving four
         * reactions and changing your mind about two of them is six presses in
         * a minute, which is somebody using the feature rather than abusing it.
         */
        $limiter = new RateLimit($db);
        if (!$limiter->allow('reaction:' . $user->id, 40, 600)) {
            return $back();
        }

        $kind = (string) ($request->input('kind') ?? '');

        /*
         * An unknown kind is ignored, not refused with a message.
         *
         * Unlike a rating, there is no way for a person to type one of these
         * wrong — the buttons are the only source. An unrecognised value means
         * a hand-made request or a vocabulary that has since changed, and
         * neither wants an explanation.
         */
        if (ReactionPolicy::isKind($kind)) {
            $repository()->toggle($videoId, $user->id, $user->email, $kind);
        }

        return $back();
    },
    ['auth.authorized']
);
