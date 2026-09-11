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
  var kind = root.getAttribute('data-kind') || 'pdf';
  var offset = parseInt(root.getAttribute('data-offset'), 10) || 0;

  /** The open PDF, once there is one. Read-aloud and indexing both need it. */
  var pdfDocument = null;

  /*
   * How an EPUB moves. Null for a PDF, where next and back are page numbers —
   * an EPUB reflows, so a screenful is the only unit its renderer knows and
   * this code does not get to choose one.
   */
  var epubNext = null;
  var epubPrevious = null;
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

    showBookmarked();
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

  /**
   * Where somebody had got to.
   *
   * DOES NOTHING FOR AN EPUB, whose own 'relocated' handler saves the CFI. This
   * one sends a page and no CFI, so letting it run would null the saved string
   * the moment the book opened — the position wiped by the act of restoring it.
   */
  function savePosition() {
    if (epubNext) {
      return;
    }

    savePdfPosition();
  }

  function savePdfPosition() {
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
   * Render the pages ourselves, with PDF.js.
   *
   * The alternative — an <iframe> at the file — renders and pages perfectly
   * well and needs no vendored library. What it cannot do is give this
   * application the TEXT: a browser's built-in viewer is a black box, so there
   * is no selection to highlight, nothing to read aloud, and nothing to extract
   * for the search index. Every one of those is a feature of this section, so
   * the renderer has to be ours.
   *
   * Falls back to the iframe when the library is missing. A reader that pages
   * is worth a great deal more than a broken one, and vendored files do go
   * missing — a pruned deploy, a host that will not serve .js from a nested
   * directory, a proxy that eats it.
   */
  function mount(info) {
    if (!stage) {
      return;
    }

    stage.innerHTML = '';

    if (kind === 'epub') {
      return mountEpub(info);
    }

    if (!window.pdfjsLib) {
      return mountFrame(info);
    }

    window.pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js';

    var canvas = document.createElement('canvas');
    canvas.className = 'reader-canvas';

    // Sits exactly over the canvas, holding invisible positioned text so the
    // browser's own selection works. This is what makes highlighting and
    // read-aloud possible at all.
    var textLayer = document.createElement('div');
    textLayer.className = 'reader-text';

    var wrap = document.createElement('div');
    wrap.className = 'reader-canvas-wrap';
    wrap.appendChild(canvas);
    wrap.appendChild(textLayer);
    stage.appendChild(wrap);

    var pending = null;

    window.pdfjsLib.getDocument({ url: info.file, withCredentials: true }).promise
      .then(function (pdf) {
        pdfDocument = pdf;
        pageCount = pdf.numPages || pageCount;

        rendered = function (pdfPage) {
          /*
           * One render at a time. Flicking through with the arrow keys queues
           * renders faster than they finish, and two writing to one canvas
           * leaves half of each page on screen.
           */
          if (pending) {
            pending.cancelled = true;
          }

          var job = { cancelled: false };
          pending = job;

          pdf.getPage(Math.max(1, Math.min(pdfPage, pdf.numPages))).then(function (pageObject) {
            if (job.cancelled) { return; }

            // The reading size multiplies the fit-to-width scale, so bigger
            // text means a bigger page rather than a different layout — a PDF
            // page is a picture and there is no reflow to be had.
            var fit = (stage.clientWidth || 800) / pageObject.getViewport({ scale: 1 }).width;
            var viewport = pageObject.getViewport({ scale: Math.max(0.2, fit * zoom()) });

            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            wrap.style.width = canvas.width + 'px';
            wrap.style.height = canvas.height + 'px';

            return pageObject.render({
              canvasContext: canvas.getContext('2d'),
              viewport: viewport
            }).promise.then(function () {
              if (job.cancelled) { return; }

              return pageObject.getTextContent().then(function (text) {
                if (job.cancelled) { return; }

                textLayer.innerHTML = '';
                textLayer.style.width = canvas.width + 'px';
                textLayer.style.height = canvas.height + 'px';

                window.pdfjsLib.renderTextLayer({
                  textContentSource: text,
                  container: textLayer,
                  viewport: viewport
                });

                // After the page is drawn, not before: the highlight layer is
                // sized as a fraction of the wrapper, which has no size until
                // the canvas has one.
                drawMarks();
              });
            });
          }).catch(function () {
            // A page that will not render is one page, not the book.
          });
        };

        rendered(page);
      })
      .catch(function () {
        // A file PDF.js cannot open still opens in the browser's own viewer
        // often enough to be worth trying.
        mountFrame(info);
      });
  }

  /**
   * An EPUB, rendered by epub.js.
   *
   * # ITS POSITION IS A STRING, NOT A PAGE
   *
   * An EPUB has no pages — it reflows, so "page 40" depends on the window and
   * the type size. What it has is a CFI: an opaque pointer into the document
   * that only this renderer understands. That is why {reading_positions} keeps
   * `epub_cfi` BESIDE `pdf_page` rather than instead of it, and why the
   * percentage is stored rather than derived — nothing on the server could work
   * one out of a CFI.
   *
   * Highlighting stays unavailable here and is refused on the server too. The
   * text lives inside an iframe epub.js owns; a mark anchored into it could
   * never be drawn again.
   */
  function mountEpub(info) {
    if (!window.ePub || !window.JSZip) {
      // Missing either one and epub.js fails with an error naming neither. The
      // browser's own handling is a poor book but a clear one.
      return mountFrame(info);
    }

    var host = document.createElement('div');
    host.className = 'reader-epub';
    stage.appendChild(host);

    var book = window.ePub(info.file, { openAs: 'epub' });
    var view = book.renderTo(host, { width: '100%', height: '100%', flow: 'paginated' });

    var startAt = root.getAttribute('data-cfi') || null;
    view.display(startAt || undefined);

    /*
     * Next and back move through the BOOK, not through our page numbers. The
     * contents still step by entry — an EPUB's chapters are entries like any
     * other — but between them the unit is a screenful, which is what epub.js
     * knows and this code does not.
     */
    rendered = function () {};

    epubNext = function () { view.next(); };
    epubPrevious = function () { view.prev(); };

    view.on('relocated', function (location) {
      if (!location || !location.start) { return; }

      // Saved as the opaque string plus a percentage, which is all a progress
      // bar needs and all this can honestly compute.
      var percent = location.start.percentage
        ? Math.round(location.start.percentage * 100)
        : 0;

      var body = new FormData();
      body.append('_token', token);
      body.append('page', '1');
      body.append('cfi', location.start.cfi || '');
      body.append('percent', String(percent));

      fetch('/books/' + encodeURIComponent(slug) + '/position', {
        method: 'POST',
        body: body,
        credentials: 'same-origin'
      }).catch(function () {});
    });

    book.ready.catch(function () {
      mountFrame(info);
    });
  }

  /** The fallback: the browser's own viewer, which cannot be read from. */
  function mountFrame(info) {
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

    // In an EPUB, past the contents entries the unit is a screenful and only
    // its renderer knows where one ends.
    if (!entry && epubNext) { return epubNext(); }

    show(entry ? entry.page : page + 1);
  });

  on('[data-reader-prev]', 'click', function () {
    var entry = previousEntry(page);

    if (!entry && epubPrevious) { return epubPrevious(); }

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

  /*
   * Saving the file to this device.
   *
   * Shown only where the browser can do it. What it buys is SPEED and DATA,
   * not availability: the reader still asks the site on every open, so a saved
   * book will not open with no signal. The button's title says so, the offline
   * screen says so, and that is deliberate rather than a limitation to be
   * worked around.
   */
  var saveButton = root.querySelector('[data-reader-save]');

  if (saveButton && window.PortalOffline && window.PortalOffline.supported()) {
    saveButton.hidden = false;

    saveButton.addEventListener('click', function () {
      if (!window.PortalOffline.mayDownloadNow()) {
        saveButton.textContent = 'Not on mobile data — change it in your account';

        return;
      }

      saveButton.disabled = true;
      saveButton.textContent = 'Saving…';

      window.PortalOffline.saveBook(slug)
        .then(function () {
          saveButton.textContent = 'Saved to this device';
        })
        .catch(function (error) {
          // The reason, not a category. Four things produce a failed save and
          // each needs a different fix.
          saveButton.disabled = false;
          saveButton.textContent = error && error.message ? error.message : 'That did not save';
        });
    });
  }

  /* ------------------------------------------------------ finding a line
   *
   * Against the text an admin's browser read out of this book, not against the
   * page on screen — so it finds a line four hundred pages away rather than
   * only what is currently drawn.
   *
   * A book nobody has indexed finds nothing, and the answer SAYS SO. An empty
   * result and an unindexed book look identical otherwise, and the second is
   * something an administrator can fix in a minute.
   */
  var hits = root.querySelector('[data-reader-hits]');

  function escapeText(value) {
    var node = document.createElement('span');
    node.textContent = String(value == null ? '' : value);

    return node.innerHTML;
  }

  on('[data-reader-find]', 'submit', function (event) {
    event.preventDefault();

    var input = root.querySelector('[data-reader-query]');
    var query = (input && input.value || '').trim();

    if (!hits) { return; }

    if (query === '') {
      hits.hidden = true;

      return;
    }

    hits.hidden = false;
    hits.innerHTML = '<p class="muted small">Looking…</p>';

    fetch('/books/' + encodeURIComponent(slug) + '/search?q=' + encodeURIComponent(query), {
      credentials: 'same-origin'
    })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (answer) {
        if (!answer || !answer.results || answer.results.length === 0) {
          hits.innerHTML = '<p class="muted small">Nothing found. Only a book somebody has '
            + 'indexed can be searched from here.</p>';

          return;
        }

        var html = '<ul>';

        answer.results.forEach(function (hit) {
          var number = printed(hit.pdf_page);

          html += '<li><a href="#" data-reader-jump="' + Number(hit.pdf_page) + '">'
            + (number === null ? 'front matter' : 'page ' + number)
            + '</a> '
            // Said, because OCR text is good enough to search and not good
            // enough to quote — a snippet may be visibly wrong.
            + (hit.source === 'ocr' ? '<span class="muted small">(read by OCR)</span> ' : '')
            + '<span class="muted small">' + escapeText(hit.snippet) + '</span></li>';
        });

        hits.innerHTML = html + '</ul>';

        hits.querySelectorAll('[data-reader-jump]').forEach(function (link) {
          link.addEventListener('click', function (jumpEvent) {
            jumpEvent.preventDefault();
            show(parseInt(link.getAttribute('data-reader-jump'), 10) || 1);
          });
        });
      })
      .catch(function () {
        hits.innerHTML = '<p class="muted small">The search did not answer.</p>';
      });
  });

  /* ---------------------------------------------------------- the marks
   *
   * A HIGHLIGHT IS A RECTANGLE IN THE PAGE'S OWN COORDINATES, stored as a
   * fraction of the page rather than in pixels.
   *
   * Pixels would be a highlight that lands somewhere else on a phone, on a
   * wider window, or at a different reading size — and reading size is a
   * control this reader has. Fractions survive all three, because they describe
   * where on the PAGE the words are rather than where on the screen they were
   * when somebody dragged.
   *
   * Which is the same reasoning as storing the PDF page rather than the printed
   * number: keep what is true about the book, derive what is true about this
   * screen.
   */
  var marks = (data.marks || []).slice();

  function markLayer() {
    var wrap = root.querySelector('.reader-canvas-wrap');

    if (!wrap) { return null; }

    var layer = wrap.querySelector('.reader-marks');

    if (!layer) {
      layer = document.createElement('div');
      layer.className = 'reader-marks';
      // Behind the text layer so selection still works over a highlight.
      wrap.insertBefore(layer, wrap.querySelector('.reader-text'));
    }

    return layer;
  }

  /** Draw this page's highlights. */
  function drawMarks() {
    var layer = markLayer();

    if (!layer) { return; }

    layer.innerHTML = '';

    marks.forEach(function (mark) {
      if (mark.page !== page || mark.kind !== 'highlight' || !mark.anchor) {
        return;
      }

      var boxes;

      try {
        boxes = JSON.parse(mark.anchor);
      } catch (e) {
        // An anchor this cannot read draws nothing rather than throwing —
        // one unreadable mark must not cost the page.
        return;
      }

      (boxes || []).forEach(function (box) {
        var el = document.createElement('span');
        el.className = 'reader-mark';
        el.style.left = (box.x * 100) + '%';
        el.style.top = (box.y * 100) + '%';
        el.style.width = (box.w * 100) + '%';
        el.style.height = (box.h * 100) + '%';

        if (mark.colour) {
          el.style.background = mark.colour;
        }

        el.title = mark.body || mark.quote || 'Highlighted';
        layer.appendChild(el);
      });
    });
  }

  /**
   * Turn what is selected into fractions of the page.
   *
   * getClientRects rather than one bounding box, so a selection spanning three
   * lines is three rectangles rather than one covering the paragraph between
   * them.
   */
  function selectionBoxes() {
    var selection = window.getSelection();

    if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
      return null;
    }

    var wrap = root.querySelector('.reader-canvas-wrap');

    if (!wrap) { return null; }

    var frame = wrap.getBoundingClientRect();

    if (!frame.width || !frame.height) { return null; }

    var rects = selection.getRangeAt(0).getClientRects();
    var boxes = [];

    for (var i = 0; i < rects.length; i++) {
      var rect = rects[i];

      if (rect.width < 1 || rect.height < 1) { continue; }

      boxes.push({
        x: (rect.left - frame.left) / frame.width,
        y: (rect.top - frame.top) / frame.height,
        w: rect.width / frame.width,
        h: rect.height / frame.height
      });
    }

    return boxes.length ? { boxes: boxes, text: selection.toString() } : null;
  }

  function postMark(fields) {
    var body = new FormData();
    body.append('_token', token);

    Object.keys(fields).forEach(function (key) {
      body.append(key, fields[key]);
    });

    return fetch('/books/' + encodeURIComponent(slug) + '/marks', {
      method: 'POST',
      body: body,
      credentials: 'same-origin'
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('That could not be saved.');
      }

      return response.json();
    });
  }

  on('[data-reader-highlight]', 'click', function () {
    var chosen = selectionBoxes();

    if (!chosen) {
      return;
    }

    var fields = {
      kind: 'highlight',
      page: String(page),
      anchor: JSON.stringify(chosen.boxes),
      quote: chosen.text.slice(0, 1000),
      colour: '#ffd54f'
    };

    postMark(fields).then(function (saved) {
      marks.push({
        id: saved.id,
        page: page,
        kind: 'highlight',
        anchor: fields.anchor,
        quote: fields.quote,
        colour: fields.colour
      });

      window.getSelection().removeAllRanges();
      drawMarks();
    }).catch(function () {
      /* Refused — an EPUB, or no account. The button is hidden in both. */
    });
  });

  on('[data-reader-bookmark]', 'click', function () {
    postMark({ kind: 'bookmark', page: String(page) }).then(function (saved) {
      marks.push({ id: saved.id, page: page, kind: 'bookmark' });
      showBookmarked();
    }).catch(function () {});
  });

  function showBookmarked() {
    var button = root.querySelector('[data-reader-bookmark]');

    if (!button) { return; }

    var here = marks.some(function (mark) {
      return mark.kind === 'bookmark' && mark.page === page;
    });

    button.textContent = here ? 'Bookmarked' : 'Bookmark';
  }

  /* ------------------------------------------------------- reading aloud
   *
   * ONE VERSE AT A TIME, not the whole page in one utterance.
   *
   * Speech synthesis gives no reliable way to seek inside a long utterance, so
   * a whole page read as one is a thing somebody can only stop and restart. Cut
   * into blank-line-separated blocks, "pause" lands between verses, which is
   * where somebody following along actually wants it.
   *
   * The text comes from the layer PDF.js built, so this needs the real renderer
   * — with the iframe fallback there is nothing to read.
   */
  var speaking = false;

  function readAloud() {
    if (!window.speechSynthesis) {
      return;
    }

    if (speaking) {
      window.speechSynthesis.cancel();
      speaking = false;

      return;
    }

    var layer = root.querySelector('.reader-text');
    var text = layer ? layer.textContent : '';

    if (!text || !text.trim()) {
      return;
    }

    // Blank lines where the page had them; failing that, sentences. A hymn's
    // verses are the unit somebody means.
    var blocks = text.split(/\n\s*\n/).filter(function (block) {
      return block.trim() !== '';
    });

    if (blocks.length < 2) {
      blocks = text.split(/(?<=[.!?])\s+/);
    }

    speaking = true;

    blocks.forEach(function (block) {
      var utterance = new SpeechSynthesisUtterance(block.trim());
      utterance.onend = function () {
        // Only the last one clears the flag, so pressing the button mid-way
        // through stops the rest rather than restarting.
        if (window.speechSynthesis.pending === false && window.speechSynthesis.speaking === false) {
          speaking = false;
        }
      };
      window.speechSynthesis.speak(utterance);
    });
  }

  on('[data-reader-aloud]', 'click', readAloud);

  // Never leave a voice talking to an empty room.
  window.addEventListener('pagehide', function () {
    if (window.speechSynthesis) {
      window.speechSynthesis.cancel();
    }
  });

  /* ------------------------------------------------------------ text size
   *
   * Per device, in localStorage. A reading size is a fact about the screen and
   * the eyes in front of it, not about an account — and somebody who sets it
   * large on a phone does not mean it on the projector.
   *
   * It scales the RENDER rather than a font, because a PDF page is a picture:
   * there is no text to enlarge, only a page to draw bigger.
   */
  var SIZE_KEY = 'portal_reader_zoom';

  function zoom(value) {
    try {
      if (value === undefined) {
        return parseFloat(window.localStorage.getItem(SIZE_KEY)) || 1;
      }

      window.localStorage.setItem(SIZE_KEY, String(value));
    } catch (e) {
      /* Storage refused. The size still applies for this visit. */
    }

    return value;
  }

  on('[data-reader-bigger]', 'click', function () {
    root.style.setProperty('--reader-zoom', String(zoom(Math.min(3, zoom() + 0.15))));
    show(page);
  });

  on('[data-reader-smaller]', 'click', function () {
    root.style.setProperty('--reader-zoom', String(zoom(Math.max(0.5, zoom() - 0.15))));
    show(page);
  });

  root.style.setProperty('--reader-zoom', String(zoom()));

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
