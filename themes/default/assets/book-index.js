/**
 * Reading a book's text out, in the admin's browser.
 *
 * # WHY HERE AND NOT ON THE SERVER
 *
 * Parsing a PDF needs a PDF library and OCR needs a whole recognition engine.
 * Shared hosting has neither and cannot be given them at any price — no shell,
 * no extensions, and an execution limit measured in seconds. The machine an
 * administrator is sitting at has a PDF engine already loaded (it is rendering
 * the book) and is otherwise idle.
 *
 * So the browser reads, and the server only stores. That is the same division
 * the spec asks for and it is the only one that works on the hosts this ships
 * to.
 *
 * # IT POSTS AS IT GOES
 *
 * A four-hundred-page hymnal takes minutes. Posting once at the end means a
 * closed tab loses all of it; posting per batch means a closed tab loses the
 * last batch and the rest is already stored. The screen can then say how far it
 * got rather than "it did not work".
 *
 * # THE TEXT LAYER FIRST, OCR ONLY WHERE THERE IS NONE
 *
 * Most PDFs carry their text. Reading it is instant and exact. OCR is slow and
 * approximate and is only worth reaching for on a page that has nothing —
 * a scanned hymnal — which is why the source is recorded per page: OCR text is
 * good enough to search and not good enough to quote.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-book-index]');

  if (!root) {
    return;
  }

  var bookId = root.getAttribute('data-book');
  var token = root.getAttribute('data-token');
  var fileUrl = root.getAttribute('data-file');
  var button = root.querySelector('[data-index-start]');
  var status = root.querySelector('[data-index-status]');
  var ocrBox = root.querySelector('[data-index-ocr]');

  /** How many pages go in one post. Small enough to lose little, large
   *  enough not to make a request per page of a long book. */
  var BATCH = 20;

  function say(message) {
    if (status) {
      status.textContent = message;
    }
  }

  function post(payload) {
    return fetch('/admin/books/' + encodeURIComponent(bookId) + '/index', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token
      },
      body: JSON.stringify(payload)
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('The site would not store that (' + response.status + ').');
      }

      return response.json();
    });
  }

  /**
   * The contents, from the PDF's own bookmarks.
   *
   * A hymnal usually has them and they are exactly the entries wanted. Where
   * there are none this posts nothing rather than inventing entries — a
   * fabricated contents list is worse than an empty one, because "go to hymn
   * 214" would then land somewhere confidently wrong.
   */
  function readOutline(pdf) {
    return pdf.getOutline().then(function (outline) {
      if (!outline || outline.length === 0) {
        return [];
      }

      var entries = [];

      function walk(nodes, depth) {
        return nodes.reduce(function (chain, node) {
          return chain.then(function () {
            return pdf.getPageIndex(node.dest && node.dest[0] ? node.dest[0] : null)
              .then(function (index) {
                var title = (node.title || '').trim();

                // A leading number is how a hymnal's bookmarks are written:
                // "27. Abide with me". Taken as the hymn number, and the title
                // keeps the rest.
                var match = title.match(/^(\d{1,4})[.\s)-]+(.+)$/);

                entries.push({
                  number: match ? parseInt(match[1], 10) : null,
                  title: match ? match[2] : title,
                  pdf_page: index + 1,
                  depth: depth
                });
              })
              .catch(function () {
                /* A bookmark pointing nowhere is skipped, not fatal. */
              })
              .then(function () {
                return node.items && node.items.length ? walk(node.items, depth + 1) : null;
              });
          });
        }, Promise.resolve());
      }

      return walk(outline, 0).then(function () {
        return entries;
      });
    }).catch(function () {
      return [];
    });
  }

  /** The text of one page, or '' where the page carries none. */
  function readPage(pdf, number) {
    return pdf.getPage(number)
      .then(function (page) {
        return page.getTextContent();
      })
      .then(function (content) {
        return content.items.map(function (item) {
          return item.str;
        }).join(' ').replace(/\s+/g, ' ').trim();
      })
      .catch(function () {
        return '';
      });
  }

  /**
   * OCR one page, by drawing it and handing the picture to Tesseract.
   *
   * Only reached for a page with no text layer, and only when the engine is
   * present — it is a large thing to ship and a site that never scans anything
   * does not need it. Absent, the page is simply left unindexed, which is
   * honest: an empty result and a page nobody could read are the same to a
   * search box, and the screen says how many were skipped.
   */
  /**
   * One recogniser, reused.
   *
   * Creating a worker per page reloads four megabytes of engine each time and
   * is how a four-hundred-page book takes an afternoon instead of an hour.
   */
  var recogniser = null;

  function ocrWorker() {
    if (recogniser) {
      return recogniser;
    }

    /*
     * Every path is local. tesseract.js defaults to jsdelivr for the core AND
     * the language data — left alone, OCR stops working whenever that CDN is
     * blocked, and it tells a browser somewhere else which books this site is
     * indexing.
     */
    recogniser = window.Tesseract.createWorker('eng', 1, {
      workerPath: '/assets/vendor/tesseract/worker.min.js',
      corePath: '/assets/vendor/tesseract',
      langPath: '/assets/vendor/tesseract/lang',
      // The data is committed gzipped, which is how it comes.
      gzip: true
    });

    return recogniser;
  }

  function ocrPage(pdf, number) {
    if (!window.Tesseract) {
      return Promise.resolve('');
    }

    return pdf.getPage(number).then(function (page) {
      var viewport = page.getViewport({ scale: 2 });
      var canvas = document.createElement('canvas');
      canvas.width = Math.floor(viewport.width);
      canvas.height = Math.floor(viewport.height);

      return page.render({
        canvasContext: canvas.getContext('2d'),
        viewport: viewport
      }).promise.then(function () {
        return ocrWorker();
      }).then(function (worker) {
        return worker.recognize(canvas);
      }).then(function (result) {
        return ((result && result.data && result.data.text) || '').replace(/\s+/g, ' ').trim();
      });
    }).catch(function () {
      // A page the engine cannot read is one page, not the book. It is counted
      // as blank and reported with the rest.
      return '';
    });
  }

  function run() {
    if (!window.pdfjsLib) {
      say('The PDF library did not load, so nothing can be read out of this book.');

      return;
    }

    button.disabled = true;
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.js';

    var wantOcr = ocrBox && ocrBox.checked;
    var stored = 0;
    var ocrPages = 0;
    var blank = 0;

    say('Opening the book…');

    window.pdfjsLib.getDocument({ url: fileUrl, withCredentials: true }).promise
      .then(function (pdf) {
        return readOutline(pdf).then(function (contents) {
          return post({ contents: contents, page_count: pdf.numPages }).then(function () {
            say(contents.length
                ? contents.length + ' contents entries stored. Reading the pages…'
                : 'This file has no bookmarks, so no contents were stored. Reading the pages…');

            return pdf;
          });
        });
      })
      .then(function (pdf) {
        var batch = {};
        var inBatch = 0;

        function flush() {
          if (inBatch === 0) {
            return Promise.resolve();
          }

          var payload = { pages: batch };
          batch = {};
          inBatch = 0;

          return post(payload);
        }

        // Sequential rather than parallel. Rendering and recognising are both
        // heavy, and twenty at once is how a tab is killed for memory.
        var chain = Promise.resolve();

        for (var n = 1; n <= pdf.numPages; n++) {
          (function (number) {
            chain = chain.then(function () {
              return readPage(pdf, number).then(function (text) {
                if (text !== '') {
                  batch[number] = { body: text, source: 'text' };
                  stored++;
                  inBatch++;

                  return;
                }

                if (!wantOcr) {
                  blank++;

                  return;
                }

                return ocrPage(pdf, number).then(function (recognised) {
                  if (recognised === '') {
                    blank++;

                    return;
                  }

                  batch[number] = { body: recognised, source: 'ocr' };
                  stored++;
                  ocrPages++;
                  inBatch++;
                });
              }).then(function () {
                say('Read ' + number + ' of ' + pdf.numPages + ' pages…');

                return inBatch >= BATCH ? flush() : null;
              });
            });
          })(n);
        }

        return chain.then(flush).then(function () {
          return post({ done: true });
        }).then(function () {
          // Four megabytes of engine held open after the last page is four
          // megabytes doing nothing.
          if (recogniser) {
            recogniser.then(function (worker) { worker.terminate(); }).catch(function () {});
            recogniser = null;
          }
        }).then(function () {
          say(
            'Done. ' + stored + ' page(s) stored'
            + (ocrPages ? ', ' + ocrPages + ' of them read by OCR' : '')
            // Reported rather than swallowed: a book with two hundred unread
            // pages searches like a book with two hundred blank ones, and an
            // administrator needs to know which they have.
            + (blank ? ', ' + blank + ' page(s) had no text this could read' : '')
            + '.'
          );
          button.disabled = false;
        });
      })
      .catch(function (error) {
        say(error && error.message ? error.message : 'That did not finish.');
        button.disabled = false;
      });
  }

  button.addEventListener('click', run);
})();
