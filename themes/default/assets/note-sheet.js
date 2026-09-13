/*
 * Note sheet: save as it is typed.
 *
 * Somebody filling this in is listening to a talk at the same time, and a Save
 * button they forget to press is the whole sheet lost. So every change is
 * saved shortly after typing stops, and again when the page is hidden. The
 * Save button stays for when this script does not run.
 */
(function () {
  'use strict';

  var form = document.getElementById('note-sheet');
  if (!form) { return; }

  var print = form.querySelector('[data-sheet-print]');
  if (print) {
    print.hidden = false;
    print.addEventListener('click', function () { window.print(); });
  }

  if (form.dataset.save !== '1') { return; }

  var status = document.getElementById('sheet-status');
  var token = form.querySelector('input[name="_token"]');
  var version = form.querySelector('input[name="version"]');
  var timer = null;

  function values() {
    return Array.prototype.map.call(
      form.querySelectorAll('input.sheet-gap'),
      function (input) { return input.value; }
    );
  }

  var lastSaved = JSON.stringify(values());

  function say(text) {
    if (status) { status.textContent = text; }
  }

  function save() {
    var answers = values();
    var body = JSON.stringify(answers);
    if (body === lastSaved) { return; }

    fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token ? token.value : ''
      },
      body: JSON.stringify({ answers: answers, version: version ? parseInt(version.value, 10) : 0 })
    }).then(function (response) {
      if (!response.ok) { throw new Error('refused'); }
      lastSaved = body;
      say('Saved');
    }).catch(function () {
      /* Out loud: an answer that silently failed to save is one somebody
         stops writing down on paper, trusting it. */
      say('Not saved — check your connection');
    });
  }

  form.addEventListener('input', function () {
    say('');
    if (timer) { clearTimeout(timer); }
    timer = setTimeout(save, 1000);
  });

  window.addEventListener('pagehide', save);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { save(); }
  });
})();
