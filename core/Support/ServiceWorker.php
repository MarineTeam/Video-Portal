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
    public const VERSION = '2';

    public const CACHE = 'portal-shell-v' . self::VERSION;

    /** The one page precached, and the only thing served from cache. */
    public const OFFLINE_URL = '/offline';

    /**
     * Saved videos, in a cache of their own.
     *
     * Separate from the shell cache and deliberately NOT swept on activate. A
     * shell cache is disposable — it can be rebuilt from the network in a
     * second. These are hundreds of megabytes somebody chose to keep, quite
     * possibly on a metered connection, and throwing them away because the
     * worker was updated would be the single most expensive mistake this file
     * could make.
     */
    public const VIDEO_CACHE = 'portal-offline-videos-v1';

    /**
     * The path a saved video is served from.
     *
     * Never reaches the network: nothing on the server answers this. It exists
     * so a `<video>` element has a same-origin URL to point at, which is what
     * makes seeking work — the worker answers the Range requests itself.
     */
    public const VIDEO_PREFIX = '/offline-video/';

    /**
     * Build the script.
     *
     * WHY THE RANGE HANDLER STREAMS
     *
     * `response.arrayBuffer()` would be three lines shorter and allocates the
     * whole file — several hundred megabytes for a sermon, on the phone where
     * this feature is actually used. Seeking far into a long video instead
     * costs a pass through the cached bytes; that is local I/O, and it is the
     * right thing to spend rather than memory.
     *
     * Written here rather than in the generated script, because every comment
     * in that heredoc is shipped to every browser on every worker fetch — and
     * because a test asserts the shipped script never mentions the method it
     * must not use, which prose naming it would defeat.
     *
     * @param string $extra whatever plugins appended, already JavaScript
     */
    public static function script(string $extra = ''): string
    {
        $cache = self::CACHE;
        $offline = self::OFFLINE_URL;
        $videoCache = self::VIDEO_CACHE;
        $videoPrefix = self::VIDEO_PREFIX;

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
        var VIDEO_CACHE = '{$videoCache}';
        var VIDEO_PREFIX = '{$videoPrefix}';

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

        /*
         * Serve one saved video, honouring Range.
         *
         * THE RANGE HANDLING IS THE WHOLE FEATURE. A media element does not
         * fetch a file; it asks for byte ranges, and it asks for a new one
         * every time somebody drags the scrubber. Answering a range request
         * with the entire body and a 200 makes Safari refuse the file outright
         * and leaves Chrome unable to seek — so a "saved" video plays from the
         * start, once, and cannot be moved through. That is the difference
         * between a download and offline playback.
         *
         * The body is STREAMED and discarded up to the start offset rather than
         * read into memory and sliced — see the note on this method in the PHP
         * source, which does not ship to every browser the way this comment
         * does.
         */
        function serveRange(cached, rangeHeader) {
          var total = Number(cached.headers.get('Content-Length') || 0);
          var type = cached.headers.get('Content-Type') || 'video/mp4';

          var match = /bytes=(\d*)-(\d*)/.exec(rangeHeader || '');
          var start = match && match[1] !== '' ? parseInt(match[1], 10) : 0;
          var end = match && match[2] !== '' ? parseInt(match[2], 10) : (total > 0 ? total - 1 : 0);

          if (!total || start >= total) {
            /*
             * A range nothing can satisfy gets 416 with the real length, which
             * is what tells the player to ask again sensibly. Returning 200
             * here makes it retry the same impossible range forever.
             */
            return new Response(null, {
              status: 416,
              headers: { 'Content-Range': 'bytes */' + (total || 0) }
            });
          }

          if (end >= total) {
            end = total - 1;
          }

          var wanted = end - start + 1;
          var reader = cached.body.getReader();
          var seen = 0;
          var sent = 0;

          var stream = new ReadableStream({
            /*
             * PULL MUST NOT RESOLVE WITHOUT ENQUEUEING SOMETHING.
             *
             * A stream calls pull() again only when it is asked for more data.
             * A pull that reads a chunk, decides the chunk is entirely before
             * the range, and simply returns has satisfied nobody and provoked
             * nothing — the pending read stays pending and the response hangs
             * forever.
             *
             * So the skipping happens INSIDE one pull, looping until it has
             * bytes to hand over or the body runs out. The recursion is
             * promise-chained rather than synchronous, so a long skip costs
             * turns of the event loop and not stack.
             *
             * Found in a browser, not in a test: every assertion that can be
             * written against the text of this file passed while a seek into
             * any file delivered in more than one chunk never returned. The
             * first byte of a video arrives in the opening chunk, so playback
             * from the start worked perfectly — it was only seeking that hung.
             */
            pull: function (controller) {
              function step() {
                return reader.read().then(function (result) {
                  if (result.done) {
                    controller.close();
                    return undefined;
                  }

                  var chunk = result.value;
                  var chunkStart = seen;
                  seen += chunk.byteLength;

                  // Entirely before the range: discard and keep reading
                  // within this same pull.
                  if (seen <= start) {
                    return step();
                  }

                  var from = Math.max(0, start - chunkStart);
                  var to = Math.min(chunk.byteLength, from + (wanted - sent));

                  if (to <= from) {
                    return step();
                  }

                  controller.enqueue(chunk.subarray(from, to));
                  sent += to - from;

                  if (sent >= wanted) {
                    controller.close();
                    reader.cancel();
                  }

                  return undefined;
                });
              }

              return step();
            },
            cancel: function () {
              // The player seeked away or the tab closed. Let go of the cached
              // response rather than reading the rest of it for nobody.
              reader.cancel();
            }
          });

          return new Response(stream, {
            status: 206,
            headers: {
              'Content-Type': type,
              'Content-Length': String(wanted),
              'Content-Range': 'bytes ' + start + '-' + end + '/' + total,
              'Accept-Ranges': 'bytes',
              'Cache-Control': 'no-store'
            }
          });
        }

        self.addEventListener('fetch', function (event) {
          var request = event.request;

          if (request.method !== 'GET') {
            return;
          }

          /*
           * A saved video. Answered entirely from the device — there is no
           * server route behind this path, so if it is not in the cache the
           * honest answer is 404 rather than a request that hangs.
           */
          if (new URL(request.url).pathname.indexOf(VIDEO_PREFIX) === 0) {
            event.respondWith(
              caches.open(VIDEO_CACHE).then(function (cache) {
                return cache.match(request.url).then(function (cached) {
                  if (!cached) {
                    return new Response('Not saved on this device.', {
                      status: 404,
                      headers: { 'Content-Type': 'text/plain' }
                    });
                  }

                  var range = request.headers.get('range');
                  if (range) {
                    return serveRange(cached, range);
                  }

                  /*
                   * No Range asked for. Accept-Ranges is what tells the player
                   * it MAY ask, and without it Chrome will not offer a
                   * scrubber at all on some files.
                   */
                  var headers = new Headers(cached.headers);
                  headers.set('Accept-Ranges', 'bytes');
                  headers.set('Cache-Control', 'no-store');

                  return new Response(cached.body, { status: 200, headers: headers });
                });
              })
            );
            return;
          }

          /*
           * Navigations only beyond this point. Everything else — assets, API
           * calls, form posts — goes straight to the network untouched, so
           * this worker cannot change what any of them returns.
           */
          if (request.mode !== 'navigate') {
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
