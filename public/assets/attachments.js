/*
 * Attach several files at once.
 *
 * Without this script the form attaches one file, and still works. With it,
 * the picker takes several; each gets a row with its own title box, pre-filled
 * with the filename minus its extension — ONLY the extension, because turning
 * underscores into spaces or fixing capitals is guessing what somebody meant
 * to call it. Rows can be removed before starting, and picking again adds to
 * the queue rather than replacing it.
 *
 * Uploaded ONE AT A TIME, one request each: the server's size limit is per
 * request, so a batch would make it worse. A refused file stays in the queue
 * with the reason and the rest carry on; attached ones leave it. So a
 * partly-failed run is retried by pressing the button again, without picking
 * everything a second time.
 */
(function () {
  'use strict';

  var form = document.getElementById('attach-form');
  var input = document.getElementById('attach-input');
  var queue = document.getElementById('attach-queue');
  var summary = document.getElementById('attach-summary');
  var submit = document.getElementById('attach-submit');
  var singleLabel = document.getElementById('attach-label-row');

  if (!form || !input || !queue || !submit || !window.fetch || !window.FormData) {
    return;
  }

  var items = [];
  var busy = false;

  input.multiple = true;
  input.required = false;
  if (singleLabel) { singleLabel.hidden = true; }

  function titleFor(name) {
    var dot = name.lastIndexOf('.');
    return dot > 0 ? name.slice(0, dot) : name;
  }

  function render() {
    var body = queue.tBodies[0];
    body.textContent = '';

    items.forEach(function (item, index) {
      var row = document.createElement('tr');

      var name = document.createElement('td');
      name.className = 'small muted';
      name.textContent = item.file.name;

      var titleCell = document.createElement('td');
      var title = document.createElement('input');
      title.type = 'text';
      title.maxLength = 180;
      title.value = item.title;
      title.setAttribute('aria-label', 'Title for ' + item.file.name);
      title.addEventListener('input', function () { item.title = title.value; });
      titleCell.appendChild(title);

      var status = document.createElement('td');
      status.className = 'small';
      status.textContent = item.error || '';

      var actions = document.createElement('td');
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn small secondary';
      remove.textContent = 'Remove';
      remove.disabled = busy;
      remove.addEventListener('click', function () {
        items.splice(index, 1);
        render();
      });
      actions.appendChild(remove);

      row.appendChild(name);
      row.appendChild(titleCell);
      row.appendChild(status);
      row.appendChild(actions);
      body.appendChild(row);
    });

    queue.hidden = items.length === 0;
    submit.textContent = items.length > 1 ? 'Attach ' + items.length + ' files' : 'Attach';
  }

  input.addEventListener('change', function () {
    Array.prototype.forEach.call(input.files, function (file) {
      items.push({ file: file, title: titleFor(file.name), error: '' });
    });
    /* Cleared so the same file can be picked again after being removed, and so
       a plain submit cannot also send whatever is still in the input. */
    input.value = '';
    render();
  });

  function send(item) {
    var data = new FormData();
    data.append('_token', form.querySelector('input[name="_token"]').value);
    data.append('id', form.querySelector('input[name="id"]').value);
    data.append('action', 'attach');
    data.append('_async', '1');
    data.append('label', item.title);
    data.append('attachment', item.file, item.file.name);

    return fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (response) {
        return response.json().catch(function () {
          /* A host-level refusal — the request was over the host's own limit,
             or the session expired — arrives as a page, not JSON. */
          return { ok: false, message: response.status === 413
            ? 'Larger than this host accepts.'
            : 'Refused by the server (' + response.status + ').' };
        });
      })
      .catch(function () {
        return { ok: false, message: 'Not sent — check your connection.' };
      });
  }

  form.addEventListener('submit', function (event) {
    if (items.length === 0) {
      /* Nothing queued: let the plain form say "choose a file". */
      return;
    }
    event.preventDefault();
    if (busy) { return; }

    busy = true;
    submit.disabled = true;
    render();

    var attached = 0;
    var queued = items.slice();

    (function next(i) {
      if (i >= queued.length) {
        busy = false;
        submit.disabled = false;
        items = queued.filter(function (item) { return item.error !== ''; });
        render();

        var failed = items.length;
        summary.textContent = (attached > 0 ? attached + ' attached. ' : '')
          + (failed > 0 ? failed + ' not attached — the reasons are beside them; fix and press again.' : '')
          + (attached > 0 ? ' Reload the page to see them in the list.' : '');
        return;
      }

      var item = queued[i];
      summary.textContent = 'Uploading ' + (i + 1) + ' of ' + queued.length + '…';

      send(item).then(function (result) {
        if (result && result.ok) {
          attached++;
          item.error = '';
        } else {
          item.error = (result && result.message) || 'Not attached.';
        }
        next(i + 1);
      });
    })(0);
  });
})();
