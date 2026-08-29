<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * The site's one service worker.
 *
 * ONE, deliberately. A scope may have exactly one active worker, so registering
 * a second script at `/` silently replaces the first — and the replacement
 * looks like a success in every log. The push plugin had `/push-sw.js`
 * registered at `/` and would have stopped receiving anything the moment
 * anything else registered a worker; it now contributes to this one through
 * the `service_worker` filter instead.
 *
 * The caching rules here are deliberately timid, because a service worker is
 * the one piece of this product that can serve one person's page to another.
 */
final class ServiceWorker
{
    /**
     * Bumped when the worker's own behaviour changes.
     *
     * The cache name contains it, so `activate` can delete everything from an
     * older version. It is not a release version — a worker that has not
     * changed should not throw away a cache on every deploy.
     */
    public const VERSION = '1';

    public const CACHE = 'portal-shell-v' . self::VERSION;

    /** The one page precached, and the only thing served from cache. */
    public const OFFLINE_URL = '/offline';

    /**
     * Build the script.
     *
     * @param string $extra whatever plugins appended, already JavaScript
     */
    public static function script(string $extra = ''): string
    {
        $cache = self::CACHE;
        $offline = self::OFFLINE_URL;

        $core = <<<JS
        /*
         * The service worker for this site.
         *
         * NOTHING DYNAMIC IS EVER CACHED. Every page here can be personalised
         * or access-gated: a members-only video page, an admin screen, a share
         * link's player. Caching any of them risks handing one person's page to
         * the next visitor on a shared machine, or serving a page after the
         * access behind it was revoked. The site is worth less offline than it
         * is worth wrong.
         *
         * So exactly one page is cached — an offline notice that contains no
         * data and needs no session — and it is served only when the network
         * has already failed.
         */
        var CACHE = '{$cache}';
        var OFFLINE = '{$offline}';

        self.addEventListener('install', function (event) {
          // Take over as soon as this worker is ready rather than waiting for
          // every tab to close, so a fix to the worker reaches people.
          self.skipWaiting();

          event.waitUntil(
            caches.open(CACHE).then(function (cache) {
              return cache.add(new Request(OFFLINE, { cache: 'reload' }));
            }).catch(function () {
              // A failed precache must not fail the install. The worker is
              // still useful for anything a plugin added to it.
            })
          );
        });

        self.addEventListener('activate', function (event) {
          event.waitUntil(
            caches.keys().then(function (names) {
              return Promise.all(names.map(function (name) {
                /*
                 * Only this worker's own shell caches are swept. Anything a
                 * plugin or a later feature keeps under its own name is left
                 * alone — a saved video should not vanish because the worker
                 * was updated.
                 */
                if (name !== CACHE && name.indexOf('portal-shell-') === 0) {
                  return caches.delete(name);
                }
                return undefined;
              }));
            }).then(function () {
              return self.clients.claim();
            })
          );
        });

        self.addEventListener('fetch', function (event) {
          var request = event.request;

          /*
           * Navigations only, and only GET. Everything else — assets, API
           * calls, form posts — goes straight to the network untouched, so
           * this worker cannot change what any of them returns.
           */
          if (request.method !== 'GET' || request.mode !== 'navigate') {
            return;
          }

          event.respondWith(
            fetch(request).catch(function () {
              // The network failed. This is the only moment anything is served
              // from cache, and the only thing in it is the offline notice.
              return caches.match(OFFLINE).then(function (cached) {
                return cached || Response.error();
              });
            })
          );
        });
        JS;

        $extra = trim($extra);

        if ($extra === '') {
            return $core . "\n";
        }

        return $core . "\n\n/* ---- added by plugins ---- */\n" . $extra . "\n";
    }
}
