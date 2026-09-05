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
  var audio = document.getElementById('portal-audio');

  /* Either source is enough. The audio panel exists on pages where the video
     player does not — a premiere has no iframe — and the progress rules below
     are the same whichever one is playing, which is the point: somebody can
     start listening and finish watching. */
  if (!data || (!frame && !audio)) {
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

  /* Where the player has got to, for anything else on the page that needs it.
     Read-only and a function rather than a value, so a caller cannot hold a
     stale number — the note-taking panel asks at the moment somebody presses a
     button, which is the only moment the answer is worth anything.

     Deliberately the only thing this file exposes. A player that published its
     whole state would be a player every theme started reaching into. */
  window.portalPlayer = {
    position: function () {
      return Math.floor(position);
    }
  };

  /* Save at most every 10 seconds. The player reports position several times a
     second; posting each one would be thousands of writes per view. */
  var SAVE_INTERVAL = 10;

  function send(method, value) {
    if (!frame) {
      return;
    }
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

  /* ------------------------------------------------------------- audio mode
   *
   * The three things the iframe cannot do: change speed, keep playing with the
   * screen off, and say what is playing on a lock screen. An <audio> element on
   * this origin can do all of them.
   *
   * It shares save() with the video above rather than posting its own
   * progress. That is the point of putting it in this file: the ten-second
   * throttle, the ten-second floor and the sendBeacon-on-unload rule are the
   * heartbeat contract, and a second implementation of it would drift from
   * this one — which would show up as a position that depends on which player
   * somebody happened to use.
   */
  if (!audio) {
    return;
  }

  var controls = document.getElementById('portal-audio-controls');
  var speed = document.getElementById('portal-audio-speed');
  var sleep = document.getElementById('portal-audio-sleep');
  var sleepState = document.getElementById('portal-audio-sleep-state');
  var sleepTimer = null;
  var stopAtEnd = false;

  /* Revealed only now. With scripting off the audio still plays and only these
     two are missing, which is the right thing to lose — a speed menu that does
     nothing is worse than no speed menu. */
  if (controls) {
    controls.hidden = false;
  }

  /*
   * Resume applies to audio too, and it is the same position.
   *
   * Set on `loadedmetadata` rather than immediately: currentTime before the
   * duration is known is silently dropped by every browser, which reads as
   * resume simply not working on audio.
   */
  audio.addEventListener('loadedmetadata', function () {
    duration = audio.duration || duration;

    if (hasResumed || !duration) {
      return;
    }

    var wanted = startAt > 0
      ? (startAt < duration ? startAt : 0)
      : (resumeAt > 5 && resumeAt < duration * 0.95 ? resumeAt : 0);

    if (wanted > 0) {
      hasResumed = true;
      audio.currentTime = wanted;
    }
  });

  /*
   * Starting the audio stops the video.
   *
   * Both can play at once otherwise — open the panel while the video is
   * running and the same sermon comes out twice, a second or two apart, which
   * sounds like the site is broken rather than like two players. The iframe
   * cannot be read from here but it can be told, which is enough.
   */
  audio.addEventListener('play', function () {
    send('pause');
  });

  audio.addEventListener('timeupdate', function () {
    position = audio.currentTime || 0;
    duration = audio.duration || duration;
    save(false);
  });

  audio.addEventListener('pause', function () {
    save(true);
  });

  audio.addEventListener('ended', function () {
    position = duration;
    save(true);
  });

  /*
   * Speed.
   *
   * Applied on `ratechange` guard rather than trusting the select's value
   * directly, because a browser that refuses a rate leaves the menu showing
   * something that is not happening.
   */
  if (speed) {
    speed.addEventListener('change', function () {
      var rate = parseFloat(speed.value);

      if (!(rate > 0)) {
        return;
      }

      audio.playbackRate = rate;

      /* Say what actually took effect. Safari clamps above 2× on some
         versions, and a menu that reads 2 while playing at 1 is a control
         that lies. */
      if (Math.abs(audio.playbackRate - rate) > 0.01) {
        speed.value = String(audio.playbackRate);
      }
    });
  }

  /*
   * The sleep timer.
   *
   * It PAUSES rather than stopping and unloading, so the position is saved by
   * the pause handler above and somebody who fell asleep finds themselves
   * where they drifted off — which is the entire reason for the feature.
   *
   * "End of this" is a separate answer rather than a duration, because the
   * length of what is playing is the one interval nobody can estimate and it
   * is what people actually mean at bedtime.
   */
  function clearSleep() {
    if (sleepTimer) {
      clearTimeout(sleepTimer);
      sleepTimer = null;
    }
    stopAtEnd = false;
    if (sleepState) {
      sleepState.hidden = true;
      sleepState.textContent = '';
    }
  }

  function announceSleep(text) {
    if (sleepState) {
      sleepState.textContent = text;
      sleepState.hidden = false;
    }
  }

  if (sleep) {
    sleep.addEventListener('change', function () {
      var seconds = parseInt(sleep.value, 10);

      clearSleep();

      if (seconds === -1) {
        stopAtEnd = true;
        announceSleep('Stopping at the end of this.');
        return;
      }

      if (!(seconds > 0)) {
        return;
      }

      /* An absolute wall-clock target, so the message stays true. A countdown
         driven by setInterval drifts when the tab is backgrounded, which is
         exactly where this timer spends its life. */
      var endsAt = Date.now() + seconds * 1000;

      sleepTimer = setTimeout(function () {
        audio.pause();
        clearSleep();
        announceSleep('Paused. Press play to carry on.');
      }, seconds * 1000);

      announceSleep('Pausing at ' + new Date(endsAt).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit'
      }) + '.');
    });
  }

  audio.addEventListener('ended', function () {
    if (stopAtEnd) {
      clearSleep();
    }
  });

  /*
   * The lock screen.
   *
   * Without this a phone shows the page URL, which tells somebody driving
   * nothing about which sermon is playing. Guarded because Media Session is
   * absent on desktop Safari and older Android, where the audio still plays —
   * it is metadata, not playback.
   */
  if ('mediaSession' in navigator && window.MediaMetadata) {
    audio.addEventListener('play', function () {
      var artwork = [];
      var image = data.dataset.artwork || '';

      /* Only a real URL. An empty src handed to the operating system draws a
         broken image on the lock screen, where the absence of one draws the
         app's own icon. */
      if (image) {
        artwork.push({ src: image, sizes: '512x512', type: 'image/jpeg' });
      }

      try {
        navigator.mediaSession.metadata = new window.MediaMetadata({
          title: data.dataset.title || document.title,
          artist: data.dataset.artist || '',
          artwork: artwork
        });
      } catch (e) {
        /* Metadata is a courtesy; playback is the feature. */
      }
    });

    /* Skip buttons, because the hardware ones on headphones and car stereos
       map to these and otherwise do nothing. Thirty and fifteen seconds are
       the podcast conventions rather than a choice made here. */
    try {
      navigator.mediaSession.setActionHandler('seekbackward', function () {
        audio.currentTime = Math.max(0, audio.currentTime - 15);
      });
      navigator.mediaSession.setActionHandler('seekforward', function () {
        audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + 30);
      });
    } catch (e) {
      /* Not every browser accepts every action. */
    }
  }
})();
