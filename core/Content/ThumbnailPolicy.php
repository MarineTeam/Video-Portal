<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * Whether a viewer gets a video's real artwork, or a members-only placeholder.
 *
 * This exists because listing and playing are separate permissions here.
 * `/watch` is guarded and the library is not, so the normal arrangement is a
 * catalogue anyone can browse and only an approved account can play. That is
 * usually what a site wants — but not always, and there was previously no way
 * to say "you may know this video exists without seeing a frame of it".
 *
 * Pure: no database, no request. The inheritance rule is the part worth getting
 * exactly right, and it should be testable without either.
 */
final class ThumbnailPolicy
{
    /** Defer upward: video → category chain → site setting. */
    public const INHERIT = 'default';

    /** Always show the real thumbnail. */
    public const PUBLIC_ART = 'public';

    /** Show a placeholder to anyone who cannot watch. */
    public const MEMBERS = 'members';

    /**
     * Resolve one video's mode.
     *
     * $categoryModes is the video's category chain ordered NEAREST FIRST — the
     * category the video sits in, then its parent, up to the root. The first
     * entry with an opinion wins, which is the same nearest-ancestor rule the
     * plugin overrides already use.
     *
     * A video in no category resolves straight to the site default, so an empty
     * chain is legitimate rather than an error.
     *
     * @param list<string> $categoryModes nearest first
     */
    public static function resolve(
        string $videoMode,
        array $categoryModes,
        bool $siteDefault = false
    ): string {
        if (self::isDecisive($videoMode)) {
            return $videoMode;
        }

        foreach ($categoryModes as $mode) {
            if (self::isDecisive($mode)) {
                return $mode;
            }
        }

        return $siteDefault ? self::MEMBERS : self::PUBLIC_ART;
    }

    /**
     * Should this viewer be given the real artwork?
     *
     * The question is "can they watch it", not "are they signed in". An account
     * an administrator has not approved yet cannot play anything, so showing it
     * the artwork would leak exactly what the setting withholds — and that is
     * the state every new account starts in, making it the common case rather
     * than an edge one.
     */
    public static function showsRealArt(string $resolvedMode, bool $viewerCanWatch): bool
    {
        return $resolvedMode !== self::MEMBERS || $viewerCanWatch;
    }

    /** Is $mode a real answer, or a request to defer? */
    private static function isDecisive(string $mode): bool
    {
        return $mode === self::PUBLIC_ART || $mode === self::MEMBERS;
    }

    /**
     * Coerce anything a form submits into a valid mode.
     *
     * Unknown values become INHERIT, not MEMBERS. Both are defensible, but a
     * stray value silently locking artwork would go unnoticed until somebody
     * spotted their whole library had turned grey, whereas an unexpected
     * INHERIT behaves as though the setting was never touched.
     */
    public static function sanitize(mixed $value): string
    {
        $mode = is_scalar($value) ? (string) $value : '';

        return in_array($mode, [self::INHERIT, self::PUBLIC_ART, self::MEMBERS], true)
            ? $mode
            : self::INHERIT;
    }

    /**
     * The choices an admin picks from.
     *
     * Kept here so the video form, the category form, and anything added later
     * cannot describe the same setting three different ways.
     *
     * @return array<string, string>
     */
    public static function choices(string $inheritLabel): array
    {
        return [
            self::INHERIT    => $inheritLabel,
            self::PUBLIC_ART => 'Always show the real thumbnail',
            self::MEMBERS    => 'Members only — placeholder for anyone who cannot watch',
        ];
    }
}
