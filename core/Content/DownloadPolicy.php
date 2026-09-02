<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Whether a video may be downloaded, and by whom.
 *
 * Downloads are different from everything else this application gates. A share
 * link expires, a members-only thumbnail is re-checked on the next page load,
 * and an unpublished video stops playing — but a file on somebody's phone is
 * there for good. Nothing decided here can be withdrawn once the bytes have
 * left, so the settings that lead to it are worth being explicit about.
 *
 * Two questions, kept apart because they have different answers and different
 * screens:
 *
 *   WHAT may be downloaded — this class. A tri-state on the video, its series,
 *   and its categories, resolved most-specific-first.
 *
 *   WHO may download it — `Capability::DOWNLOAD_CONTENT`, scopable, resolved by
 *   the existing permission system. There is no bespoke audience table here on
 *   purpose: roles, groups, scoped grants and the Permissions screen already
 *   answer "which people, over which content", and a second mechanism for the
 *   same question is how the two end up disagreeing.
 *
 * Pure: no database, no request. The inheritance rule is the part worth getting
 * exactly right, and it should be testable without either.
 */
final class DownloadPolicy
{
    /** Defer upward: video → series → category chain → site setting. */
    public const INHERIT = 'default';

    /** Downloadable, whatever the level above says. */
    public const ALLOW = 'allow';

    /** Not downloadable, whatever the level above says. */
    public const BLOCK = 'block';

    /**
     * Resolve one video's mode.
     *
     * The order is video, then series, then the category chain nearest first,
     * then the site default. First opinion wins.
     *
     * SERIES BEFORE CATEGORIES, which is the one debatable step. A video sits
     * in exactly one series and in any number of categories, so the series is
     * an unambiguous answer while the categories may disagree and need a
     * tie-break below. Reaching for the unambiguous one first means the
     * tie-break decides less often. It also matches how this codebase already
     * describes the two: a series is an ORDER somebody curated, a category is a
     * PLACE, and the curated sequence is the narrower statement.
     *
     * A video in no series and no category resolves straight to the site
     * default, so empty inputs are legitimate rather than an error.
     *
     * @param list<string> $categoryModes nearest first
     */
    public static function resolve(
        string $videoMode,
        string $seriesMode = self::INHERIT,
        array $categoryModes = [],
        bool $siteDefault = false
    ): string {
        if (self::isDecisive($videoMode)) {
            return $videoMode;
        }

        if (self::isDecisive($seriesMode)) {
            return $seriesMode;
        }

        foreach ($categoryModes as $mode) {
            if (self::isDecisive($mode)) {
                return $mode;
            }
        }

        return $siteDefault ? self::ALLOW : self::BLOCK;
    }

    /**
     * Where two categories disagree, BLOCK wins.
     *
     * Same rule as members-only thumbnails, for the same reason: without it the
     * ordering of rows in a join table decides whether a file leaves the site.
     * Here the stake is higher, because the thumbnail is recoverable by
     * changing the setting and the download is not.
     *
     * @param list<string> $opinions
     * @return list<string> at most one entry — the chain's single verdict
     */
    public static function reconcile(array $opinions): array
    {
        if (in_array(self::BLOCK, $opinions, true)) {
            return [self::BLOCK];
        }

        return in_array(self::ALLOW, $opinions, true) ? [self::ALLOW] : [];
    }

    /** Did the resolution end in a yes? */
    public static function allows(string $resolvedMode): bool
    {
        return $resolvedMode === self::ALLOW;
    }

    /** Is $mode a real answer, or a request to defer? */
    private static function isDecisive(string $mode): bool
    {
        return $mode === self::ALLOW || $mode === self::BLOCK;
    }

    /**
     * Coerce anything a form submits into a valid mode.
     *
     * Unknown values become INHERIT, not BLOCK and not ALLOW. INHERIT is the
     * only one of the three that changes nothing — a stray value must not
     * silently grant a download, and it must not silently withdraw one either,
     * because both look like the setting working and neither is what anybody
     * asked for.
     */
    public static function sanitize(mixed $value): string
    {
        $mode = is_scalar($value) ? (string) $value : '';

        return in_array($mode, [self::INHERIT, self::ALLOW, self::BLOCK], true)
            ? $mode
            : self::INHERIT;
    }

    /**
     * The choices an admin picks from.
     *
     * Kept here so the video form, the series form, the category form and
     * anything added later cannot describe the same setting four different
     * ways.
     *
     * @return array<string, string>
     */
    public static function choices(string $inheritLabel): array
    {
        return [
            self::INHERIT => $inheritLabel,
            self::ALLOW   => 'Allow downloads',
            self::BLOCK   => 'Block downloads',
        ];
    }
}
