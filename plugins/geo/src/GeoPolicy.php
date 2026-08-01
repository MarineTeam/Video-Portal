<?php

declare(strict_types=1);

namespace Portal\Plugins\Geo;

/**
 * Who may reach this site from where.
 *
 * Pure, and every rule in it bends toward letting the request through. That is
 * not timidity — it is the only defensible design for a feature whose failure
 * mode is locking the site's owner out of the screen that would switch it off,
 * on a host where they have no shell and no way back in.
 *
 * Four separate things each independently allow a request:
 *
 *   1. The feature is switched off.
 *   2. The country list is empty. An empty list means "no restriction", never
 *      "block everyone" — the opposite reading turns switching the plugin on
 *      before filling in the list into an instant self-lockout.
 *   3. The country is unknown. Most shared hosts send no country header at all,
 *      so failing closed here would block one hundred percent of traffic on a
 *      typical install.
 *   4. The path is one that must never be blocked (see exemptPath).
 *
 * The country lists themselves live in config.php and are never editable from
 * the database, so recovery from a mistake is always possible over FTP.
 */
final class GeoPolicy
{
    public const ALLOW  = 'allow';
    public const BLOCK  = 'block';
    public const BLOCK_ADMIN = 'block_admin';

    /**
     * Decide one request.
     *
     * @param string      $path          request path
     * @param string      $country       ISO-3166-1 alpha-2, or '' if unknown
     * @param string|null $viewerEmail   the signed-in address, if any
     * @param array{
     *     viewersEnabled: bool,
     *     adminEnabled: bool,
     *     viewerCountries: list<string>,
     *     adminCountries: list<string>,
     *     bypassEmails: list<string>
     * } $rules
     */
    public static function decide(string $path, string $country, ?string $viewerEmail, array $rules): string
    {
        if (self::isExemptPath($path)) {
            return self::ALLOW;
        }

        // An unknown country cannot be measured against any list. Deciding it
        // is "not in the list" would block every visitor on every host that
        // does not provide the header.
        if ($country === '') {
            return self::ALLOW;
        }

        // Someone named in the bypass list is never geo-blocked anywhere. The
        // alternative — bypassing only the admin area — produces a site an
        // admin can administer but cannot look at, which reads as a bug.
        if (self::isBypassed($viewerEmail, $rules['bypassEmails'])) {
            return self::ALLOW;
        }

        if (self::isAdminPath($path)) {
            // The admin area is governed ONLY by the admin list. This is what
            // guarantees that switching on viewer geo-blocking can never, by
            // itself, lock an administrator out of the screen that turns it
            // back off.
            if (!$rules['adminEnabled'] || $rules['adminCountries'] === []) {
                return self::ALLOW;
            }

            return in_array($country, $rules['adminCountries'], true)
                ? self::ALLOW
                : self::BLOCK_ADMIN;
        }

        if (!$rules['viewersEnabled'] || $rules['viewerCountries'] === []) {
            return self::ALLOW;
        }

        return in_array($country, $rules['viewerCountries'], true)
            ? self::ALLOW
            : self::BLOCK;
    }

    /**
     * Paths that are never blocked, whatever the lists say.
     *
     * Each one is here for a specific reason:
     *
     *   /auth/*  Being in the bypass list is a property of an EMAIL ADDRESS,
     *            and we only learn an address once someone signs in. Blocking
     *            sign-in would make the bypass list unusable by exactly the
     *            people it exists for.
     *   /cron    Authenticated by a secret, and called by the host's scheduler
     *            from wherever that scheduler happens to run.
     *   /assets  Static files. Blocking them would strip the styling off the
     *            block page itself.
     */
    public static function isExemptPath(string $path): bool
    {
        foreach (['/auth', '/assets', '/theme-asset', '/plugin-asset'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return $path === '/cron';
    }

    /**
     * Is this the admin area?
     *
     * Compared as a whole segment. A plain str_starts_with('/admin') would also
     * match a future /administrators page and quietly govern it by the wrong
     * list.
     */
    public static function isAdminPath(string $path): bool
    {
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    /** @param list<string> $bypassEmails */
    public static function isBypassed(?string $viewerEmail, array $bypassEmails): bool
    {
        if ($viewerEmail === null) {
            return false;
        }

        $viewer = strtolower(trim($viewerEmail));
        if ($viewer === '') {
            return false;
        }

        foreach ($bypassEmails as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            if ($allowed[0] === '@') {
                if (str_ends_with($viewer, $allowed)) {
                    return true;
                }
                continue;
            }

            if ($viewer === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the block decision need to know who is signed in?
     *
     * Looking up the current user costs a query, and a global middleware runs
     * on every request including ones that end in a 404. This lets the caller
     * skip that lookup entirely whenever nothing could be blocked anyway.
     *
     * @param array{viewersEnabled: bool, adminEnabled: bool, viewerCountries: list<string>, adminCountries: list<string>, bypassEmails: list<string>} $rules
     */
    public static function couldBlock(string $path, string $country, array $rules): bool
    {
        if ($country === '' || self::isExemptPath($path)) {
            return false;
        }

        if (self::isAdminPath($path)) {
            return $rules['adminEnabled'] && $rules['adminCountries'] !== [];
        }

        return $rules['viewersEnabled'] && $rules['viewerCountries'] !== [];
    }
}
