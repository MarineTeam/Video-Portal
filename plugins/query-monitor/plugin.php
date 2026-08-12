<?php
/**
 * Plugin Name: Query Monitor
 * Slug: query-monitor
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Shows the database queries and outbound requests one page actually made, and names the ones that repeated.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Guard;
use Portal\Container;
use Portal\Db;
use Portal\Plugins\QueryMonitor\QueryMonitorBar;
use Portal\Support\Http;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/QueryReport.php';
require_once __DIR__ . '/src/QueryMonitorBar.php';

/*
 * This plugin has no tables and reads nothing it did not already have.
 *
 * Db has counted and logged every statement since Phase 1, and Http has timed
 * every outbound call since it was written, and until now nothing anywhere
 * looked at either. The whole of this plugin is a consumer for logs that were
 * already being kept — which also means turning it off costs nothing, because
 * the logging happens regardless.
 */

/*
 * The capability it checks — its own, so it can be granted without granting
 * anything else.
 *
 * A variable rather than a const on purpose. A plugin file is require'd by the
 * loader rather than autoloaded, and a top-level const that ever gets included
 * twice is a fatal error inside a plugin whose whole job is to be harmless.
 */
$capability = 'view_query_monitor';

/*
 * Registered at activation, not on every request.
 *
 * addCapability writes a row, and doing that on each page load would put an
 * INSERT in front of every request made by a plugin whose entire purpose is
 * counting how many there are.
 */
$plugin->addAction('plugin_activated', static function (string $slug) use ($plugin, $capability): void {
    if ($slug !== 'query-monitor') {
        return;
    }

    $plugin->addCapability(
        $capability,
        'See the query monitor panel on every page.'
    );
});

/**
 * May the person making this request see the panel?
 *
 * Fails closed on every uncertainty, including its own errors. The panel prints
 * the SQL a page ran, and while none of that SQL carries values — Db logs the
 * prepared statement and never the bound parameters — the statements themselves
 * still describe the shape of the database to anybody reading.
 *
 * An administrator always passes, because can() short-circuits on the admin
 * role. That is deliberate: whoever installed this should not then have to
 * grant themselves permission to see it.
 */
$allowed = static function () use ($capability): bool {
    try {
        $guard = Container::instance()->get(Guard::class);

        return $guard->user() !== null && $guard->can($capability);
    } catch (Throwable $e) {
        error_log('Query Monitor: could not resolve the viewer, so the panel is hidden. ' . $e->getMessage());

        return false;
    }
};

/**
 * Draw the bar.
 *
 * Wrapped whole. A diagnostic that can take down the page it is diagnosing is
 * worse than no diagnostic — and this renders on every page a permitted user
 * loads, including the ones they are loading BECAUSE something is broken.
 */
$render = static function () use ($allowed): void {
    if (!$allowed()) {
        return;
    }

    try {
        $db = Container::instance()->get(Db::class);

        echo QueryMonitorBar::render(
            log:             $db->log(),
            // The counters rather than count($log): the log stops at 500 and
            // the counters do not, so a page issuing a thousand statements —
            // precisely the page worth catching — would otherwise report 500.
            totalQueries:    $db->queryCount(),
            totalMs:         $db->queryMs(),
            httpLog:         Http::log(),
            // As with queryCount above: Http::log() stops at 200 entries and
            // the counter does not.
            totalHttpCalls:  Http::callCount(),
            httpMs:          Http::totalMs(),
            /*
             * Measured from PHP's own start rather than from anything this
             * plugin set up, so it includes bootstrap, migrations, and plugin
             * loading — the parts a page-level timer started later would miss,
             * and the parts most likely to be the surprise.
             */
            requestMs:       (microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000,
            peakMemoryBytes: memory_get_peak_usage(true),
        );
    } catch (Throwable $e) {
        error_log('Query Monitor: could not render the panel. ' . $e->getMessage());
    }
};

/*
 * Both footers.
 *
 * The public one has existed since Phase 1. The admin one did not exist at all
 * until this plugin needed it — the admin layout had no do_action anywhere, so
 * a plugin had no way to say anything on an admin request. Admin screens are
 * the heaviest pages here, which made them the ones a query monitor most needed
 * to reach.
 */
$plugin->addAction('footer', $render);
$plugin->addAction('admin_footer', $render);
