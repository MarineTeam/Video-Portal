<?php
/**
 * Plugin Name: What's new
 * Slug: whats-new
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Marks the videos published since somebody was last here.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Container;
use Portal\Db;
use Portal\Plugins\WhatsNew\VisitTracker;
use Portal\Plugins\WhatsNew\WhatsNewPage;
use Portal\Plugins\WhatsNew\WhatsNewPolicy;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/WhatsNewPolicy.php';
require_once __DIR__ . '/src/VisitTracker.php';
require_once __DIR__ . '/src/WhatsNewPage.php';

/*
 * The marker, worked out once per request.
 *
 * Resolved lazily rather than on `init`, so a request that renders no listing —
 * a stylesheet, a feed, the cron endpoint — does no work and writes nothing.
 * The first listing on a request pays for it and every later one on the same
 * page reuses the answer.
 *
 * `$resolved` is a separate flag because null is a real answer meaning "badge
 * nothing", and it is the answer for everybody who is signed out. Without the
 * flag every anonymous page render would retry the whole lookup for each
 * listing on it.
 */
$resolved = false;
$cutoff = null;

$markerFor = static function () use ($plugin, &$resolved, &$cutoff): ?string {
    if ($resolved) {
        return $cutoff;
    }
    $resolved = true;

    $user = $plugin->user();
    if ($user === null) {
        // Signed-out visitors get no badges, and the settings screen says so.
        // The only identity available is a cookie, and a marker that resets
        // whenever one is cleared would badge the whole library at random.
        return $cutoff = null;
    }

    try {
        $tracker = new VisitTracker(Container::instance()->get(Db::class));

        $cutoff = $tracker->markerFor(
            $user->id,
            WhatsNewPolicy::horizon($plugin->setting('horizon_days', WhatsNewPolicy::DEFAULT_HORIZON_DAYS))
        );
    } catch (Throwable $e) {
        // A decoration must not take a listing with it.
        error_log("What's new: could not work out the last visit. " . $e->getMessage());
        $cutoff = null;
    }

    return $cutoff;
};

/*
 * The badge.
 *
 * Through the `video_list` filter, so every listing in the product gets it at
 * once — the library, a category, a series, search results, a curated row —
 * rather than one hook per screen with one of them missed.
 *
 * Priority 20, late, so the set of cards is settled before anything is added
 * to it: a plugin that removed cards at the default priority would otherwise
 * have its work undone by badges attached to entries that are no longer there.
 *
 * @param list<array<string, mixed>> $cards
 * @return list<array<string, mixed>>
 */
$plugin->addFilter('video_list', static function (array $cards) use ($plugin, $markerFor): array {
    if ($cards === []) {
        return $cards;
    }

    $cutoff = $markerFor();
    if ($cutoff === null) {
        return $cards;
    }

    try {
        $tracker = new VisitTracker(Container::instance()->get(Db::class));
        $new = $tracker->newAmong(array_map(static fn (array $c): int => (int) ($c['id'] ?? 0), $cards), $cutoff);
    } catch (Throwable $e) {
        error_log("What's new: could not check what is new. " . $e->getMessage());

        return $cards;
    }

    if ($new === []) {
        return $cards;
    }

    $label = WhatsNewPolicy::label($plugin->setting('label', WhatsNewPolicy::DEFAULT_LABEL));

    foreach ($cards as $i => $card) {
        if (isset($new[(int) ($card['id'] ?? 0)])) {
            /*
             * Appended, never assigned. Another plugin may have put a badge
             * here already, and the two have nothing to do with each other.
             */
            $cards[$i]['badges'] = [...($card['badges'] ?? []), ['label' => $label, 'kind' => 'new']];
        }
    }

    return $cards;
}, 20);

$plugin->addAdminPage(
    "What's new",
    'whats-new',
    Capability::MANAGE_SETTINGS,
    static fn ($request, $params) => (new WhatsNewPage($plugin))->show($request, $params),
    position: 33
);
