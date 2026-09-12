/**
 * The television waiting to be let in.
 *
 * It polls /tv/pair/poll with the device code the page was rendered with, and
 * when somebody says yes the response carries a session cookie and the
 * television goes to its own page.
 *
 * IT HONOURS THE SERVER'S INTERVAL, which RFC 8628 requires and which matters
 * here for a plain reason: this page is left up on a screen in a foyer all
 * week, and a television polling every second is 86,400 requests a day at a
 * shared host that is also serving the actual site.
 *
 * It also STOPS. A pairing lives ten minutes; after that the code on the screen
 * is dead and polling it is pure waste, so the script says so and asks the
 * person to reload rather than quietly asking forever.
 */
(function () {
    'use strict';

    var node = document.querySelector('[data-tv-pairing]');
    var state = document.querySelector('[data-tv-state]');

    if (!node) {
        return;
    }

    var device = node.getAttribute('data-device') || '';
    var interval = (parseInt(node.getAttribute('data-interval') || '5', 10) || 5) * 1000;
    var expires = (parseInt(node.getAttribute('data-expires') || '600', 10) || 600) * 1000;

    if (device === '') {
        return;
    }

    var startedAt = Date.now();
    var stopped = false;

    function say(text) {
        if (state) {
            state.textContent = text;
        }
    }

    function stop(text) {
        stopped = true;
        say(text);
    }

    function poll() {
        if (stopped) {
            return;
        }

        /* Give up at the code's own lifetime rather than polling a dead
           pairing. The server would keep answering expired_token, correctly and
           for ever, and a screen in a foyer would ask it all week. */
        if (Date.now() - startedAt > expires) {
            stop('This code has expired. Reload the page for a new one.');
            return;
        }

        var body = new FormData();
        body.append('device_code', device);

        fetch('/tv/pair/poll', { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) { return response.json(); })
            .then(function (answer) {
                if (answer.ok && answer.next) {
                    /* The session cookie arrived with this response, so the
                       next navigation is authenticated. */
                    stop('Signed in. One moment.');
                    window.location.href = answer.next;

                    return;
                }

                switch (answer.error) {
                    case 'authorization_pending':
                        /* Nothing said on screen while waiting. The code is
                           what the person is reading, and a line of status text
                           changing every five seconds pulls the eye away from
                           it. */
                        say('');
                        break;

                    case 'expired_token':
                        stop('This code has expired. Reload the page for a new one.');

                        return;

                    default:
                        /* access_denied covers claimed and blocked, and both
                           mean start again — so the honest instruction is the
                           same one. */
                        stop('This code can no longer be used. Reload the page for a new one.');

                        return;
                }

                /* The server may raise its own interval. Honoured, per the
                   specification, rather than kept at whatever the page loaded
                   with. */
                if (typeof answer.interval === 'number' && answer.interval > 0) {
                    interval = Math.max(interval, answer.interval * 1000);
                }

                window.setTimeout(poll, interval);
            })
            .catch(function () {
                /* A failed poll says nothing and simply waits longer. A
                   television on a foyer wifi loses its connection regularly,
                   and an error message on a screen the whole congregation walks
                   past is worse than a few seconds of silence.

                   Backed off to twice the interval so a network that is
                   genuinely down is not hammered. */
                window.setTimeout(poll, interval * 2);
            });
    }

    window.setTimeout(poll, interval);
})();
