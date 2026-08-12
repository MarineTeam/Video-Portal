<?php

declare(strict_types=1);

namespace Portal\Plugins\Playback;

/**
 * The skip button and the up-next card.
 *
 * Both are rendered as ordinary HTML with real links, and the script upgrades
 * them. With scripting blocked the skip button is a link to `?t=` — which the
 * theme's player already honours as an explicit request — and the up-next card
 * is a link to the next episode. Nothing here is only reachable through
 * JavaScript, which is the difference between a progressive enhancement and a
 * feature that silently does not exist for some people.
 */
final class PlaybackView
{
    /**
     * @param array{start: int, title: string}|null $skip
     * @param array{title: string, url: string}|null $next
     */
    public static function widget(?array $skip, ?array $next, int $countdown, string $scriptUrl): string
    {
        $skipHtml = '';
        if ($skip !== null) {
            $skipHtml = sprintf(
                '<a class="pb-skip" href="?t=%d" data-pb-seek="%d">%s <span aria-hidden="true">&rarr;</span></a>',
                $skip['start'],
                $skip['start'],
                e(PlaybackPolicy::skipLabel($skip['title']))
            );
        }

        $nextHtml = '';
        if ($next !== null) {
            /*
             * `hidden` and revealed by the script, because this only makes
             * sense once a video has ended — and without scripting there is no
             * "ended", so showing it always would put a permanent "Up next"
             * card under a video somebody just started.
             *
             * The link inside is real either way, so a keyboard user who finds
             * it and a script that reveals it reach the same place.
             */
            $nextHtml = sprintf(
                '<div class="pb-next" id="pb-next" hidden data-pb-countdown="%d">
                   <p class="pb-next-label">Up next</p>
                   <p class="pb-next-title"><a href="%s">%s</a></p>
                   <p class="pb-next-actions">
                     <a class="pb-next-go" href="%s">Play now</a>
                     <button type="button" class="pb-next-stop">Stay here</button>
                   </p>
                 </div>',
                $countdown,
                e($next['url']),
                e($next['title']),
                e($next['url'])
            );
        }

        $css = self::css();

        return <<<HTML
        <section class="pb" aria-live="polite">
          <style>{$css}</style>
          {$skipHtml}
          {$nextHtml}
          <script src="{$scriptUrl}" defer></script>
        </section>
        HTML;
    }

    /**
     * Shipped with the plugin, not borrowed from the theme.
     *
     * A plugin that needs the active theme to have styled it is a plugin that
     * looks broken on every theme but one.
     */
    private static function css(): string
    {
        return <<<CSS
        .pb { margin: 1rem 0; }
        .pb-skip {
          display: inline-flex; align-items: center; gap: .5rem;
          padding: .5rem 1rem; border-radius: 999px; text-decoration: none;
          border: 1px solid rgba(148,163,184,.35); color: inherit;
          font-size: .875rem;
        }
        .pb-skip:hover { border-color: rgba(148,163,184,.7); }
        .pb-next {
          margin-top: 1rem; padding: 1rem 1.25rem; border-radius: 12px;
          border: 1px solid rgba(148,163,184,.35);
        }
        .pb-next-label {
          margin: 0 0 .25rem; font-size: .75rem; letter-spacing: .08em;
          text-transform: uppercase; opacity: .7;
        }
        .pb-next-title { margin: 0 0 .75rem; font-size: 1.0625rem; font-weight: 650; }
        .pb-next-title a { color: inherit; text-decoration: none; }
        .pb-next-title a:hover { text-decoration: underline; }
        .pb-next-actions { display: flex; gap: .75rem; align-items: center; margin: 0; }
        .pb-next-go, .pb-next-stop {
          padding: .375rem .875rem; border-radius: 999px; font: inherit; font-size: .8125rem;
          border: 1px solid rgba(148,163,184,.35); background: transparent; color: inherit;
          cursor: pointer; text-decoration: none;
        }
        .pb-next-go { border-color: currentColor; font-weight: 650; }
        CSS;
    }
}
