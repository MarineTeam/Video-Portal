<?php

declare(strict_types=1);

namespace Portal\Plugins\Geo;

use Portal\Container;
use Portal\Db;
use Portal\Support\Audit;
use Portal\Support\RateLimit;
use Throwable;

/**
 * Recording blocked requests.
 *
 * A class rather than a function in plugin.php: plugin files are `require`d,
 * not `require_once`d, so a top-level `function` in one is a fatal redeclare
 * waiting for the first time a plugin is loaded twice in a process. Classes are
 * guarded by the same rule but fail the same way, so plugin code that must be
 * reusable belongs in a file the plugin includes with require_once — which is
 * exactly what this is.
 */
final class GeoAudit
{
    /**
     * Record an admin-area block, and only that.
     *
     * Viewer blocks are deliberately never recorded. On a site that restricts
     * by country they are routine and high-volume, and they would bury
     * everything else in the audit log. An admin-area block is rare and always
     * worth seeing: it is either an attempt on the admin area or the owner
     * locked out of their own site, and somebody needs to know about both.
     *
     * Rate-limited per country so a persistent prober cannot flood the log
     * either — six an hour is enough to notice a pattern without recording
     * every request in it.
     */
    public static function adminBlocked(string $country, ?string $email, string $ip): void
    {
        try {
            /** @var Db $db */
            $db = Container::instance()->get(Db::class);

            if (!(new RateLimit($db))->allow('geo-admin-block:' . $country, 6, 3600)) {
                return;
            }

            Audit::log(
                $db,
                $email,
                'geo.admin_blocked',
                'country',
                $country,
                'Admin area refused for a request from ' . $country,
                $ip
            );
        } catch (Throwable $e) {
            // Never let logging a refusal turn into a failure to refuse.
            error_log('Geo: could not record an admin block. ' . $e->getMessage());
        }
    }
}
