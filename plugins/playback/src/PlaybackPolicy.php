<?php

declare(strict_types=1);

namespace Portal\Plugins\Playback;

/**
 * Two decisions about what happens around a playing video.
 *
 * Pure — a list of chapters and some strings in, an answer out. No database, no
 * request, no player. The parts that talk to the iframe live in the script;
 * everything worth arguing about lives here where it can be tested directly.
 */
final class PlaybackPolicy
{
    /**
     * Chapter titles that mean "the bit people came for", in order of
     * preference.
     *
     * A church posting a whole service is the case this exists for: forty
     * minutes of welcome, notices, and songs before the thing most people
     * opened the page to hear. The alternative designs are worse — "skip to
     * chapter two" assumes the running order never changes, and a per-video
     * field is another thing to fill in on every upload and therefore another
     * thing nobody fills in.
     *
     * Matching on the title means the person who typed the chapters has already
     * said where it starts, without knowing they were configuring anything.
     */
    public const DEFAULT_TITLES = 'Sermon, Message, Teaching, Talk';

    /**
     * How long the next episode waits before playing itself.
     *
     * Ten seconds is long enough to read the title and press cancel, short
     * enough that somebody who wanted it does not feel stalled. Zero means the
     * card appears and nothing happens on its own, which is the setting for a
     * site that finds autoplay rude.
     */
    public const DEFAULT_COUNTDOWN = 10;

    /**
     * Where "skip the intro" should jump to, or null if there is nowhere.
     *
     * @param list<array{start: int, title: string}> $chapters
     * @return array{start: int, title: string}|null
     */
    public static function skipTarget(array $chapters, string $titles): ?array
    {
        if (count($chapters) < 2) {
            /*
             * One chapter is not an intro and a sermon, it is a label on the
             * whole video — so there is nothing to skip and a button offering
             * to would jump somebody to the start of what they are already
             * watching.
             */
            return null;
        }

        $wanted = self::titles($titles);
        if ($wanted === []) {
            return null;
        }

        foreach ($wanted as $needle) {
            foreach ($chapters as $chapter) {
                $title = trim((string) ($chapter['title'] ?? ''));
                $start = (int) ($chapter['start'] ?? 0);

                /*
                 * A chapter at zero is never a skip target, whatever it is
                 * called. Some people title the whole recording "Sermon", and
                 * a button that seeks to 0:00 is a button that appears to do
                 * nothing — which reads as broken rather than as inapplicable.
                 */
                if ($start <= 0) {
                    continue;
                }

                // Case-insensitive CONTAINS, not equals: "Sermon: Romans 8" and
                // "The Sermon" are both what somebody meant, and demanding an
                // exact match makes the feature depend on typing discipline
                // nobody was told about.
                if (mb_stripos($title, $needle) !== false) {
                    return ['start' => $start, 'title' => $title];
                }
            }
        }

        return null;
    }

    /**
     * The label on the button.
     *
     * Names the destination rather than saying "Skip intro", because on a
     * service recording the thing being skipped is the welcome and the songs —
     * calling that "the intro" is both inaccurate and slightly rude about the
     * part of the service somebody led.
     */
    public static function skipLabel(string $chapterTitle): string
    {
        $title = trim($chapterTitle);

        return $title === '' ? 'Skip ahead' : 'Skip to ' . $title;
    }

    /**
     * The configured titles, cleaned.
     *
     * @return list<string>
     */
    public static function titles(string $csv): array
    {
        $out = [];

        foreach (explode(',', $csv) as $piece) {
            $piece = trim($piece);
            if ($piece !== '') {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * A countdown a person can live with.
     *
     * Clamped rather than refused: this comes from a number field, and a
     * setting somebody typed 900 into should become "a long time" rather than
     * an error page. Zero survives, because zero means something here.
     */
    public static function countdown(mixed $value): int
    {
        $seconds = (int) $value;

        if ($seconds <= 0) {
            return 0;
        }

        return min(60, max(3, $seconds));
    }
}
