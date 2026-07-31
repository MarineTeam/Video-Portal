/**
 * Playback progress and resume.
 *
 * Talks to the bunny.net player through player.js over postMessage, because
 * the player lives in a cross-origin iframe and nothing inside it is reachable
 * from here directly.
 *
 * Everything degrades quietly. If the player never answers — an ad blocker, a
 * provider change, a slow connection — the video still plays; only resume and
 * progress tracking are lost. Failing loudly here would break playback to
 * protect a convenience feature.
 */
(function () {
  'use strict';

  var data = document.getElementById('portal-player-data');
  var frame = document.querySelector('.player iframe');

  if (!data || !frame) {
    return;
  }

  var videoId = parseInt(data.dataset.videoId || '0', 10);
  var resumeAt = parseInt(data.dataset.resumeAt || '0', 10);

  if (!videoId) {
    return;
  }

  var duration = 0;
  var position = 0;
  var lastSaved = 0;
  var hasResumed = false;

  /* Save at most every 10 seconds. The player reports position several times a
     second; posting each one would be thousands of writes per view. */
  var SAVE_INTERVAL = 10;

  function send(method, value) {
    try {
      frame.contentWindow.postMessage(
        JSON.stringify({ context: 'player.js', method: method, value: value }),
        '*'
      );
    } catch (e) {
      /* Cross-origin restrictions vary by browser and embed configuration. */
    }
  }

  function save(force) {
    if (!duration || position < 10) {
      return;
    }
    if (!force && Math.abs(position - lastSaved) < SAVE_INTERVAL) {
      return;
    }

    lastSaved = position;

    var body = JSON.stringify({
      videoId: videoId,
      position: Math.floor(position),
      duration: Math.floor(duration)
    });

    /* sendBeacon survives the page being closed, which is exactly when the
       final position matters most. fetch() is cancelled on unload. */
    if (force && navigator.sendBeacon) {
      navigator.sendBeacon('/api/progress', new Blob([body], { type: 'application/json' }));
      return;
    }

    fetch('/api/progress', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: body,
      credentials: 'same-origin',
      keepalive: true
    }).catch(function () {
      /* A dropped progress save is not worth surfacing to a viewer. */
    });
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
      send('addEventListener', 'ended');
      send('addEventListener', 'pause');
      send('getDuration');
      return;
    }

    if (message.event === 'timeupdate' && message.value) {
      position = message.value.seconds || 0;
      duration = message.value.duration || duration;

      /* Seek once, on the first position report — waiting for `ready` alone is
         not enough, because the player will not accept a seek until it has
         actually loaded something. */
      if (!hasResumed && resumeAt > 5 && duration > 0 && resumeAt < duration * 0.95) {
        hasResumed = true;
        send('setCurrentTime', resumeAt);
      }

      save(false);
      return;
    }

    if (message.event === 'pause') {
      save(true);
      return;
    }

    if (message.event === 'ended') {
      position = duration;
      save(true);
      return;
    }

    if (message.method === 'getDuration') {
      duration = message.value || 0;
    }
  });

  /* Last chance to record where they got to. pagehide fires in cases
     beforeunload does not, notably on iOS. */
  window.addEventListener('pagehide', function () {
    save(true);
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      save(true);
    }
  });
})();
