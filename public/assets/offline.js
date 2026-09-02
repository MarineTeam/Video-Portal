/*
 * Saving a video to this device, and managing what is saved.
 *
 * THE ONE FEATURE HERE THAT GENUINELY NEEDS JAVASCRIPT. Everything else in
 * this product works without it — the admin menu opens on a checkbox, ratings
 * are five submit buttons, comments need one small script for reply targeting
 * and lose only nesting without it. This cannot be done that way: Cache
 * Storage has no HTML equivalent, and neither does asking the browser how much
 * room is left.
 *
 * WHAT THE SERVER KNOWS: that a signed URL was handed out, which the audit log
 * records. It does not know what is saved on this device, and nothing here
 * tells it. That is deliberate and it has a cost worth being honest about —
 * the list cannot be seen from another browser, cannot be restored after
 * clearing site data, and cannot be shown on a screen the server renders. The
 * alternative is a table on the server describing the contents of people's
 * phones, which is a worse thing to have than a list that does not survive a
 * reinstall.
 */
(function () {
  'use strict';

  var CACHE = 'portal-offline-videos-v1';

  /*
   * Metadata rides in the same cache as the file, under a sibling key.
   *
   * No IndexedDB. Two stores means two things that can disagree — a file with
   * no metadata is unlistable, and metadata with no file is a row that offers
   * to play something that is not there. One store, and `cache.keys()` is the
   * whole listing.
   */
  function metaKey(cacheKey) {
    return cacheKey.replace(/\.mp4$/, '.json');
  }

  function supported() {
    return 'caches' in window && 'serviceWorker' in navigator;
  }

  /* ------------------------------------------------------------- saving */

  /**
   * Fetch the file and put it in the cache, reporting progress as it goes.
   *
   * Streamed rather than `await response.blob()`, for two reasons. A sermon is
   * several hundred megabytes and buffering it whole is how a phone runs out
   * of memory; and a silent wait of several minutes with no feedback reads as
   * a broken button, so people press it again.
   */
  function save(slug, onProgress) {
    return fetch('/download/' + encodeURIComponent(slug) + '.json', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) {
        return response.text().then(function (body) {
          /*
           * The server's own words. Four different situations produce a
           * missing MP4 and each needs a different fix; replacing that with
           * "download failed" is how somebody ends up looking in the wrong
           * place for an afternoon.
           */
          var message = 'This video cannot be saved.';
          try {
            var parsed = JSON.parse(body);
            if (parsed && parsed.error) { message = parsed.error; }
          } catch (e) {
            if (body && body.length < 300) { message = body; }
          }
          throw new Error(message);
        });
      }

      return response.json();
    }).then(function (meta) {
      return fetch(meta.url).catch(function () {
        /*
         * THE PRECONDITION THAT IS NOT OBVIOUS.
         *
         * The file is on the video service's own domain, so reading its bytes
         * needs a CORS header from the pull zone. Without one the browser
         * refuses, and the error it gives is the same generic network failure
         * as a flat tyre — so it is named here rather than reported as "check
         * your connection", which sends people to restart their router over a
         * setting in a dashboard.
         */
        throw new Error(
          'The video service refused to hand over the file. This usually means the '
          + 'pull zone is not sending CORS headers — an administrator can enable them '
          + 'in the bunny.net dashboard.'
        );
      }).then(function (fileResponse) {
        if (!fileResponse.ok) {
          throw new Error('The video service answered ' + fileResponse.status + '.');
        }

        var total = Number(fileResponse.headers.get('Content-Length') || 0);
        var loaded = 0;

        /*
         * Content-Length is what the worker slices ranges against later, so it
         * has to survive into the cache. A response assembled from a stream
         * carries only the headers given to it here.
         */
        var headers = new Headers();
        headers.set('Content-Type', fileResponse.headers.get('Content-Type') || 'video/mp4');
        if (total) { headers.set('Content-Length', String(total)); }

        var reader = fileResponse.body.getReader();
        var stream = new ReadableStream({
          pull: function (controller) {
            return reader.read().then(function (result) {
              if (result.done) {
                controller.close();
                return;
              }
              loaded += result.value.byteLength;
              if (onProgress) { onProgress(loaded, total); }
              controller.enqueue(result.value);
            });
          },
          cancel: function () { reader.cancel(); }
        });

        return caches.open(CACHE).then(function (cache) {
          return cache.put(meta.cacheKey, new Response(stream, { headers: headers }))
            .then(function () {
              /*
               * Metadata is written AFTER the file, never before. A save
               * interrupted halfway then leaves an unlisted orphan, which
               * `sweep()` clears — where the other order would leave a row
               * offering to play a file that does not exist, which is the
               * failure somebody reports.
               */
              return cache.put(metaKey(meta.cacheKey), new Response(JSON.stringify({
                id: meta.id,
                title: meta.title,
                slug: meta.slug,
                duration: meta.duration,
                height: meta.height,
                bytes: loaded,
                savedAt: Date.now()
              }), { headers: { 'Content-Type': 'application/json' } }));
            })
            .then(function () { return meta; });
        });
      });
    });
  }

  /* ------------------------------------------------------------ listing */

  /** Everything saved here, newest first. */
  function list() {
    if (!supported()) { return Promise.resolve([]); }

    return caches.open(CACHE).then(function (cache) {
      return cache.keys().then(function (requests) {
        var files = {};
        var metas = [];

        requests.forEach(function (request) {
          var path = new URL(request.url).pathname;
          if (/\.mp4$/.test(path)) { files[path] = true; }
          if (/\.json$/.test(path)) { metas.push(path); }
        });

        return Promise.all(metas.map(function (path) {
          var filePath = path.replace(/\.json$/, '.mp4');

          // Metadata whose file is gone describes nothing. Reported as absent
          // rather than as a playable row.
          if (!files[filePath]) { return null; }

          return cache.match(path)
            .then(function (r) { return r ? r.json() : null; })
            .then(function (meta) {
              if (!meta) { return null; }
              meta.src = filePath;
              return meta;
            })
            .catch(function () { return null; });
        })).then(function (rows) {
          return rows.filter(Boolean).sort(function (a, b) {
            return (b.savedAt || 0) - (a.savedAt || 0);
          });
        });
      });
    });
  }

  function remove(src) {
    return caches.open(CACHE).then(function (cache) {
      return Promise.all([cache.delete(src), cache.delete(metaKey(src))]);
    });
  }

  /** Files whose save never finished, and metadata whose file is gone. */
  function sweep() {
    return caches.open(CACHE).then(function (cache) {
      return cache.keys().then(function (requests) {
        var paths = requests.map(function (r) { return new URL(r.url).pathname; });
        var orphans = paths.filter(function (path) {
          if (/\.mp4$/.test(path)) {
            return paths.indexOf(metaKey(path)) === -1;
          }
          return paths.indexOf(path.replace(/\.json$/, '.mp4')) === -1;
        });

        return Promise.all(orphans.map(function (path) { return cache.delete(path); }))
          .then(function () { return orphans.length; });
      });
    });
  }

  /**
   * How much room is left, as the browser sees it.
   *
   * Reported because a browser that evicts a saved video does it silently, and
   * somebody standing on a platform discovering their sermon is gone deserves
   * to have been able to see this coming. The numbers are deliberately vague
   * in every browser — an exact quota is a fingerprinting surface — so they are
   * shown as approximate.
   */
  function space() {
    if (!navigator.storage || !navigator.storage.estimate) {
      return Promise.resolve(null);
    }

    return navigator.storage.estimate().then(function (estimate) {
      return { used: estimate.usage || 0, quota: estimate.quota || 0 };
    }).catch(function () { return null; });
  }

  function bytes(n) {
    if (!n) { return '0 MB'; }
    var mb = n / (1024 * 1024);
    return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : Math.round(mb) + ' MB';
  }

  window.PortalOffline = {
    supported: supported,
    save: save,
    list: list,
    remove: remove,
    sweep: sweep,
    space: space,
    bytes: bytes
  };
})();
