/**
 * The ten-foot page, under a remote control.
 *
 * The page works without this: the tiles are ordinary links in DOM order, so
 * the browser's own Tab and arrow handling already moves focus along the strip
 * and OK activates one. What this adds is the two things a browser does NOT do
 * on a television:
 *
 *   IT SCROLLS THE FOCUSED TILE INTO VIEW. Native focus scrolling jumps the
 *   container so the tile is just barely visible at the edge, which on a strip
 *   means the next press moves focus to something off-screen and the whole
 *   thing feels broken. Centring it keeps the next tile visible before you
 *   press for it.
 *
 *   IT FOCUSES SOMETHING TO BEGIN WITH. A television arrives at a page with
 *   focus on the document, so the first arrow press does nothing — which reads
 *   as the remote not working, and the second press is somebody pressing
 *   harder.
 *
 * Deliberately NOT here: a grid model, or arrow handling of its own. The strip
 * is one row in DOM order, so the browser's own behaviour is already correct,
 * and a hand-rolled focus manager would be a second one to disagree with it.
 */
(function () {
    'use strict';

    var strip = document.querySelector('[data-tv-strip]');

    if (!strip) {
        return;
    }

    var tiles = Array.prototype.slice.call(strip.querySelectorAll('.tv-tile'));

    if (tiles.length === 0) {
        return;
    }

    /*
     * Centre the focused tile, on focus rather than on keypress.
     *
     * On focus, because that catches every way focus can arrive — arrows, Tab,
     * a pointer on a tablet, and whatever a particular television's browser
     * does — where binding to keydown would only catch the one we predicted.
     */
    strip.addEventListener('focusin', function (event) {
        var tile = event.target.closest ? event.target.closest('.tv-tile') : null;

        if (!tile) {
            return;
        }

        if (tile.scrollIntoView) {
            try {
                tile.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
            } catch (e) {
                /* Older televisions take no options object and scroll to the
                   top of the document if given one. Fall back to the boolean
                   form, which at least scrolls the right axis. */
                tile.scrollIntoView(false);
            }
        }
    });

    /*
     * Focus the first tile, so the first arrow press moves rather than doing
     * nothing.
     *
     * preventScroll, because focusing without it scrolls the page — and the
     * body has overflow hidden, so on some browsers that shifts the layout by
     * a few pixels and leaves it there.
     */
    try {
        tiles[0].focus({ preventScroll: true });
    } catch (e) {
        tiles[0].focus();
    }

    /*
     * The television's screen saver is its own business and this does not fight
     * it — unlike present mode and the reader, which hold a wake lock.
     *
     * A television showing a library index is not mid-hymn: if somebody walks
     * away, the set going to sleep is correct. Holding a lock on the one device
     * in the house that has its own idea about sleeping would be this page
     * deciding something that is not its decision.
     */
})();
