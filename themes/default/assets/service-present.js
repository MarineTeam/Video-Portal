/**
 * Moving between slides on the screen at the front.
 *
 * Everything is already in the page — see the template — so this fetches
 * nothing and works with the wifi unplugged, which is the state a church hall
 * projector is regularly in.
 *
 * It is an ENHANCEMENT over working links. With the script blocked, Back and
 * Next are ordinary hrefs carrying ?at=, each loading a page that shows one
 * slide. What the script buys is not the feature but the absence of a page load
 * between hymns — on a projector, a reload is a visible flash of black in front
 * of the whole building.
 *
 * INPUTS, in the order they matter:
 *
 *   A television remote, which sends Left and Right arrows and nothing else.
 *   That is why the buttons are focusable links with a visible focus ring and
 *   why the arrows are bound here rather than only click.
 *
 *   A presentation clicker, which sends Page Up and Page Down.
 *
 *   A finger, on a tablet propped at the front.
 */
(function () {
    'use strict';

    var stage = document.querySelector('[data-present]');

    if (!stage) {
        return;
    }

    var slides = Array.prototype.slice.call(stage.querySelectorAll('[data-slide]'));
    var count = document.querySelector('[data-present-count]');
    var prev = document.querySelector('[data-present-prev]');
    var next = document.querySelector('[data-present-next]');
    var full = document.querySelector('[data-present-full]');

    var at = parseInt(stage.getAttribute('data-at') || '0', 10) || 0;

    if (slides.length === 0) {
        return;
    }

    function show(index) {
        /* Clamped HERE, unlike PresentPlan::at() on the server, and the
           difference is deliberate. On the server an out-of-range position is a
           stale link and belongs at the beginning where it is obviously a
           start. Here it is somebody holding the arrow down at the end of the
           service, and stopping on the last slide is what they mean — going
           back to the first hymn would be alarming. */
        index = Math.max(0, Math.min(slides.length - 1, index));
        at = index;

        slides.forEach(function (slide, i) {
            /* `hidden` rather than a class, matching the server-rendered
               markup: one mechanism for hiding a slide, so a slide cannot end
               up hidden by the attribute and shown by the class. */
            slide.hidden = i !== index;
        });

        if (count) {
            count.textContent = String(index + 1) + ' of ' + String(slides.length);
        }

        /* The links keep working and keep pointing somewhere true, so a
           right-click-copy or a reload lands on the slide being shown. */
        if (prev) {
            prev.href = prev.href.replace(/at=\d+/, 'at=' + String(Math.max(0, index - 1)));
        }

        if (next) {
            next.href = next.href.replace(
                /at=\d+/,
                'at=' + String(Math.min(slides.length - 1, index + 1))
            );
        }

        /* The address bar follows without a navigation, so reloading a
           projector mid-service comes back to the same slide instead of the
           first one. replaceState rather than pushState: forty hymns would
           otherwise put forty entries in the history of a machine nobody is
           browsing on. */
        if (window.history && window.history.replaceState) {
            try {
                window.history.replaceState({}, '', '?at=' + String(index));
            } catch (e) {
                /* A file:// or sandboxed context throws. The slide has already
                   changed, which is the part that matters. */
            }
        }
    }

    function move(delta) {
        show(at + delta);
    }

    [prev, next].forEach(function (control, index) {
        if (!control) {
            return;
        }

        control.addEventListener('click', function (event) {
            event.preventDefault();
            move(index === 0 ? -1 : 1);
        });
    });

    document.addEventListener('keydown', function (event) {
        switch (event.key) {
            case 'ArrowRight':
            case 'ArrowDown':
            case 'PageDown':
            case ' ':
            case 'Enter':
                /* Enter and Space only when nothing is focused, so pressing
                   Enter on the Leave link leaves rather than advancing. */
                if ((event.key === ' ' || event.key === 'Enter')
                    && document.activeElement
                    && document.activeElement !== document.body) {
                    return;
                }

                event.preventDefault();
                move(1);
                break;

            case 'ArrowLeft':
            case 'ArrowUp':
            case 'PageUp':
                event.preventDefault();
                move(-1);
                break;

            case 'Home':
                event.preventDefault();
                show(0);
                break;

            case 'End':
                event.preventDefault();
                show(slides.length - 1);
                break;

            default:
                break;
        }
    });

    if (full) {
        full.addEventListener('click', function () {
            if (document.fullscreenElement) {
                if (document.exitFullscreen) {
                    document.exitFullscreen().catch(function () {});
                }

                return;
            }

            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(function () {});
            }
        });
    }

    /*
     * The controls fade when nothing has happened for a while, and come back on
     * any movement. A bar of buttons is not something the congregation should
     * be reading, and hiding it permanently would leave the person at the front
     * with nothing to press.
     */
    var quiet = null;

    function stir() {
        document.body.classList.remove('present-quiet');

        if (quiet) {
            clearTimeout(quiet);
        }

        quiet = setTimeout(function () {
            document.body.classList.add('present-quiet');
        }, 4000);
    }

    ['mousemove', 'keydown', 'touchstart', 'focusin'].forEach(function (name) {
        document.addEventListener(name, stir, { passive: true });
    });

    stir();

    /*
     * The screen stays awake, best effort — the same call the reader makes.
     * A projector showing the words of a hymn that dims halfway through the
     * second verse is worse than no present mode at all, because by then
     * everybody is looking at it.
     *
     * Re-requested on visibility change: the lock is released whenever the page
     * is hidden and is not restored on its own, so a service that switched to
     * the stream and back would have lost it silently.
     */
    var lock = null;

    function keepAwake() {
        if (!navigator.wakeLock || document.visibilityState !== 'visible') {
            return;
        }

        navigator.wakeLock.request('screen').then(function (held) {
            lock = held;
        }).catch(function () {});
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !lock) {
            keepAwake();
        }
    });

    keepAwake();

    show(at);
})();
