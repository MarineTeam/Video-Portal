<?php
/**
 * Plugin Name: Ratings
 * Slug: ratings
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Lets approved viewers rate a video out of five, with a weighted leaderboard.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Container;
use Portal\Db;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\Ratings\RatingPage;
use Portal\Plugins\Ratings\RatingPolicy;
use Portal\Plugins\Ratings\RatingRepository;
use Portal\Plugins\Ratings\RatingView;
use Portal\Support\RateLimit;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/RatingPolicy.php';
require_once __DIR__ . '/src/RatingRepository.php';
require_once __DIR__ . '/src/RatingView.php';
require_once __DIR__ . '/src/RatingPage.php';

/**
 * Who may rate.
 *
 * The same bar as commenting, and for the same reason: an anonymous rating is
 * one anybody can cast as often as they can clear a cookie, and an average
 * assembled from those is worse than no average at all. Everybody can *read*
 * one.
 */
$canRate = static function () use ($plugin): bool {
    $user = $plugin->user();

    return $user !== null && ($user->isAdmin() || $user->authorized);
};

$repository = static fn (): RatingRepository => new RatingRepository(Container::instance()->get(Db::class));

/*
 * The widget, under the video and above the comments.
 *
 * Priority 5 rather than the default 10, which is where comments registers: a
 * one-line summary belongs above a thread that can run to a screenful.
 *
 * @param array<string, mixed> $video the template's video data
 */
$plugin->addAction('after_video', static function (array $video) use ($plugin, $canRate, $repository): void {
    $videoId = isset($video['id']) ? (int) $video['id'] : 0;
    if ($videoId <= 0) {
        return;
    }

    // Per-category override, the same as comments: ratings can be switched off
    // for one section of the site without deactivating the plugin everywhere.
    try {
        $videos = Container::instance()->get(\Portal\Content\VideoRepository::class);
        $model = $videos->find($videoId);

        if ($model !== null && !$plugin->isEnabledFor($videos->effectiveCategoryId($model))) {
            return;
        }
    } catch (Throwable $e) {
        error_log('Ratings: could not resolve the category; showing the widget. ' . $e->getMessage());
    }

    $user = $plugin->user();

    try {
        $totals = $repository()->forVideo($videoId);
        $yours = $user === null ? null : $repository()->scoreBy($videoId, $user->email);
    } catch (Throwable $e) {
        // A broken total must not take the video page with it.
        error_log('Ratings: could not load the totals: ' . $e->getMessage());

        return;
    }

    echo RatingView::widget(
        $totals,
        $yours,
        $canRate() ? '/ratings/' . $videoId : '',
        $plugin->csrfField(),
        (int) $plugin->setting('minimum_votes', 1),
        (bool) $plugin->setting('allow_changes', true),
        (string) (Container::instance()->get(\Portal\Auth\Session::class)->pull('rating_notice') ?? '')
    );
}, 5);

/*
 * Casting, changing, or withdrawing a rating.
 *
 * One route for all three, because they are the same decision — what do I think
 * of this — expressed three ways, and splitting them would mean two more places
 * for the rate limit and the permission check to drift apart.
 *
 * The {video:\d+} constraint is not decoration. An unconstrained {video} would
 * match any single segment, so a later literal route under /ratings/ would be
 * swallowed here with video cast to 0: a silent no-op that still answers 302.
 * The comments plugin shipped exactly that bug.
 */
$plugin->addRoute(
    'POST',
    '/ratings/{video:\d+}',
    static function (Request $request, array $params) use ($plugin, $canRate, $repository): Response {
        $plugin->verifyCsrf($request);

        $videoId = (int) ($params['video'] ?? 0);
        $user = $plugin->user();

        if (!$canRate() || $user === null) {
            return Response::redirect($plugin->config()->url('/auth/login'));
        }

        $db = Container::instance()->get(Db::class);

        // Looked up rather than taken from the form. A redirect target a
        // visitor controls is an open redirect waiting to happen, and it also
        // means a rating for a video that no longer exists goes nowhere
        // instead of writing a row the foreign key would reject anyway.
        $slug = (string) ($db->value('SELECT slug FROM {videos} WHERE id = ?', [$videoId]) ?? '');
        if ($slug === '') {
            return Response::redirect($plugin->config()->url('/'));
        }

        $say = static function (string $message) use ($plugin, $slug): Response {
            Container::instance()->get(\Portal\Auth\Session::class)->put('rating_notice', $message);

            return Response::redirect($plugin->config()->url('/watch/' . $slug . '#ratings'));
        };

        // Generous, because changing your mind twice is normal and the limit
        // is here to stop a script rather than to ration an opinion.
        $limiter = new RateLimit($db);
        if (!$limiter->allow('rating:' . $user->id, 30, 600)) {
            return $say('You are rating very quickly. Give it a minute.');
        }

        $allowChanges = (bool) $plugin->setting('allow_changes', true);

        if ((string) ($request->input('action') ?? '') === 'remove') {
            if (!$allowChanges) {
                return $say('Ratings cannot be changed on this site.');
            }

            $repository()->remove($videoId, $user->email);

            return $say('Your rating has been removed.');
        }

        $score = RatingPolicy::sanitize($request->input('score'));
        if ($score === null) {
            // Refused rather than clamped: recording a rating nobody gave is
            // worse than dropping one somebody mangled.
            return $say(sprintf(
                'A rating has to be a whole number from %d to %d.',
                RatingPolicy::MIN_SCORE,
                RatingPolicy::MAX_SCORE
            ));
        }

        if (!$repository()->rate($videoId, $user->email, $score, $allowChanges)) {
            return $say('You have already rated this, and ratings cannot be changed on this site.');
        }

        return $say('Thanks — your rating has been saved.');
    },
    ['auth.authorized']
);

$plugin->addAdminPage(
    'Ratings',
    'ratings',
    Capability::VIEW_ANALYTICS,
    static fn ($request, $params) => (new RatingPage($plugin))->show($request, $params),
    position: 31
);
