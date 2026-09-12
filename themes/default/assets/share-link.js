/**
 * Copying a link to this video, or to a moment in it.
 *
 * AN ENHANCEMENT over a page that already works. With this blocked, the address
 * sits in a readonly box somebody can select, and "Share at" is a GET form that
 * reloads the page at that moment — whose address bar is then the link. What
 * this adds is the clipboard, and "from where I am".
 *
 * IT DOES NOT PARSE THE TIME. The field's `pattern` attribute is rendered from
 * Portal\Support\Timestamp::PATTERN, so `checkValidity()` asks the browser to
 * run the SERVER'S regular expression — and what is copied is exactly what was
 * typed, for the server to read. A JavaScript copy of the rule would eventually
 * disagree about "1:5", and the copied link would open somewhere different from
 * where the person who copied it believes.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-share-at]');
    var status = document.querySelector('[data-share-status]');

    function say(text) {
        if (status) {
            status.textContent = text;
        }
    }

    /*
     * The clipboard, with an honest fallback.
     *
     * navigator.clipboard needs a secure context and a user gesture, and is
     * refused outright in some embedded browsers. When it is unavailable the
     * link is put in the address box and selected, so the next thing somebody
     * presses — Ctrl+C, or "copy" on a long-press — does the job. Saying "copied"
     * when nothing was copied is the failure to avoid: the person then pastes
     * whatever was on their clipboard before.
     */
    function copy(text, done) {
        var box = document.querySelector('[data-share-address]');

        function fallback() {
            if (box) {
                box.value = text;
                box.focus();
                box.select();
            }

            say('Selected — copy it with your keyboard or long-press menu.');
        }

        if (!navigator.clipboard || !window.isSecureContext) {
            fallback();
            return;
        }

        navigator.clipboard.writeText(text).then(function () {
            say(done);
        }, fallback);
    }

    // The chapter copy buttons, revealed only now that they can work.
    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (button) {
        button.hidden = false;

        button.addEventListener('click', function () {
            copy(button.getAttribute('data-copy') || '', 'Copied a link to that chapter.');
        });
    });

    if (!form) {
        return;
    }

    var time = form.querySelector('[data-share-at-time]');
    var copyButton = form.querySelector('[data-share-at-copy]');
    var hereButton = form.querySelector('[data-share-at-here]');
    var base = form.getAttribute('action') || '';

    if (copyButton) {
        copyButton.hidden = false;

        copyButton.addEventListener('click', function () {
            var typed = (time.value || '').trim();

            if (typed === '') {
                copy(base, 'Copied a link to the video.');
                return;
            }

            // The server's own pattern, run by the browser. See the note at the
            // top of this file.
            time.value = typed;

            if (!time.checkValidity()) {
                say('That is not a time this can use. Try 12:30, or seconds on their own.');
                time.focus();
                return;
            }

            copy(base + '?t=' + encodeURIComponent(typed).replace(/%3A/g, ':'), 'Copied a link to ' + typed + '.');
        });
    }

    /*
     * "From where I am" — fills the field with the player's current position.
     *
     * In plain SECONDS, deliberately, rather than formatted as m:ss: formatting
     * here would be a second implementation of Timestamp::format(), and plain
     * seconds is a form the server already reads. Shown only when the player
     * exposes a position at all, which it does not for an embed it cannot talk
     * to — a button that fills in "0" would be a button that lies.
     */
    /*
     * Checked on DOMContentLoaded, not now. player.js is a later deferred script
     * on the watch page, and deferred scripts run in document order — so at the
     * moment this file runs, window.portalPlayer does not exist yet and the
     * button would never appear. DOMContentLoaded fires after every deferred
     * script has run, which is the first moment the question has an answer.
     */
    document.addEventListener('DOMContentLoaded', function () {
        if (!hereButton || !window.portalPlayer || typeof window.portalPlayer.position !== 'function') {
            return;
        }

        hereButton.hidden = false;

        hereButton.addEventListener('click', function () {
            var at = window.portalPlayer.position();

            if (!at) {
                say('Start the video first — there is no moment to share yet.');
                return;
            }

            time.value = String(at);
            say('Set to where you are. Copy the link, or change the time first.');
        });
    });
})();
