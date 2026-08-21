/**
 * Skip to a chapter, and roll into the next episode.
 *
 * Its own conversation with the player, not a borrowed one. The bundled theme
 * has a player.js of its own and deliberately exposes only the current
 * position — "a player that published its whole state would be a player every
 * theme started reaching into". A plugin that relied on that private API would
 * be a plugin that breaks on every other theme, so this does its own handshake.
 * Two listeners on one iframe is normal; postMessage broadcasts.
 *
 * Everything degrades. Without this file the skip button is a ?t= link the
 * theme already honours, and the up-next card stays hidden with a working link
 * inside it. If the player never answers — an ad blocker, a provider change —
 * the video still plays and only the conveniences are lost. Failing loudly to
 * protect a convenience is how you break playback.
 */
(function () {
  'use strict';

  var root = document.querySelector('.pb');
  if (!root) {
    return;
  }

  /* The bundled theme's markup first, then any embed iframe. A third-party
     theme will not have `.player`, and this plugin should not require it. */
  var frame = document.querySelector('.player iframe')
    || document.querySelector('iframe[src*="mediadelivery.net"]')
    || document.querySelector('iframe[allow*="fullscreen"]');

  if (!frame) {
    return;
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

  /* ------------------------------------------------------------ skip ahead */

  var skip = root.querySelector('[data-pb-seek]');

  if (skip) {
    skip.addEventListener('click', function (event) {
      var seconds = parseInt(skip.dataset.pbSeek || '0', 10);
      if (!seconds) {
        return; /* Let the href do it. */
      }

      /* Only now is the link's job taken over. Preventing the default before
         knowing there is something to seek to would turn a working link into
         a dead one. */
      event.preventDefault();
      send('setCurrentTime', seconds);
      send('play');
    });
  }

  /* -------------------------------------------------------------- up next */

  var next = document.getElementById('pb-next');
  if (!next) {
    return;
  }

  var countdown = parseInt(next.dataset.pbCountdown || '0', 10);
  var target = next.querySelector('.pb-next-go');
  var stop = next.querySelector('.pb-next-stop');
  var label = next.querySelector('.pb-next-label');
  var timer = null;
  var cancelled = false;

  function halt() {
    cancelled = true;
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
    if (label) {
      label.textContent = 'Up next';
    }
  }

  if (stop) {
    stop.addEventListener('click', halt);
  }

  /* Any of these means somebody is still here and did not mean to leave.
     Scrolling is deliberately not one of them — a phone settling as a page
     loads would cancel a countdown nobody objected to.

     Registered only once the card is SHOWING. At load time the first click on
     the page is the one that starts the video, and cancelling then would mean
     the countdown never ran for anybody who pressed play. */
  function watchForActivity() {
    ['click', 'keydown', 'pointerdown'].forEach(function (name) {
      document.addEventListener(name, function (event) {
        if (event.target && event.target.closest && event.target.closest('.pb-next-go')) {
          return; /* They pressed Play now; let the link do it. */
        }
        halt();
      }, { once: true });
    });
  }

  function ended() {
    /* A player can report `ended` more than once — a replay, a seek past the
       end — and neither should restart a countdown somebody already stopped. */
    if (cancelled || !next.hidden) {
      return;
    }

    next.hidden = false;
    watchForActivity();

    if (countdown <= 0 || !target) {
      return; /* Card only: the site has asked not to play things by itself. */
    }

    var left = countdown;

    function tick() {
      if (cancelled) {
        return;
      }

      if (left <= 0) {
        clearInterval(timer);
        timer = null;
        window.location.href = target.getAttribute('href');
        return;
      }

      if (label) {
        label.textContent = 'Up next in ' + left + 's';
      }

      left--;
    }

    tick();
    timer = setInterval(tick, 1000);
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
      send('addEventListener', 'ended');
      return;
    }

    if (message.event === 'ended') {
      ended();
    }
  });

  /* The player may have become ready before this file ran — `defer` puts it
     after parsing, and the iframe starts loading earlier. Asking again costs
     one message and covers the race. */
  send('addEventListener', 'ended');
})();
