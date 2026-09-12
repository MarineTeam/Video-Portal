/**
 * Live chat for a stream.
 *
 * IT POLLS. There is no socket, because the hosts this product runs on give you
 * PHP behind Apache and nothing that outlives a request — see the migration.
 *
 * Everything here is written around that one constraint:
 *
 *   The cursor is the last message id seen, so a poll asks for what it has not
 *   got rather than for "the last N", and can neither repeat a message nor miss
 *   one that arrived between two requests.
 *
 *   The interval BACKS OFF when the tab is hidden and when nothing is
 *   happening. A hundred people with the page open on a Monday is a hundred
 *   pointless requests every few seconds at a shared host, and a chat left open
 *   in a background tab is the commonest way that happens.
 *
 *   The window state comes back on every poll, so a page that has been open
 *   since before the stream finds out when it may post, and one open an hour
 *   afterwards finds out that it may not, without anybody reloading.
 *
 * Progressive enhancement, as everywhere in this theme: with the script blocked
 * the form still posts, the page reloads, and the message is sent. What is lost
 * is the live updating, which is the thing that genuinely needs a script.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-chat]');

    if (!root) {
        return;
    }

    var log = root.querySelector('[data-chat-log]');
    var form = root.querySelector('[data-chat-form]');
    var input = form ? form.querySelector('[data-chat-input]') : null;
    var button = form ? form.querySelector('button') : null;
    var notice = root.querySelector('[data-chat-notice]');

    var endpoint = root.getAttribute('data-chat');
    var token = root.getAttribute('data-token') || '';
    var canModerate = root.getAttribute('data-moderate') === '1';

    /* The last id seen. 0 means "I have just arrived", which is the only
       request the server answers with history. */
    var cursor = parseInt(root.getAttribute('data-cursor') || '0', 10) || 0;

    /* Poll pacing. FAST while the room is active, slowing to IDLE after a
       stretch with nothing new, and stopping altogether when the window closes
       — a closed room cannot gain a message, so continuing to ask is the purest
       waste available. */
    var FAST = 4000;
    var IDLE = 12000;
    var HIDDEN = 30000;
    var quiet = 0;
    var timer = null;
    var stopped = false;

    function delay() {
        if (document.hidden) {
            return HIDDEN;
        }

        return quiet >= 5 ? IDLE : FAST;
    }

    function schedule() {
        if (timer) {
            clearTimeout(timer);
        }

        if (stopped) {
            return;
        }

        timer = setTimeout(poll, delay());
    }

    function atBottom() {
        /* Whether to follow new messages. Somebody who has scrolled up is
           reading something, and yanking them to the bottom every four seconds
           makes that impossible — the commonest complaint about every chat that
           gets this wrong. 40px of slack so "nearly at the bottom" counts. */
        return log.scrollHeight - log.scrollTop - log.clientHeight < 40;
    }

    function escape(text) {
        var node = document.createElement('span');
        node.textContent = text;

        return node.innerHTML;
    }

    function draw(message) {
        var row = document.createElement('div');
        row.className = 'chat-message' + (message.mine ? ' chat-mine' : '');
        row.setAttribute('data-message', String(message.id));

        var html = '<span class="chat-author">' + escape(message.author) + '</span>'
            + '<span class="chat-body">' + escape(message.body) + '</span>';

        /* The hide button is drawn for a moderator on everybody else's
           messages. Not on their own, because a moderator hiding themselves is
           not a moderation action and the button in that position is just a
           delete they do not have. */
        if (canModerate && !message.mine) {
            html += ' <button type="button" class="chat-hide" data-hide="' + String(message.id)
                + '" title="Hide this message">hide</button>'
                + ' <button type="button" class="chat-mute" data-mute="' + String(message.id)
                + '" title="Stop this person posting in this stream">mute</button>';
        }

        row.innerHTML = html;

        return row;
    }

    function say(text, kind) {
        if (!notice) {
            return;
        }

        notice.textContent = text || '';
        notice.className = 'chat-notice' + (kind ? ' ' + kind : '');
    }

    function close(reason) {
        stopped = true;

        if (form) {
            form.hidden = true;
        }

        say(reason, 'muted');
    }

    function apply(data) {
        var messages = data.messages || [];
        var follow = atBottom();

        messages.forEach(function (message) {
            /* Replace rather than append when the id is already drawn. A
               moderator's own page re-polls after hiding something, and the
               ordinary poll no longer carries it — so the row is removed
               separately, below. This guard is for the duplicate that a retried
               request would otherwise produce. */
            var existing = log.querySelector('[data-message="' + String(message.id) + '"]');

            if (existing) {
                existing.replaceWith(draw(message));
            } else {
                log.appendChild(draw(message));
            }
        });

        if (messages.length > 0) {
            quiet = 0;
        } else {
            quiet += 1;
        }

        if (typeof data.cursor === 'number' && data.cursor > cursor) {
            cursor = data.cursor;
        }

        if (follow) {
            log.scrollTop = log.scrollHeight;
        }

        if (data.window && data.window !== 'open') {
            close(data.closed || '');
        }
    }

    function poll() {
        fetch(endpoint + '?after=' + String(cursor), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (response) {
                if (!response.ok) {
                    /* A failed poll is not reported to the reader.
                       A stream watched on a phone loses its connection
                       regularly, and an error line appearing in the middle of a
                       service reads as the site being broken when the correct
                       behaviour is simply to ask again. */
                    throw new Error(String(response.status));
                }

                return response.json();
            })
            .then(apply)
            .catch(function () {
                quiet += 1;
            })
            .then(schedule);
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var body = (input.value || '').trim();

            if (body === '') {
                return;
            }

            var data = new FormData();
            data.append('_token', token);
            data.append('body', body);

            if (button) {
                button.disabled = true;
            }

            fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.json(); })
                .then(function (answer) {
                    if (answer.ok) {
                        /* Cleared only on success. A refusal keeps what they
                           typed, because the commonest refusal is slow mode and
                           making somebody retype a sentence they are allowed to
                           send in four seconds is the rudest possible reading of
                           "wait". */
                        input.value = '';
                        say('', '');

                        /* Poll immediately rather than waiting out the
                           interval, so your own message appears at once. It
                           comes back through the poll like everybody else's —
                           drawing it locally would mean two code paths for the
                           same row, and the optimistic one is always the one
                           that gets the id wrong. */
                        poll();

                        return;
                    }

                    say(answer.message || 'That did not send.', 'warn');

                    if (answer.wait > 0) {
                        /* Re-enabled when the wait is up, so the button is not
                           dead for longer than the rule requires. */
                        setTimeout(function () {
                            if (button) {
                                button.disabled = false;
                            }
                        }, answer.wait * 1000);

                        return;
                    }
                })
                .catch(function () {
                    say('That did not send. Try again.', 'warn');
                })
                .then(function () {
                    if (button && !button.disabled) {
                        return;
                    }

                    /* The wait branch above owns re-enabling; everything else
                       re-enables now. */
                    if (button) {
                        button.disabled = false;
                    }
                });
        });
    }

    if (canModerate) {
        log.addEventListener('click', function (event) {
            var hide = event.target.closest('[data-hide]');
            var mute = event.target.closest('[data-mute]');
            var id = hide
                ? hide.getAttribute('data-hide')
                : (mute ? mute.getAttribute('data-mute') : '');

            if (!id) {
                return;
            }

            if (mute && !window.confirm('Stop this person posting in this stream?')) {
                /* Confirmed, because a mute is about a person and a misclick
                   silences somebody for the rest of the service. Hiding is not
                   confirmed: it is reversible, and a dialog in the middle of a
                   busy room is what stops a moderator acting quickly. */
                return;
            }

            var data = new FormData();
            data.append('_token', token);
            data.append('action', mute ? 'mute' : 'hide');
            data.append('message', id);

            fetch(endpoint + '/moderate', {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
                .then(function (response) { return response.json(); })
                .then(function (answer) {
                    say(answer.message || '', answer.ok ? 'ok' : 'warn');

                    if (answer.ok) {
                        var row = log.querySelector('[data-message="' + id + '"]');

                        if (row) {
                            row.remove();
                        }
                    }
                })
                .catch(function () {
                    say('That did not work. Try again.', 'warn');
                });
        });
    }

    /* Re-poll straight away when the tab comes back, rather than waiting out
       the thirty-second hidden interval — somebody switching back to the stream
       wants the room as it is now, not as it was half a minute ago. */
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !stopped) {
            poll();
        }
    });

    log.scrollTop = log.scrollHeight;
    schedule();
})();
