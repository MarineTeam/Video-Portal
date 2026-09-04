<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Whether a particular person may see a particular thing.
 *
 * Viewing had two settings before this: published, and members-only. "Everyone
 * who has been approved" was the finest grain available, so a course for one
 * group had nowhere to live except a share link per person.
 *
 * THE RULE, WHICH IS THE SAME RULE AS EVERY OTHER INHERITANCE HERE
 *
 *   groups named on the video   -> they decide
 *   else groups named on its series -> they decide
 *   else                        -> unrestricted
 *
 * The nearest scope with an opinion wins, exactly as watermark, thumbnail and
 * download modes already work.
 *
 * THE PART WORTH BEING CAREFUL ABOUT: an empty list means UNRESTRICTED, not
 * "nobody". Those are one missing row apart and they fail in opposite
 * directions — the first shows a video to everyone, the second hides the whole
 * library the moment a table is empty. Getting it the safe way round would mean
 * a fresh install showing nothing at all, so the default has to be permissive
 * and the restriction has to be something somebody added on purpose.
 *
 * This is expressed twice, deliberately and unavoidably: here in PHP for one
 * video, and in SQL in VideoRepository for a listing. That is the arrangement
 * scheduling already uses, and the tests check the two agree — because the
 * failure when they disagree is a video that is listed and 404s, or worse, one
 * that is hidden from a listing and plays.
 */
final class AudiencePolicy
{
    /**
     * May somebody in these groups see something with this restriction?
     *
     * @param list<int> $videoGroups   groups named on the video, empty if none
     * @param list<int> $seriesGroups  groups named on its series, empty if none
     * @param list<int> $viewerGroups  groups this person belongs to
     */
    public static function allows(array $videoGroups, array $seriesGroups, array $viewerGroups): bool
    {
        $deciding = $videoGroups !== [] ? $videoGroups : $seriesGroups;

        // Nothing named anywhere: this was never restricted.
        if ($deciding === []) {
            return true;
        }

        return array_intersect($deciding, $viewerGroups) !== [];
    }

    /**
     * Which scope actually decided, for a screen that has to explain itself.
     *
     * An administrator looking at a video restricted by its series needs to be
     * told that, or they go looking for a setting on the video that is not
     * there.
     *
     * @param list<int> $videoGroups
     * @param list<int> $seriesGroups
     */
    public static function decidedBy(array $videoGroups, array $seriesGroups): ?string
    {
        if ($videoGroups !== []) {
            return 'video';
        }

        return $seriesGroups !== [] ? 'series' : null;
    }
}
