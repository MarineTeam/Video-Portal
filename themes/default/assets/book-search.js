/**
 * Searching every book on a shelf at once.
 *
 * This is what the stored page text is for: a whole hymnal category becomes
 * searchable without opening six PDFs, because an admin's browser already read
 * them once and posted the text back.
 *
 * The form works without this file — it submits to /books/search and the server
 * answers. This only makes the answer appear in place.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-book-search]');
  var results = document.querySelector('[data-book-results]');

  if (!form || !results) {
    return;
  }

  function text(value) {
    var node = document.createElement('span');
    node.textContent = String(value == null ? '' : value);

    return node.innerHTML;
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();

    var input = form.querySelector('input[name="q"]');
    var query = (input && input.value || '').trim();

    if (query === '') {
      results.hidden = true;

      return;
    }

    results.innerHTML = '<p class="muted small">Looking…</p>';
    results.hidden = false;

    fetch('/books/search?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(function (answer) {
        if (!answer || !answer.results || answer.results.length === 0) {
          results.innerHTML = '<p class="muted">Nothing found. Only books somebody has indexed '
            + 'can be searched.</p>';

          return;
        }

        var html = '<ul class="book-hits">';

        answer.results.forEach(function (hit) {
          /*
           * The printed number, worked out from the offset the server sent —
           * the same rule as everywhere else. The link goes by PAGE because a
           * hit is a spot in the text rather than a numbered entry, and that
           * is the honest fallback.
           */
          var printed = hit.pdf_page - hit.page_offset;

          html += '<li><a href="/books/' + text(hit.book_slug) + '?at=p' + Number(hit.pdf_page) + '">'
            + '<strong>' + text(hit.book_title) + '</strong> '
            + (printed >= 1 ? 'page ' + printed : 'front matter')
            + '</a>'
            // Said, because OCR text is good enough to search and not good
            // enough to quote — a snippet from it may be visibly wrong.
            + (hit.source === 'ocr' ? ' <span class="muted small">(read by OCR)</span>' : '')
            + '<div class="muted small">' + text(hit.snippet) + '</div></li>';
        });

        results.innerHTML = html + '</ul>';
      })
      .catch(function () {
        results.innerHTML = '<p class="muted">The search did not answer.</p>';
      });
  });
})();
