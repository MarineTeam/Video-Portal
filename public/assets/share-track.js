/**
 * Playback tracking for a share link.
 *
 * Reports plays, furthest position, and completion so whoever sent the link can
 * see whether it was actually watched — as distinct from merely opened, which
 * the server records on its own.
 *
 * Authenticated by the share id, which is the same unguessable secret that
 * granted access in the first place. A session would not work here: gate
 * recipients deliberately have none.
 *
 * Degrades silently. If the player never answers — an ad blocker, a provider
 * change, a slow connection — the video still plays and only the statistics are
 * lost. Failing loudly to protect a metric would be the wrong trade.
 */
(function () {
  'use strict';

  var node = document.getElementById('share-tracking');
  var frame = document.querySelector('.player iframe');

  if (!node || !frame) {
    return;
  }

  var shareId = node.dataset.shareId;
  if (!shareId) {
    return;
  }

  var duration = 0;
  var position = 0;
  var lastReported = -1;
  var hasStarted = false;

  /* Every 15 seconds at most. The player reports several times a second, and
     posting each one would be thousands of writes for a single viewing. */
  var REPORT_INTERVAL = 15;

  function post(event, percent, useBeacon) {
    var body = JSON.stringify({
      shareId: shareId,
      event: event,
      percent: Math.max(0, Math.min(100, Math.round(percent || 0)))
    });

    /* sendBeacon survives the page closing, which is exactly when the final
       position matters most; fetch is cancelled on unload. */
    if (useBeacon && navigator.sendBeacon) {
      navigator.sendBeacon('/api/share-track', new Blob([body], { type: 'application/json' }));
      return;
    }

    fetch('/api/share-track', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: body,
      credentials: 'same-origin',
      keepalive: true
    }).catch(function () {
      /* A dropped statistic is not worth surfacing to a viewer. */
    });
  }

  function send(method, value) {
    try {
      frame.contentWindow.postMessage(
        JSON.stringify({ context: 'player.js', method: method, value: value }),
        '*'
      );
    } catch (e) {
      /* Cross-origin rules vary by browser and embed configuration. */
    }
  }

  function percent() {
    return duration > 0 ? (position / duration) * 100 : 0;
  }

  window.addEventListener('message', function (event) {
    if (typeof event.data !== 'string') {
      return;
    }

    var message;
    try {
      message = JSON.parse(event.data);
    } catch (e) {
      return;
    }

    if (!message || message.context !== 'player.js') {
      return;
    }

    if (message.event === 'ready') {
      send('addEventListener', 'timeupdate');
      send('addEventListener', 'play');
      send('addEventListener', 'pause');
      send('addEventListener', 'ended');
      return;
    }

    if (message.event === 'play' && !hasStarted) {
      /* Counted once per page view, so re-pressing play after a pause does not
         inflate the number. */
      hasStarted = true;
      post('play', 0, false);
      return;
    }

    if (message.event === 'timeupdate' && message.value) {
      position = message.value.seconds || 0;
      duration = message.value.duration || duration;

      var current = percent();
      if (current - lastReported >= REPORT_INTERVAL) {
        lastReported = current;
        post('progress', current, false);
      }
      return;
    }

    if (message.event === 'pause') {
      post('progress', percent(), false);
      return;
    }

    if (message.event === 'ended') {
      post('ended', 100, false);
    }
  });

  /* Last chance to record where they reached. pagehide fires in cases
     beforeunload does not, notably on iOS. */
  window.addEventListener('pagehide', function () {
    if (hasStarted && duration > 0) {
      post('progress', percent(), true);
    }
  });
})();
