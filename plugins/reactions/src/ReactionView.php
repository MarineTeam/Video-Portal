<?php

declare(strict_types=1);

namespace Portal\Plugins\Reactions;

/**
 * The reaction row, as it appears under a video.
 *
 * No JavaScript, the same as ratings and for the same reasons: submit buttons
 * in an ordinary form work with scripting blocked, are keyboard-navigable with
 * nothing wired up, and give a screen reader labelled buttons rather than a
 * custom widget that has to announce itself.
 *
 * The cost here is a page reload per reaction, which is more noticeable than it
 * was for ratings because somebody may leave several. It is still the right
 * trade: the alternative is a feature that silently does nothing for anybody
 * whose scripts failed to load, on a page whose real job is playing a video.
 */
final class ReactionView
{
    /**
     * @param array<string, int> $counts every kind, in order, zeroes included
     * @param list<string>       $yours  kinds this person has already left
     * @param string             $action where to post, or '' if they may not react
     */
    public static function widget(array $counts, array $yours, string $action, string $csrfField): string
    {
        $css = self::css();
        $buttons = '';

        foreach ($counts as $kind => $count) {
            $isMine = in_array($kind, $yours, true);
            $label = ReactionPolicy::label($kind);
            $emoji = ReactionPolicy::emoji($kind);

            /*
             * The count is rendered but the EMOJI is hidden from assistive
             * technology, because the button already has a real label. Read
             * aloud, "🙏 Amen 3" would announce the picture and the word for
             * one idea.
             */
            $inner = sprintf(
                '<span aria-hidden="true">%s</span> %s%s',
                e($emoji),
                e($label),
                $count > 0 ? ' <span class="reaction-count">' . $count . '</span>' : ''
            );

            if ($action === '') {
                /*
                 * A reader who may not react sees the counts as text, not as
                 * disabled buttons. A disabled control invites somebody to work
                 * out why it is disabled; a number does not pretend to be
                 * pressable in the first place.
                 */
                if ($count > 0) {
                    $buttons .= '<span class="reaction reaction-static">' . $inner . '</span>';
                }
                continue;
            }

            $buttons .= sprintf(
                '<button type="submit" name="kind" value="%s" class="reaction%s"'
                . ' aria-pressed="%s" title="%s">%s</button>',
                e($kind),
                $isMine ? ' is-mine' : '',
                $isMine ? 'true' : 'false',
                // Says what pressing will DO, which for a toggle is the half
                // that is not obvious from the label.
                e($isMine ? 'Remove your “' . $label . '”' : 'React “' . $label . '”'),
                $inner
            );
        }

        if (trim($buttons) === '') {
            return '';
        }

        if ($action === '') {
            return <<<HTML
            <section class="reactions" aria-labelledby="reactions-heading" id="reactions">
              <style>{$css}</style>
              <h2 class="sr-only" id="reactions-heading">Reactions</h2>
              <div class="reaction-row">{$buttons}</div>
            </section>
            HTML;
        }

        return <<<HTML
        <section class="reactions" aria-labelledby="reactions-heading" id="reactions">
          <style>{$css}</style>
          <h2 class="sr-only" id="reactions-heading">Reactions</h2>
          <form method="post" action="{$action}" class="reaction-row">
            {$csrfField}
            {$buttons}
          </form>
        </section>
        HTML;
    }

    /**
     * Scoped to this widget, and shipped with it.
     *
     * A plugin that needs the active theme to have styled it is a plugin that
     * looks broken on every theme but one.
     */
    private static function css(): string
    {
        return <<<CSS
        .reactions { margin: 1.5rem 0; }
        .reaction-row { display: flex; flex-wrap: wrap; gap: .5rem; }
        .reaction {
          display: inline-flex; align-items: center; gap: .375rem;
          padding: .375rem .75rem; border-radius: 999px; cursor: pointer;
          border: 1px solid rgba(148,163,184,.35);
          background: transparent; color: inherit;
          font: inherit; font-size: .875rem; line-height: 1.4;
        }
        .reaction:hover { border-color: rgba(148,163,184,.7); }
        /*
         * The pressed state is a border, a background AND a weight change --
         * not colour alone, so it survives a high-contrast rendering and is
         * legible to somebody who cannot distinguish the tint. aria-pressed
         * carries it for a screen reader.
         */
        .reaction.is-mine {
          border-color: currentColor; background: rgba(148,163,184,.18); font-weight: 650;
        }
        .reaction-static { cursor: default; }
        .reaction-count { opacity: .75; font-variant-numeric: tabular-nums; }
        CSS;
    }
}
