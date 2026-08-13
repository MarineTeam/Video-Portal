<?php
/**
 * Plugin Name: Most watched
 * Slug: popular
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: A homepage row of the videos people actually watched.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Container;
use Portal\Content\VideoPresenter;
use Portal\Content\VideoRepository;
use Portal\Content\ViewRepository;
use Portal\Plugins\Popular\PopularPage;
use Portal\Plugins\Popular\PopularPolicy;
use Portal\Plugins\Popular\PopularRow;
use Portal\Video\VideoProvider;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/PopularPolicy.php';
require_once __DIR__ . '/src/PopularRow.php';
require_once __DIR__ . '/src/PopularPage.php';

/*
 * NO TABLE, and no counter of its own.
 *
 * {video_views} has been filled by core since the analytics screen shipped, so
 * this plugin has nothing to record — it reads a number somebody else is
 * already keeping. That is also why uninstalling it drops nothing: there is
 * nothing here that would be lost.
 */

/*
 * The row, added to the homepage.
 *
 * Through the `home_rows` filter rather than by echoing markup at
 * `before_video_list`, for two reasons. The filter fires only on the front page
 * — not on page two, not over somebody's search results — which is a gate the
 * controller already applies to the curated rows and which a plugin would
 * otherwise have to re-derive. And the row comes back as data, so the active
 * theme renders it with its own card partial: a site running a different theme
 * gets its own design rather than this plugin's idea of one.
 *
 * @param list<array{title: string, url: ?string, videos: list<array<string, mixed>>}> $rows
 * @return list<array{title: string, url: ?string, videos: list<array<string, mixed>>}>
 */
$plugin->addFilter('home_rows', static function (array $rows) use ($plugin): array {
    try {
        $container = Container::instance();

        $days = PopularPolicy::days($plugin->setting('days', PopularPolicy::DEFAULT_DAYS));
        $count = PopularPolicy::count($plugin->setting('count', PopularPolicy::DEFAULT_COUNT));

        /*
         * The same two questions the library asks, asked the same way: can this
         * person play anything, and what does the site do about artwork by
         * default. Everything else — published, scheduled, hidden — is decided
         * inside the listing query, which is the point.
         */
        $user = $plugin->user();
        $mayWatch = $user !== null && ($user->isAdmin() || $user->authorized);

        $videos = (new PopularRow(
            $container->get(ViewRepository::class),
            $container->get(VideoRepository::class),
        ))->forViewer($days, $count, ['includeMemberOnly' => $mayWatch]);

        if (!PopularPolicy::worthShowing(count($videos))) {
            return $rows;
        }

        $presenter = new VideoPresenter(
            $container->get(VideoRepository::class),
            $container->get(VideoProvider::class),
        );

        $row = [
            'title'  => PopularPolicy::title($plugin->setting('title', PopularPolicy::DEFAULT_TITLE)),
            'url'    => null,
            'videos' => $presenter->cards(
                $videos,
                $mayWatch,
                $plugin->config()->settingBool('members_thumbnail_default', false)
            ),
        ];
    } catch (Throwable $e) {
        // A row that cannot be built is a row that is not there. The homepage
        // matters more than the decoration on it.
        error_log('Most watched: could not build the row. ' . $e->getMessage());

        return $rows;
    }

    return PopularPolicy::position($plugin->setting('position', PopularPolicy::FIRST)) === PopularPolicy::LAST
        ? [...$rows, $row]
        : [$row, ...$rows];
});

$plugin->addAdminPage(
    'Most watched',
    'popular',
    Capability::MANAGE_SETTINGS,
    static fn ($request, $params) => (new PopularPage($plugin))->show($request, $params),
    position: 34
);
