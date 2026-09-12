<?php
/**
 * What is saved on this device.
 *
 * The only screen in this application that ships empty. Everything on it lives
 * in Cache Storage in this browser and the server has no idea what is there —
 * so the markup is a shell, and offline.js fills it in.
 *
 * That is stated on the page rather than left to be discovered. A list that is
 * full on a phone and empty on a laptop looks like a bug in every other screen
 * here, and somebody who does not know it is per-device will report it as one.
 *
 * @var \Portal\Themes\TemplateLoader $template
 */

declare(strict_types=1);

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Saved for offline</h1>
<p class="page-subtitle"><a href="/account">Your account</a></p>

<div id="offline-unsupported" class="notice error" hidden>
  This browser cannot save videos for offline viewing. It needs support for service
  workers and Cache Storage, which private-browsing windows often switch off.
</div>

<div id="offline-space" class="muted small" hidden></div>

<div id="offline-empty" class="empty" hidden>
  <p>Nothing is saved on this device.</p>
  <p class="muted small">
    A <strong>Save for offline</strong> button appears under a video when you are
    allowed to download it.
  </p>
</div>

<div id="offline-list"></div>

<?php
/*
 * BOOKS ARE NOT VIDEOS, and the difference has to be on the screen.
 *
 * A saved video plays with the network off. A saved book still asks the site
 * whether you may read it, every time it opens — so saving one buys speed and
 * bandwidth, not availability.
 *
 * Somebody who saves a hymnal for a hall with no signal and finds it will not
 * open has been let down by this page, not by the reader. So the two are
 * listed apart and the difference is stated where it is read, rather than
 * being left in the reader's source.
 */
?>
<h2 class="section-title">Books saved here</h2>

<div class="notice">
  <strong>A saved book still needs a connection to open.</strong>
  <p class="muted small">Saving one means it opens straight away and does not use your data
     again — but the site checks you are still allowed to read it each time, so unlike a saved
     video it will not open with no signal at all. That is deliberate: who may read a book is a
     question about right now.</p>
</div>

<div id="offline-books-empty" class="empty" hidden>
  <p>No books are saved on this device.</p>
  <p class="muted small">A <strong>Save this book</strong> button appears on a book you can read.</p>
</div>

<div id="offline-books"></div>

<h2 class="section-title">Downloading</h2>

<label class="check">
  <input type="checkbox" id="offline-wifi">
  Only download when I am not on mobile data
</label>
<p class="muted small">Kept on this device, because it is a fact about this device and its tariff
   rather than about you — the same person on a laptop usually means something different by it.
   Browsers are vague about what kind of connection they are on, so when yours will not say, a
   download you asked for goes ahead rather than being refused on a guess.</p>

<template id="offline-book-row">
  <div class="card" style="margin-bottom:1rem;padding:1rem 1.25rem">
    <h3 class="section-title" style="margin-top:0"><a data-open href="#"></a></h3>
    <p class="muted small" data-detail></p>
    <p style="margin:.75rem 0 0">
      <button class="btn secondary" data-delete-book>Delete from this device</button>
    </p>
  </div>
</template>

<p class="muted small" style="margin-top:2rem">
  This list is kept by this browser, not by the site. It will look different on
  another device, and clearing your browsing data removes everything in it — the
  site is never told what you have saved.
</p>

<template id="offline-row">
  <div class="card" style="margin-bottom:1rem;padding:1rem 1.25rem">
    <h2 class="section-title" style="margin-top:0"><a data-watch href="#"></a></h2>
    <p class="muted small" data-detail></p>
    <video data-player controls preload="none" style="width:100%;max-width:640px"></video>
    <p style="margin:.75rem 0 0">
      <button class="btn secondary" data-delete>Delete from this device</button>
    </p>
  </div>
</template>

<script src="<?= e(asset_url('/assets/offline.js')) ?>" defer></script>
<script defer>
window.addEventListener('DOMContentLoaded', function () {
  var api = window.PortalOffline;
  var list = document.getElementById('offline-list');
  var empty = document.getElementById('offline-empty');
  var space = document.getElementById('offline-space');
  var row = document.getElementById('offline-row');

  if (!api || !api.supported()) {
    document.getElementById('offline-unsupported').hidden = false;
    return;
  }

  function showSpace() {
    api.space().then(function (estimate) {
      if (!estimate || !estimate.quota) { return; }
      space.hidden = false;
      // Approximate on purpose: every browser fuzzes these numbers, because an
      // exact quota is a fingerprinting surface.
      space.textContent = 'Using about ' + api.bytes(estimate.used)
        + ' of roughly ' + api.bytes(estimate.quota) + ' this browser will allow.';
    });
  }

  function render() {
    api.list().then(function (rows) {
      list.textContent = '';
      empty.hidden = rows.length > 0;

      rows.forEach(function (item) {
        var node = row.content.cloneNode(true);

        var link = node.querySelector('[data-watch]');
        link.textContent = item.title || 'Untitled';
        link.href = '/watch/' + encodeURIComponent(item.slug || '');

        node.querySelector('[data-detail]').textContent =
          api.bytes(item.bytes) + (item.height ? ' · ' + item.height + 'p' : '')
          + ' · saved ' + new Date(item.savedAt || Date.now()).toLocaleDateString();

        // Points at the worker, not the network. Playing it with the machine
        // offline is the whole point, so it must never reach for the site.
        node.querySelector('[data-player]').src = item.src;

        node.querySelector('[data-delete]').addEventListener('click', function () {
          api.remove(item.src).then(function () {
            render();
            showSpace();
          });
        });

        list.appendChild(node);
      });
    });
  }

  /*
   * The books, listed apart from the videos.
   *
   * Apart rather than mixed in, because the two behave differently with no
   * signal and a single list would imply they do not.
   */
  var books = document.getElementById('offline-books');
  var booksEmpty = document.getElementById('offline-books-empty');
  var bookRow = document.getElementById('offline-book-row');

  function renderBooks() {
    api.listBooks().then(function (rows) {
      books.textContent = '';
      booksEmpty.hidden = rows.length > 0;

      rows.forEach(function (item) {
        var node = bookRow.content.cloneNode(true);

        var link = node.querySelector('[data-open]');
        link.textContent = item.slug || 'A book';
        link.href = '/books/' + encodeURIComponent(item.slug || '');

        node.querySelector('[data-detail]').textContent =
          api.bytes(item.bytes)
          + (item.pages ? ' · ' + item.pages + ' pages' : '')
          + ' · saved ' + new Date(item.savedAt || Date.now()).toLocaleDateString();

        node.querySelector('[data-delete-book]').addEventListener('click', function () {
          api.removeBook(item.slug).then(function () {
            renderBooks();
            showSpace();
          });
        });

        books.appendChild(node);
      });
    });
  }

  var wifi = document.getElementById('offline-wifi');
  wifi.checked = api.wifiOnly();
  wifi.addEventListener('change', function () {
    api.wifiOnly(wifi.checked);
  });

  // Half-finished saves first, so the list never offers a file that is not
  // there. An interrupted download leaves bytes with no metadata.
  //
  // showSpace runs after BOTH lists, because the figure it reports counts
  // every cache this origin holds — a total that named only the videos would
  // be wrong by however many books are saved.
  api.sweep().then(render).then(renderBooks).then(showSpace);
});
</script>

<?= $template->partial('footer', get_defined_vars()) ?>
