/*
 * Reply targeting.
 *
 * The form is posted normally — no fetch, no JSON, no client-side rendering.
 * With this file blocked or broken the thread still reads and comments still
 * post; the only thing lost is that replies become top-level comments. That is
 * the right failure for a feature whose whole job is letting people speak.
 */

(function () {
  'use strict';

  var form = document.querySelector('.comment-form');
  if (!form) {
    return;
  }

  var parentField = document.getElementById('comment-parent');
  var notice = form.querySelector('.comment-replying');
  var textarea = document.getElementById('comment-body');
  var cancel = document.getElementById('comment-cancel-reply');

  // Delegated, so it keeps working for comments added to the page later.
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.comment-reply-btn');
    if (!button) {
      return;
    }

    parentField.value = button.getAttribute('data-reply-to') || '';
    notice.hidden = false;

    // Moved to the comment being answered rather than scrolling the page to
    // the form: keeping the thing you are replying to on screen is the whole
    // point of a reply.
    button.closest('.comment').appendChild(form);
    textarea.focus();
  });

  if (cancel) {
    cancel.addEventListener('click', function () {
      parentField.value = '';
      notice.hidden = true;
      document.querySelector('.comments').insertBefore(
        form,
        document.querySelector('.comment-list')
      );
      textarea.focus();
    });
  }
})();
