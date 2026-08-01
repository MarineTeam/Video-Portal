<?php

declare(strict_types=1);

namespace Portal\Plugins\Watermark;

/**
 * The overlay itself.
 *
 * What this is honestly for: a tiled, semi-transparent label naming the person
 * watching, so a screen recording that turns up somewhere it should not can be
 * traced back to whoever made it. It deters casual re-sharing.
 *
 * What it is NOT: DRM. Anyone who opens developer tools can delete this element
 * in two seconds, and the video keeps playing. Nothing rendered in a browser can
 * prevent a determined person from capturing a video they are allowed to watch,
 * and a plugin that implied otherwise would be selling a promise it cannot keep.
 * The value is attribution, not prevention.
 *
 * Tiled rather than a single corner mark because a corner is trivially cropped
 * out, and diagonal because horizontal text disappears into subtitles and lower
 * thirds.
 */
final class WatermarkOverlay
{
    /** Enough tiles to cover a 16:9 frame at any reasonable viewport. */
    private const TILES = 24;

    public static function render(string $label, float $opacity): string
    {
        $text = e($label);
        $alpha = number_format($opacity, 3, '.', '');

        $tiles = str_repeat('<span>' . $text . '</span>', self::TILES);
        $css = self::css();

        // aria-hidden and pointer-events:none together: the overlay must not be
        // read out to someone using a screen reader (it is not content), and it
        // must never intercept a click meant for the player underneath, or the
        // watermark would break playback for everyone.
        return <<<HTML
        <style>{$css}</style>
        <div class="pw-mark" style="--pw-alpha:{$alpha}" aria-hidden="true">{$tiles}</div>
        HTML;
    }

    private static function css(): string
    {
        return <<<'CSS'
        /* A theme whose .player forgot position:relative would otherwise let
           the overlay escape and cover the page. Cheap insurance. */
        .player { position: relative; }
        .pw-mark {
          position: absolute;
          inset: 0;
          overflow: hidden;
          pointer-events: none;
          user-select: none;
          display: flex;
          flex-wrap: wrap;
          align-content: center;
          justify-content: center;
          gap: 4vw 3vw;
          transform: rotate(-24deg) scale(1.6);
          z-index: 2;
        }
        .pw-mark span {
          color: #fff;
          opacity: var(--pw-alpha, .12);
          font: 600 clamp(9px, 1.3vw, 15px)/1 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
          letter-spacing: .06em;
          white-space: nowrap;
          /* Legible over both a blown-out sky and a black frame. */
          text-shadow: 0 1px 2px rgba(0, 0, 0, .55);
        }
        @media print {
          /* Printing a page is a capture too. */
          .pw-mark span { opacity: .5; color: #000; text-shadow: none; }
        }
        CSS;
    }
}
