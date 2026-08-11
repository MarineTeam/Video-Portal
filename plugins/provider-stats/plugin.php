<?php
/**
 * Plugin Name: Provider Statistics
 * Slug: provider-stats
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Shows what your video service counted beside what this site counted, and says what the difference between them means.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Plugins\ProviderStats\ProviderStatsPage;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/ProviderStatsReport.php';
require_once __DIR__ . '/src/ProviderStatsPage.php';

/*
 * A consumer for something that was already there.
 *
 * VideoProvider::statistics() has been on the interface since Phase 1 and
 * implemented in BunnyStreamProvider since Phase 1, and until this plugin
 * nothing in the entire codebase called it. That is the same shape as the query
 * monitor, which existed to consume the query log Db had been keeping and
 * nobody read — and the same shape as the seven mechanisms Phase 3 found with
 * repositories fully tested and no caller.
 *
 * Which is why it is a plugin rather than a core screen. It reads an optional
 * capability of the provider — not every video service will have one, and
 * `statistics()` returning zeroes is a perfectly valid implementation for a
 * self-hosted origin that counts nothing. A core screen would have to pretend
 * that is a failure. A plugin can simply be turned off.
 *
 * No tables, no settings, no cron, and no writes of any kind. Deactivating it
 * costs nothing and uninstalling it removes nothing, because it never stored
 * anything to remove.
 */

$plugin->addAdminPage(
    'Provider stats',
    'provider-stats',
    /*
     * VIEW_ANALYTICS rather than MANAGE_PLUGINS, which is what the other
     * bundled plugins use for their screens. Those are settings pages and this
     * is a report: the person who should see it is whoever is allowed to see
     * the analytics screen, not whoever is allowed to install software.
     */
    Capability::VIEW_ANALYTICS,
    static fn ($request, $params) => (new ProviderStatsPage())->show($request, $params),
    position: 70
);
