<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Who still gets through while the site is closed for work.
 *
 * Deployment here is `git pull` on a live host, and the pending migrations run
 * on the first request that arrives afterwards — whichever unlucky visitor that
 * happens to be. Twenty seconds of a notice is worth a great deal more than
 * twenty seconds of a half-applied schema being read by the public.
 *
 * PURE. No database, no session, no request object: the decision is a function
 * of a path and two booleans, so every branch can be tested directly rather
 * than staged. The middleware that calls it does the looking-up.
 *
 * The standing rule of this codebase applies here more than anywhere:
 * restricting the site must never, on its own, close the screen that would
 * undo it. Three separate things guarantee that below, and each has a test.
 */
final class MaintenancePolicy
{
    /**
     * Paths that are never closed, whatever the switch says.
     *
     * Sign-in above all. The rule chosen for this feature is "admins get
     * through", which is a fact about a SESSION — so somebody arriving from a
     * different browser, or after their session expired, has to be able to sign
     * in and become an admin. Closing /auth would make the switch a one-way
     * door: turn it on, close your laptop, and the only way back is FTP.
     *
     * Assets, because a notice page that cannot load its own stylesheet reads
     * as a broken site rather than a deliberate one.
     *
     * Cron, because scheduled work must not stop for a deploy — and because the
     * cron endpoint is authenticated by a secret, so it is not public anyway.
     */
    private const OPEN_PREFIXES = ['/auth', '/assets', '/theme-asset', '/plugin-asset', '/cron'];

    /**
     * How long to tell a client to wait, in seconds.
     *
     * Sent as Retry-After beside a 503. The number is a guess and says so; what
     * matters is that it is SHORT, because the honest expectation for a `git
     * pull` deploy is under a minute. A long value invites a crawler to stay
     * away for hours after a thirty-second deploy.
     */
    public const RETRY_AFTER = 120;

    /**
     * May this request proceed?
     *
     * @param bool $isAdmin whether the viewer holds any admin capability — the
     *                      caller resolves this, because doing it here would
     *                      mean a session lookup on every asset request
     */
    public static function allows(string $path, bool $enabled, bool $isAdmin): bool
    {
        if (!$enabled) {
            return true;
        }

        if (self::isAlwaysOpen($path)) {
            return true;
        }

        /*
         * An admin sees the site exactly as it is, including the front end.
         *
         * Letting them into /admin only would produce a site they can
         * administer and cannot look at — so they would have no way to check
         * whether the thing they just deployed actually works, which is the
         * entire reason they are here during a deploy.
         */
        return $isAdmin;
    }

    /**
     * Is this a path the switch never touches?
     *
     * Public because the middleware asks it FIRST, before resolving a session:
     * an asset request must not cost a session lookup, and this runs on every
     * request the site serves.
     */
    public static function isAlwaysOpen(string $path): bool
    {
        foreach (self::OPEN_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        /*
         * The admin area itself is never closed by this switch.
         *
         * Belt and braces beside the $isAdmin rule above, and deliberately so.
         * If the capability lookup ever fails — a database hiccup, a session
         * that will not load — `$isAdmin` comes back false and an administrator
         * would be shown the notice on the very screen that turns it off. This
         * makes that impossible. Every /admin route still enforces its own
         * capability, so this opens a door, not a room.
         */
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    /**
     * The message to show, falling back to something that says enough.
     *
     * A blank custom message must not produce a blank page — that reads as the
     * site being broken, which is the one impression this feature exists to
     * avoid.
     */
    public static function message(string $custom): string
    {
        $custom = trim($custom);

        return $custom === ''
            ? 'We are making a few changes and will be back shortly.'
            : $custom;
    }
}
