<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Noticing that the deployed code has changed.
 *
 * Deploying here is `git pull`, which replaces files under a server that is
 * already running. PHP's OPcache revalidates each file against its own
 * modification time on a timer — commonly every 60 seconds on shared hosting —
 * and it does so per file, independently. For the length of that window the
 * server can be executing a MIXTURE of the old release and the new one.
 *
 * That is not a theoretical problem for this project. A corrected upload.js was
 * deployed three times and never ran, because the browser held the old copy;
 * this is the same failure one layer down, where the stale copy is bytecode
 * rather than a script. Both present identically: the file on disk is right,
 * the thing actually executing is not, and every check of the source says the
 * fix is in place.
 *
 * The stamp is a hash of the modification times of a few files that a real
 * deploy touches. When it differs from the one recorded on the last request,
 * the code has changed since then and the cache is worth clearing.
 *
 * What this cannot do, stated plainly: if the file containing this check is
 * itself stale, the check does not run. So it does not eliminate the mixed
 * window — it collapses it. Instead of waiting for every file to revalidate on
 * its own schedule, the first request that runs the new App.php clears the lot.
 */
final class DeployStamp
{
    /**
     * Files whose modification time moves when the deployed code does.
     *
     * `vendor/composer/installed.php` is rewritten by the release build every
     * time, so it changes on every release even when no core file did.
     * `core/App.php` covers the case of a file edited straight onto the server
     * over FTP, which is a thing that happens on hosts with no shell.
     *
     * Deliberately a short fixed list rather than a scan. This runs on every
     * request, and a directory walk to answer a question whose answer is "no"
     * a million times in a row is the kind of cost that does not show up until
     * the site is busy.
     */
    public const SENTINELS = [
        'vendor/composer/installed.php',
        'core/App.php',
    ];

    /**
     * A stamp for the tree rooted at $root.
     *
     * A missing file contributes a fixed marker rather than being skipped, so
     * that a file APPEARING is itself a change — which is what an upgrade that
     * adds a vendored dependency looks like.
     *
     * @param list<string> $relative
     */
    public static function of(string $root, array $relative = self::SENTINELS): string
    {
        $parts = [];

        foreach ($relative as $path) {
            $full = rtrim($root, '/\\') . '/' . ltrim($path, '/\\');
            $mtime = @filemtime($full);
            $parts[] = $path . ':' . ($mtime === false ? 'absent' : (string) $mtime);
        }

        // Hashed rather than stored raw: it goes in a settings column, and a
        // list of paths and timestamps is a description of the deployment that
        // nothing needs to be able to read back.
        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }

    /**
     * Has the code changed since the stamp we last recorded?
     *
     * A missing stored stamp is NOT a change. That is the state of every
     * install that has never seen this code, and treating it as a change would
     * make the very first request after this feature ships reset the cache —
     * which is harmless once and confusing forever, because it would look like
     * a deploy happened when none did.
     */
    public static function changed(?string $stored, string $current): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        return !hash_equals($stored, $current);
    }
}
