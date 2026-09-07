/**
 * The reader.
 *
 * # THE PAGE IS WHAT IS TRACKED
 *
 * Everything here — the position it saves, the marks, the contents — is in PDF
 * pages. The printed number is worked out for DISPLAY from the offset the
 * server sent, and is never sent back. That is the same rule the server keeps,
 * and it is why correcting a book's offset relabels this page too without
 * anything being rewritten.
 *
 * # IT REVALIDATES BEFORE IT OPENS
 *
 * Every open asks /books/{slug}/open first, even when the file is already in
 * the browser cache. A book is something somebody is CURRENTLY allowed to read,
 * so withdrawing that has to take effect on the next attempt rather than
 * whenever a cache expires. The cost is that the reader will not open with no
 * signal at all, and the page says so rather than spinning.
 *
 * This is deliberately unlike a downloaded video, which plays offline. Do not
 * "fix" it.
 *
 * # STEPPING IS BY ENTRY
 *
 * Next and back move by contents entry, not by page: hymns run to three pages
 * and sit two to a page, so page arithmetic lands in the middle of the one
 * already on screen or skips one entirely.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-reader]');

  if (!root) {
    return;
  }

  var slug = root.getAttribute('data-slug');
  var offset = parseInt(root.getAttribute('data-offset'), 10) || 0;
  var pageCount = parseInt(root.getAttribute('data-pages'), 10) || 0;
  var token = root.getAttribute('data-token') || '';
  var page = parseInt(root.getAttribute('data-page'), 10) || 1;

  var stage = root.querySelector('[data-reader-stage]');
  var where = root.querySelector('[data-reader-where]');
  var offline = root.querySelector('[data-reader-offline]');
  var toc = root.querySelector('[data-reader-toc]');

  var data = { contents: [], marks: [] };

  try {
    var raw = root.querySelector('[data-reader-data]');
    if (raw) {
      data = JSON.parse(raw.textContent) || data;
    }
  } catch (e) {
    // A malformed blob costs stepping by entry, not the reader.
  }

  /** The printed number, or null in the front matter. Mirrors Locator. */
  function printed(pdfPage) {
    var n = pdfPage - offset;

    return n >= 1 ? n : null;
  }

  /** The entry a page is inside: the last one starting at or before it. */
  function entryAt(pdfPage) {
    var found = null;

    data.contents.forEach(function (entry) {
      if (entry.page <= pdfPage) {
        found = entry;
      }
    });

    return found;
  }

  function nextEntry(pdfPage) {
    for (var i = 0; i < data.contents.length; i++) {
      if (data.contents[i].page > pdfPage) {
        return data.contents[i];
      }
    }

    return null;
  }

  function previousEntry(pdfPage) {
    var inside = entryAt(pdfPage);

    if (!inside) {
      return null;
    }

    var before = null;

    for (var i = 0; i < data.contents.length; i++) {
      if (data.contents[i] === inside) {
        break;
      }
      before = data.contents[i];
    }

    return before;
  }

  function describe(pdfPage) {
    var entry = entryAt(pdfPage);
    var number = printed(pdfPage);
    var bits = [];

    if (entry) {
      bits.push((entry.number ? entry.number + '. ' : '') + entry.title);
    }

    bits.push(number === null ? 'front matter' : 'page ' + number);

    return bits.join(' — ');
  }

  // ------------------------------------------------------------- rendering

  var rendered = null;

  function show(pdfPage) {
    page = Math.max(1, pageCount > 0 ? Math.min(pdfPage, pageCount) : pdfPage);

    if (where) {
      where.textContent = describe(page);
    }

    if (rendered) {
      rendered(page);
    }

    savePosition();
    history.replaceState(null, '', locationFor(page));
  }

  /**
   * A shareable reference: BY NUMBER where there is one.
   *
   * "Hymn 214" survives a re-scan and a different edition; "page 230" survives
   * neither. Only an unnumbered spot falls back to its page.
   */
  function locationFor(pdfPage) {
    var entry = entryAt(pdfPage);

    if (entry && entry.number && entry.page === pdfPage) {
      return '?at=n' + entry.number;
    }

    return '?at=p' + pdfPage;
  }

  var saving = null;

  function savePosition() {
    // Coalesced: page turns come in bursts when somebody flicks through, and a
    // request per turn is a request per turn.
    window.clearTimeout(saving);

    saving = window.setTimeout(function () {
      var body = new FormData();
      body.append('_token', token);
      body.append('page', String(page));

      fetch('/books/' + encodeURIComponent(slug) + '/position', {
        method: 'POST',
        body: body,
        credentials: 'same-origin'
      }).catch(function () {
        /* Losing a saved place is not worth telling anybody about. */
      });
    }, 1200);
  }

  // ------------------------------------------------------------- opening

  function open() {
    return fetch('/books/' + encodeURIComponent(slug) + '/open', { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('refused');
        }

        return response.json();
      })
      .then(function (info) {
        // The server's answer wins over what the page was rendered with: the
        // offset or the file may have changed since.
        offset = typeof info.offset === 'number' ? info.offset : offset;
        pageCount = typeof info.pages === 'number' && info.pages > 0 ? info.pages : pageCount;

        return info;
      });
  }

  function fail(message) {
    if (stage) {
      stage.innerHTML = '';
    }

    if (offline) {
      offline.textContent = message;
      offline.hidden = false;
    }
  }

  /**
   * Render with whatever the browser has.
   *
   * An <iframe> at the file, which every browser with a built-in PDF viewer
   * handles, and which needs no library committed to the release branch. A
   * richer renderer — text selection, highlights, OCR — is a drop-in
   * replacement for this function and nothing else here changes.
   */
  function mount(info) {
    if (!stage) {
      return;
    }

    stage.innerHTML = '';

    var frame = document.createElement('iframe');
    frame.className = 'reader-frame';
    frame.setAttribute('title', document.title);
    stage.appendChild(frame);

    rendered = function (pdfPage) {
      // The fragment is how a PDF viewer is told which page to show. Rebuilt
      // rather than mutated, because several browsers ignore a fragment change
      // on an iframe that is already loaded.
      frame.src = info.file + '#page=' + pdfPage;
    };

    rendered(page);
  }

  // ------------------------------------------------------------- controls

  function on(selector, event, handler) {
    var el = root.querySelector(selector);

    if (el) {
      el.addEventListener(event, handler);
    }
  }

  on('[data-reader-next]', 'click', function () {
    var entry = nextEntry(page);
    show(entry ? entry.page : page + 1);
  });

  on('[data-reader-prev]', 'click', function () {
    var entry = previousEntry(page);
    show(entry ? entry.page : page - 1);
  });

  on('[data-reader-contents]', 'click', function () {
    if (toc) {
      toc.hidden = !toc.hidden;
    }
  });

  on('[data-reader-goto]', 'submit', function (event) {
    event.preventDefault();

    var input = root.querySelector('[data-reader-number]');
    var wanted = parseInt(input && input.value, 10);

    if (!wanted || wanted < 1) {
      return;
    }

    /*
     * A number is a CONTENTS ENTRY first and a printed page second — the same
     * order the server resolves in, so typing 214 into a hymnal lands on hymn
     * 214 and typing it into a book lands on printed page 214.
     */
    var entry = null;

    for (var i = 0; i < data.contents.length; i++) {
      if (data.contents[i].number === wanted) {
        entry = data.contents[i];
        break;
      }
    }

    show(entry ? entry.page : wanted + offset);
  });

  root.querySelectorAll('[data-reader-jump]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      show(parseInt(link.getAttribute('data-reader-jump'), 10) || 1);

      if (toc) {
        toc.hidden = true;
      }
    });
  });

  /** Arrow keys, unless somebody is typing in the go-to box. */
  document.addEventListener('keydown', function (event) {
    var tag = (event.target && event.target.tagName) || '';

    if (tag === 'INPUT' || tag === 'TEXTAREA') {
      return;
    }

    if (event.key === 'ArrowRight') {
      show(page + 1);
    } else if (event.key === 'ArrowLeft') {
      show(page - 1);
    }
  });

  /** Swipe. */
  var touchX = null;

  root.addEventListener('touchstart', function (event) {
    touchX = event.changedTouches[0].clientX;
  }, { passive: true });

  root.addEventListener('touchend', function (event) {
    if (touchX === null) {
      return;
    }

    var moved = event.changedTouches[0].clientX - touchX;
    touchX = null;

    if (Math.abs(moved) > 60) {
      show(moved < 0 ? page + 1 : page - 1);
    }
  }, { passive: true });

  on('[data-reader-present]', 'click', function () {
    if (root.requestFullscreen) {
      root.requestFullscreen().catch(function () {});
    }

    root.classList.toggle('reader-presenting');
  });

  /**
   * The screen stays awake while a book is open.
   *
   * A hymnal that dims halfway through the second verse is a hymnal nobody
   * uses. Best effort: the API is not everywhere, and it is refused outright
   * when the page is not visible.
   */
  var wakeLock = null;

  function keepAwake() {
    if (!navigator.wakeLock || document.visibilityState !== 'visible') {
      return;
    }

    navigator.wakeLock.request('screen').then(function (lock) {
      wakeLock = lock;
    }).catch(function () {});
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && !wakeLock) {
      keepAwake();
    }
  });

  // ------------------------------------------------------------- go

  open()
    .then(function (info) {
      if (offline) {
        offline.hidden = true;
      }

      mount(info);
      show(page);
      keepAwake();
    })
    .catch(function () {
      fail(
        navigator.onLine
          ? 'This book is not available to you.'
          : 'This needs a connection to open — the site checks you can still read it each time.'
      );
    });
})();
