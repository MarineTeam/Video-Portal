/**
 * Keeping the calendar on this device.
 *
 * The page is rendered by the server; this exists so it still says something
 * when the phone has no signal, which for a rota is most of the times somebody
 * wants it — a church hall, a car park, a Sunday morning.
 *
 * # THE TWO RULES THE PAYLOAD CANNOT STATE
 *
 * Both are applied HERE, and the server cannot apply either, because in both
 * cases NOTHING ABOUT THE ENTRY ROWS CHANGED:
 *
 *   1. A schedule that is gone or switched off takes its dates with it.
 *      Disabling one writes a single row on the server and touches no entry, so
 *      those dates are never reported as changed or deleted. If this did not
 *      drop them, they would sit here for ever and somebody would turn up for a
 *      rota that is not running.
 *
 *   2. Days behind the window are dropped, for the same reason. Yesterday's
 *      entry is not deleted; it simply stops being inside the window the server
 *      answers for, and nothing will ever mention it again.
 *
 * Delete either and the cache still looks right for weeks. That is what makes
 * them worth this much comment.
 */
(function () {
  'use strict';

  var KEY = 'portal_calendar_cache';

  function load() {
    try {
      var raw = window.localStorage.getItem(KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      // Private windows, cleared site data, and browsers set to refuse storage
      // all land here. Nothing cached is a normal state, not an error.
      return null;
    }
  }

  function save(cache) {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(cache));
    } catch (e) {
      /* Out of quota or storage refused. The page still works. */
    }
  }

  /**
   * Fold a payload into what is already held.
   */
  function merge(cache, payload) {
    var entries = payload.full || !cache ? {} : cache.entries || {};

    (payload.removed || []).forEach(function (id) {
      delete entries[id];
    });

    (payload.entries || []).forEach(function (entry) {
      entries[entry.id] = entry;
    });

    // RULE 1. Absent counts as well as disabled: a schedule deleted outright
    // drops out of the list altogether, and its dates went with it.
    var live = {};
    (payload.schedules || []).forEach(function (schedule) {
      if (schedule.enabled) {
        live[schedule.id] = schedule;
      }
    });

    // RULE 2.
    var from = payload.window.from;
    var to = payload.window.to;

    Object.keys(entries).forEach(function (id) {
      var entry = entries[id];

      if (!live[entry.schedule] || entry.date < from || entry.date > to) {
        delete entries[id];
      }
    });

    return {
      since: payload.now,
      window: payload.window,
      schedules: live,
      entries: entries
    };
  }

  function render(cache) {
    var target = document.querySelector('[data-calendar-offline]');

    if (!target) {
      return;
    }

    var list = Object.keys(cache.entries).map(function (id) {
      return cache.entries[id];
    });

    if (list.length === 0) {
      return;
    }

    list.sort(function (a, b) {
      return a.date < b.date ? -1 : a.date > b.date ? 1 : a.schedule - b.schedule;
    });

    var html = '';
    var day = null;

    list.forEach(function (entry) {
      if (entry.date !== day) {
        html += (day === null ? '' : '</ul></section>')
          + '<section class="calendar-day"><h2 class="section-title">'
          + text(new Date(entry.date + 'T00:00:00').toDateString())
          + '</h2><ul class="calendar-entries">';
        day = entry.date;
      }

      var schedule = cache.schedules[entry.schedule] || { name: '' };

      html += '<li><span class="calendar-chip"'
        + (schedule.colour ? ' style="--chip: ' + text(schedule.colour) + '"' : '')
        + '>' + text(schedule.name) + '</span> <span class="calendar-who">'
        + text(entry.person) + '</span>'
        + (entry.role ? ' <span class="muted small">' + text(entry.role) + '</span>' : '')
        + '</li>';
    });

    target.innerHTML = '<p class="notice">Showing the copy saved on this device.</p>'
      + html + '</ul></section>';
    target.hidden = false;
  }

  function text(value) {
    var node = document.createElement('span');
    node.textContent = String(value == null ? '' : value);

    return node.innerHTML;
  }

  var cache = load();

  // The saved copy goes up first, so a phone with no signal has something
  // rather than an empty page while a request that will never finish times out.
  if (cache && !navigator.onLine) {
    render(cache);
  }

  var url = '/calendar/sync' + (cache && cache.since ? '?since=' + encodeURIComponent(cache.since) : '');

  fetch(url, { credentials: 'same-origin' })
    .then(function (response) {
      return response.ok ? response.json() : null;
    })
    .then(function (payload) {
      if (!payload || !payload.window) {
        return;
      }

      var updated = merge(cache, payload);
      save(updated);

      if (!navigator.onLine || document.querySelector('[data-calendar-offline]:not([hidden])')) {
        render(updated);
      }
    })
    .catch(function () {
      // Offline, which is the case this whole file exists for. What is saved
      // is already on screen.
    });
})();
