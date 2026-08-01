<?php
/**
 * Plugin Name: Country restrictions
 * Slug: geo
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Limits who can reach the site, and the admin area, by country. The country lists live in config.php so a mistake is always recoverable.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Auth\Guard;
use Portal\Container;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\Geo\GeoAudit;
use Portal\Plugins\Geo\GeoPage;
use Portal\Plugins\Geo\GeoPolicy;
use Portal\Plugins\Geo\GeoView;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/GeoPolicy.php';
require_once __DIR__ . '/src/GeoView.php';
require_once __DIR__ . '/src/GeoAudit.php';
require_once __DIR__ . '/src/GeoPage.php';

/**
 * The toggles come from the database; the country lists never do.
 *
 * That split is the whole safety design. Turning the feature off has to be
 * possible from the admin screen, but *whitelisting the wrong country* is the
 * mistake that locks you out of that screen — so the lists themselves are only
 * editable in config.php, which is reachable over FTP on a host with no shell.
 *
 * @return array{viewersEnabled: bool, adminEnabled: bool, viewerCountries: list<string>, adminCountries: list<string>, bypassEmails: list<string>}
 */
$rules = static function () use ($plugin): array {
    $config = $plugin->config();

    return [
        'viewersEnabled'  => $config->settingBool('geo_enabled', false),
        'adminEnabled'    => $config->settingBool('admin_geo_enabled', false),
        'viewerCountries' => $config->csv('geo_whitelist'),
        'adminCountries'  => $config->csv('admin_geo_whitelist'),
        // csv() uppercases, which is right for country codes and harmless for
        // addresses — isBypassed lowercases both sides before comparing.
        'bypassEmails'    => $config->csv('admin_geo_bypass_emails'),
    ];
};

$plugin->addGlobalMiddleware('check', static function (Request $request) use ($rules): ?Response {
    try {
        $current = $rules();
        $country = $request->country();

        // Cheap gate first. This runs on every request, including ones that
        // will 404, so the common case — nothing enabled, or a host that sends
        // no country header — must not cost a database lookup.
        if (!GeoPolicy::couldBlock($request->path, $country, $current)) {
            return null;
        }

        $email = null;
        try {
            $email = Container::instance()->get(Guard::class)->user()?->email;
        } catch (Throwable) {
            // Nobody signed in, or the session is unavailable. Treated as an
            // anonymous request rather than as an error.
        }

        $decision = GeoPolicy::decide($request->path, $country, $email, $current);

        if ($decision === GeoPolicy::ALLOW) {
            return null;
        }

        if ($decision === GeoPolicy::BLOCK_ADMIN) {
            GeoAudit::adminBlocked($country, $email, $request->ip());
            return Response::html(GeoView::blockedAdmin($country), 403)->private();
        }

        return Response::html(GeoView::blocked(), 403)->private();
    } catch (Throwable $e) {
        // A throwing global middleware would take down every page on the site,
        // and this one guards the admin screen that could disable it. Letting
        // the request through is the only recoverable failure.
        error_log('Geo: check failed, allowing the request. ' . $e->getMessage());
        return null;
    }
});

$plugin->addAdminPage(
    'Countries',
    'geo',
    Capability::MANAGE_SETTINGS,
    static fn ($request, $params) => (new GeoPage($plugin))->show($request, $params),
    position: 70
);
