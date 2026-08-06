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

  /* A moment somebody asked for explicitly, from a ?t= link. It beats resume:
     they clicked a chapter or a transcript line, and putting them back where
     they left off instead would ignore what they asked for. */
  var startAt = parseInt(data.dataset.startAt || '0', 10);

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
         actually loaded something.

         The guards differ by intent. A RESUME is a guess, so it is skipped for
         the first few seconds (not worth it) and near the end (they finished);
         being wrong there is an annoyance the viewer did not ask for. An
         explicit ?t= is a request, so the only thing that can stop it is the
         moment being past the end. */
      if (!hasResumed && duration > 0) {
        var wanted = startAt > 0
          ? (startAt < duration ? startAt : 0)
          : (resumeAt > 5 && resumeAt < duration * 0.95 ? resumeAt : 0);

        if (wanted > 0) {
          hasResumed = true;
          send('setCurrentTime', wanted);
        }
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

  /*
   * Chapter and transcript links seek in place.
   *
   * They are ordinary links to ?t=seconds, so with scripting off they still
   * work — the page reloads and the player starts at that moment, because the
   * server reads the same parameter. This only removes the reload.
   *
   * Delegated from the document so it covers a transcript panel that has not
   * been opened yet, and reads the target out of the href rather than a data
   * attribute so there is one place the moment is written.
   */
  document.addEventListener('click', function (event) {
    var link = event.target.closest('.chapter-list a, .transcript-time');

    if (!link) {
      return;
    }

    var match = /[?&]t=(\d+)/.exec(link.getAttribute('href') || '');

    if (!match) {
      return;
    }

    var seconds = parseInt(match[1], 10);

    /* Only once the player has told us something. Before that a seek is
       silently dropped, and letting the link navigate is the honest fallback
       — the reload lands in the right place. */
    if (!duration) {
      return;
    }

    event.preventDefault();
    send('setCurrentTime', seconds);
    send('play');

    /* So the address bar matches what is playing and the page stays
       shareable, without adding a history entry per chapter clicked. */
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', link.getAttribute('href'));
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
