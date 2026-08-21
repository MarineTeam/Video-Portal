<?php

declare(strict_types=1);

namespace Portal\Plugins\Popular;

/**
 * What "most watched" means, and when it is worth saying.
 *
 * The counting is core's — {video_views} has been filled since the analytics
 * work. Everything here is the judgement laid over it, kept away from the
 * database so it can be argued with in tests.
 */
final class PopularPolicy
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 365;

    public const DEFAULT_COUNT = 8;
    public const MAX_COUNT = 24;

    public const DEFAULT_TITLE = 'Most watched';
    public const TITLE_MAX = 60;

    public const FIRST = 'first';
    public const LAST = 'last';

    /**
     * Below this, the row is not shown at all.
     *
     * A "most watched" row listing one video is not a ranking; it is the only
     * thing anybody opened, presented as if a crowd had chosen it. On a site
     * that has just been installed that is every row it would ever draw, and it
     * would read as the plugin being broken rather than as there being nothing
     * to say yet.
     */
    public const MIN_VIDEOS = 3;

    /**
     * How many ranked videos to ask for before visibility is applied.
     *
     * More than are wanted, because the view table knows nothing about who is
     * asking: the top of it can be full of members-only videos that a
     * signed-out visitor may not be shown, and asking for exactly eight would
     * hand back two. Four times over, which covers a library where most of the
     * popular material is restricted.
     *
     * Capped at 100 because that is what VideoRepository::query() clamps a page
     * to. Asking for more would silently return one page's worth and quietly
     * drop the tail — the kind of cap that is invisible until the day the data
     * grows past it.
     */
    public const CANDIDATE_FACTOR = 4;
    public const CANDIDATE_MAX = 100;

    /** Clamped rather than refused: this comes from a number field. */
    public static function days(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT_DAYS;
        }

        return max(1, min(self::MAX_DAYS, (int) $raw));
    }

    public static function count(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT_COUNT;
        }

        return max(self::MIN_VIDEOS, min(self::MAX_COUNT, (int) $raw));
    }

    public static function title(mixed $raw): string
    {
        $title = trim((string) (is_scalar($raw) ? $raw : ''));

        return $title === '' ? self::DEFAULT_TITLE : mb_substr($title, 0, self::TITLE_MAX);
    }

    /** Anything that is not the one other value is the default. */
    public static function position(mixed $raw): string
    {
        return $raw === self::LAST ? self::LAST : self::FIRST;
    }

    public static function candidateLimit(int $count): int
    {
        return min(self::CANDIDATE_MAX, max(1, $count) * self::CANDIDATE_FACTOR);
    }

    /**
     * Keep the ranking, drop what may not be shown, and stop at $count.
     *
     * The two halves of this answer different questions and both are needed.
     * `$ranked` is the order — which videos were watched most, from the view
     * table, which has never heard of publication dates or members-only.
     * `$available` is the permission — which of them the ordinary listing query
     * agreed to return for whoever is asking.
     *
     * Taking the ranking as the answer would put a members-only video in a
     * stranger's homepage row; taking the listing's own order would produce a
     * row labelled "most watched" sorted by publication date, which is a
     * different claim rendered under the same heading.
     *
     * A duplicate in $ranked is kept once. The view table groups by video, so
     * that should not happen — but a row appearing twice in a "top eight" is
     * the kind of thing nobody notices in code and everybody notices on a page.
     *
     * @param list<int> $ranked    video ids, most watched first
     * @param list<int> $available video ids the listing query returned
     * @return list<int>
     */
    public static function keepInRankOrder(array $ranked, array $available, int $count): array
    {
        $allowed = array_flip(array_map('intval', $available));

        $out = [];
        $seen = [];

        foreach ($ranked as $id) {
            $id = (int) $id;

            if (!isset($allowed[$id]) || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $out[] = $id;

            if (count($out) >= max(1, $count)) {
                break;
            }
        }

        return $out;
    }

    /** Is there enough here to call it a ranking? */
    public static function worthShowing(int $resolved): bool
    {
        return $resolved >= self::MIN_VIDEOS;
    }
}
