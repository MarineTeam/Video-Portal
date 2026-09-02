<?php
/**
 * Shown when the network is gone.
 *
 * DELIBERATELY EMPTY OF CONTENT. This page is precached by the service worker,
 * which means a copy is stored on the device and shown to whoever opens the app
 * next — possibly weeks later, possibly a different person on a shared machine.
 * Anything personal, anything access-gated, and anything that goes stale has no
 * business here.
 *
 * It also renders without the site header, on purpose: the header reads the
 * signed-in user and the navigation, and a cached copy of either would be wrong
 * for somebody by the time it is shown.
 *
 * @var string $siteName
 */

declare(strict_types=1);

$siteName ??= 'Video Portal';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>You are offline</title>
<meta name="robots" content="noindex, nofollow">
<style>
  /* Inline, because a stylesheet request is exactly what has just failed. */
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    background: #0f172a;
    color: #e2e8f0;
    text-align: center;
    padding: 2rem;
  }
  .panel { max-width: 34rem; width: 100%; }
  h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 .75rem; }
  h2 { font-size: 1rem; font-weight: 600; margin: 2rem 0 .75rem; }
  p { margin: 0 0 1rem; color: #94a3b8; line-height: 1.6; }
  .saved { text-align: left; }
  .saved li { list-style: none; margin: 0 0 1rem; }
  .saved video { width: 100%; border-radius: .5rem; background: #000; }
  .saved .title { display: block; margin-bottom: .35rem; color: #e2e8f0; }
  .saved .detail { font-size: .85rem; color: #64748b; }
  button {
    font: inherit;
    padding: .55rem 1.1rem;
    border-radius: .5rem;
    border: 1px solid #334155;
    background: transparent;
    color: inherit;
    cursor: pointer;
  }
</style>
</head>
<body>
  <div class="panel">
    <h1>You are offline</h1>
    <p>This page needs a connection and there is not one right now.</p>
    <p>Nothing has been lost — try again once you are back on a network.</p>
    <button type="button" onclick="location.reload()">Try again</button>

    <!--
      Anything saved on this device, listed here because this is the only page
      that renders with no network — so it is the only place the list is of any
      use. Filled by script from Cache Storage; the page itself stays free of
      content, because a precached page is shown to whoever opens the app next.
    -->
    <div id="saved" hidden>
      <h2>Saved on this device</h2>
      <ul class="saved" id="saved-list"></ul>
    </div>
  </div>

  <script>
  /*
   * Inline and self-contained. /assets/offline.js is a network request, and
   * the network is precisely what has failed — the worker does not cache it,
   * deliberately, because caching scripts is how a stale one outlives a fix.
   *
   * So the few lines this page needs are duplicated rather than shared. The
   * cache NAME is the coupling that matters, and it changing without this
   * changing would show an empty list on a device full of videos.
   */
  (function () {
    if (!('caches' in window)) { return; }

    caches.open('portal-offline-videos-v1').then(function (cache) {
      return cache.keys().then(function (requests) {
        var metas = requests
          .map(function (r) { return new URL(r.url).pathname; })
          .filter(function (p) { return /\.json$/.test(p); });

        return Promise.all(metas.map(function (path) {
          return cache.match(path)
            .then(function (r) { return r ? r.json() : null; })
            .then(function (meta) {
              if (!meta) { return null; }
              meta.src = path.replace(/\.json$/, '.mp4');
              return meta;
            })
            .catch(function () { return null; });
        }));
      });
    }).then(function (rows) {
      rows = rows.filter(Boolean);
      if (rows.length === 0) { return; }

      var list = document.getElementById('saved-list');

      rows.forEach(function (item) {
        var li = document.createElement('li');

        var title = document.createElement('span');
        title.className = 'title';
        title.textContent = item.title || 'Untitled';
        li.appendChild(title);

        var player = document.createElement('video');
        player.controls = true;
        player.preload = 'none';
        // Served by the worker out of Cache Storage, ranges and all, so this
        // plays and SEEKS with no connection at all.
        player.src = item.src;
        li.appendChild(player);

        list.appendChild(li);
      });

      document.getElementById('saved').hidden = false;
    }).catch(function () {
      // A page that cannot read the cache still shows the offline notice,
      // which is the thing it exists for.
    });
  })();
  </script>
</body>
</html>
