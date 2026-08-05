<?php

declare(strict_types=1);

namespace Portal\Plugins\Ratings;

/**
 * The rating widget, as it appears under a video.
 *
 * No JavaScript at all. Five submit buttons in an ordinary form is the whole
 * interaction, so it works with scripting blocked, it is keyboard-navigable
 * without anything being wired up, and screen readers get five labelled buttons
 * rather than a custom widget that has to announce itself. The cost is that
 * hovering does not preview a score, which is worth less than the feature
 * working everywhere.
 */
final class RatingView
{
    /**
     * @param array{count: int, sum: int, average: float} $totals
     * @param int|null $yours     what this person already gave, if anything
     * @param string   $action    where to post, or '' if this person may not rate
     */
    public static function widget(
        array $totals,
        ?int $yours,
        string $action,
        string $csrfField,
        int $minimumVotes,
        bool $allowChanges,
        string $notice = ''
    ): string {
        $css = self::css();
        $summary = self::summary($totals, $minimumVotes);
        $form = self::form($action, $csrfField, $yours, $allowChanges);

        $noticeHtml = $notice === ''
            ? ''
            : '<p class="rating-notice">' . e($notice) . '</p>';

        return <<<HTML
        <section class="ratings" aria-labelledby="ratings-heading" id="ratings">
          <style>{$css}</style>
          <h2 class="sr-only" id="ratings-heading">Rating</h2>
          {$summary}
          {$noticeHtml}
          {$form}
        </section>
        HTML;
    }

    /**
     * The average, or an honest admission that there is not one yet.
     *
     * @param array{count: int, sum: int, average: float} $totals
     */
    private static function summary(array $totals, int $minimumVotes): string
    {
        $count = $totals['count'];

        if ($count === 0) {
            return '<p class="rating-summary muted">Not rated yet.</p>';
        }

        $votes = $count === 1 ? '1 rating' : $count . ' ratings';

        // Below the threshold the count is shown and the average is withheld.
        // "5.0 out of 5" from one vote reads as a verdict; "1 rating" is the
        // same information without the borrowed authority.
        if (!RatingPolicy::showAverage($count, $minimumVotes)) {
            return sprintf(
                '<p class="rating-summary muted">%s so far — the average appears once a few more people have rated it.</p>',
                e($votes)
            );
        }

        $average = RatingPolicy::format($totals['average']);
        $percent = RatingPolicy::percent($totals['average']);

        return sprintf(
            '<p class="rating-summary">
               <span class="stars" role="img" aria-label="%s out of %d">
                 <span class="stars-empty">★★★★★</span>
                 <span class="stars-full" style="width:%s%%">★★★★★</span>
               </span>
               <strong>%s</strong> <span class="muted">from %s</span>
             </p>',
            e($average),
            RatingPolicy::MAX_SCORE,
            e((string) $percent),
            e($average),
            e($votes)
        );
    }

    private static function form(
        string $action,
        string $csrfField,
        ?int $yours,
        bool $allowChanges
    ): string {
        if ($action === '') {
            return '<p class="muted small"><a href="/auth/login">Sign in</a> to rate this.</p>';
        }

        // Rated already, and changes are off. Say so rather than showing
        // buttons that will be refused — a control that does nothing is worse
        // than no control.
        if ($yours !== null && !$allowChanges) {
            return sprintf(
                '<p class="muted small">You rated this %d out of %d.</p>',
                $yours,
                RatingPolicy::MAX_SCORE
            );
        }

        $buttons = '';
        for ($score = RatingPolicy::MIN_SCORE; $score <= RatingPolicy::MAX_SCORE; $score++) {
            $buttons .= sprintf(
                '<button type="submit" name="score" value="%1$d" class="rating-star%2$s"
                         aria-label="Rate %1$d out of %3$d"%4$s>%5$s</button>',
                $score,
                $yours !== null && $score <= $yours ? ' chosen' : '',
                RatingPolicy::MAX_SCORE,
                $yours === $score ? ' aria-pressed="true"' : '',
                $yours !== null && $score <= $yours ? '★' : '☆'
            );
        }

        $remove = $yours === null
            ? ''
            : '<button type="submit" name="action" value="remove" class="rating-remove">Remove my rating</button>';

        $legend = $yours === null ? 'Rate this' : 'Your rating';

        return <<<HTML
        <form method="post" action="{$action}" class="rating-form">
          {$csrfField}
          <fieldset>
            <legend>{$legend}</legend>
            <span class="rating-stars">{$buttons}</span>
            {$remove}
          </fieldset>
        </form>
        HTML;
    }

    private static function css(): string
    {
        return <<<'CSS'
        .ratings { margin-top: 2.5rem; max-width: 48rem; }
        .rating-summary { display: flex; align-items: center; gap: .625rem; margin: 0 0 .75rem; }
        .stars { position: relative; display: inline-block; white-space: nowrap;
            line-height: 1; font-size: 1.125rem; letter-spacing: .1em; }
        .stars-empty { color: rgb(148 163 184 / .45); }
        .stars-full { position: absolute; top: 0; left: 0; overflow: hidden;
            white-space: nowrap; color: #fbbf24; }
        .rating-form fieldset { border: 0; padding: 0; margin: 0; display: flex;
            align-items: center; gap: 1rem; flex-wrap: wrap; }
        .rating-form legend { float: left; width: 100%; font-size: .8125rem;
            margin-bottom: .375rem; opacity: .7; }
        .rating-stars { display: inline-flex; gap: .125rem; }
        .rating-star { background: none; border: 0; padding: 0 .0625rem; cursor: pointer;
            font-size: 1.5rem; line-height: 1; color: rgb(148 163 184 / .55); }
        .rating-star.chosen { color: #fbbf24; }
        .rating-star:hover, .rating-star:focus-visible { color: #fbbf24; }
        .rating-remove { background: none; border: 0; padding: 0; font: inherit;
            font-size: .8125rem; color: #38bdf8; cursor: pointer; }
        .rating-notice { padding: .5rem .875rem; border-radius: 8px; font-size: .9375rem;
            border: 1px solid rgb(56 189 248 / .4); background: rgb(56 189 248 / .08); }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        CSS;
    }
}
