<?php

declare(strict_types=1);

namespace Portal\Plugins\Watermark;

/**
 * Whether to draw a watermark, and what it should say.
 *
 * Pure: no database, no request, no config. Everything it needs is passed in.
 * That is deliberate — this is the part that decides whether a leaked recording
 * can be traced back to a person, so it has to be testable exhaustively rather
 * than approximately.
 */
final class WatermarkPolicy
{
    public const MODE_DEFAULT = 'default';
    public const MODE_ON      = 'on';
    public const MODE_OFF     = 'off';

    /**
     * Resolve the four levels in order: exemption, share, video, global.
     *
     * The order is the whole design, so it is worth saying why an exemption
     * beats an explicit "on":
     *
     *   An exemption names a PERSON; the other three describe CONTENT. Someone
     *   on the exempt list is typically the admin reviewing their own share, or
     *   an internal editor. If an explicit share-level "on" outranked them,
     *   exempting yourself would mean editing every share you ever make, which
     *   nobody would do, so the setting would go unused.
     *
     * The cost is real and should be named: an exempt address can watch any
     * share unwatermarked, so the exempt list is a list of people trusted not
     * to leak. It belongs in the settings screen with that warning attached.
     *
     * @param bool         $enabledHere  is the plugin on for this video's category
     * @param list<string> $exemptEmails already normalized, lowercased
     */
    public static function shouldWatermark(
        bool $enabledHere,
        array $exemptEmails,
        string $viewerEmail,
        string $shareMode = self::MODE_DEFAULT,
        string $videoMode = self::MODE_DEFAULT,
        bool $globalDefault = false
    ): bool {
        // A deactivated plugin — or one an admin switched off for this section
        // of the site — draws nothing, whatever the modes say.
        if (!$enabledHere) {
            return false;
        }

        if (self::isExempt($viewerEmail, $exemptEmails)) {
            return false;
        }

        if ($shareMode === self::MODE_ON || $shareMode === self::MODE_OFF) {
            return $shareMode === self::MODE_ON;
        }

        if ($videoMode === self::MODE_ON || $videoMode === self::MODE_OFF) {
            return $videoMode === self::MODE_ON;
        }

        return $globalDefault;
    }

    /**
     * Is this viewer exempt?
     *
     * An empty viewer address is never exempt. Every path that reaches the
     * overlay has already established who is watching, so a blank address here
     * means something upstream is wrong — and the safe reading of "I do not
     * know who this is" is to mark the recording, not to leave it clean.
     *
     * @param list<string> $exemptEmails
     */
    public static function isExempt(string $viewerEmail, array $exemptEmails): bool
    {
        $viewer = self::normalize($viewerEmail);

        if ($viewer === '') {
            return false;
        }

        foreach ($exemptEmails as $exempt) {
            $exempt = self::normalize($exempt);

            if ($exempt === '') {
                continue;
            }

            // A whole-domain exemption, written @example.com. Organisations
            // ask for this constantly and would otherwise paste in fifty
            // addresses and forget to maintain them.
            if ($exempt[0] === '@') {
                if (str_ends_with($viewer, $exempt)) {
                    return true;
                }
                continue;
            }

            if ($viewer === $exempt) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the settings field into a list.
     *
     * Accepts commas, semicolons, and newlines, because an admin pasting a
     * column out of a spreadsheet gets newlines and one pasting from an email
     * client gets commas, and neither should have to know which we wanted.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $clean = [];
        foreach ($parts as $part) {
            $normalized = self::normalize($part);
            if ($normalized !== '') {
                $clean[$normalized] = true;
            }
        }

        return array_keys($clean);
    }

    /**
     * What the tile should say.
     *
     * Tokens rather than a checkbox per field: an admin who wants
     * "email + date" and one who wants "email only" are asking for the same
     * feature with different content, and a template expresses that in one
     * setting instead of three booleans that interact.
     *
     * @param array<string, string> $tokens
     */
    public static function label(string $template, array $tokens): string
    {
        $template = trim($template);

        if ($template === '') {
            $template = '{email}';
        }

        foreach ($tokens as $name => $value) {
            $template = str_replace('{' . $name . '}', $value, $template);
        }

        // Any token we do not recognise is dropped rather than left as literal
        // braces, which would look like a rendering bug to whoever sees it.
        $template = (string) preg_replace('/\{[a-z_]+\}/', '', $template);

        $template = trim((string) preg_replace('/\s{2,}/', ' ', $template));

        // Never render an empty tile. A watermark that says nothing still
        // darkens the video, so it looks broken while protecting nothing.
        return $template === '' ? 'confidential' : $template;
    }

    /**
     * Clamp the opacity to a range that is both visible and watchable.
     *
     * Below about 4% it vanishes on bright footage and marks nothing; above
     * 40% the video is unwatchable and someone will simply turn the plugin
     * off, which protects less than a faint watermark would.
     */
    public static function clampOpacity(mixed $value): float
    {
        $opacity = is_numeric($value) ? (float) $value : 0.12;

        return max(0.04, min(0.40, $opacity));
    }

    private static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
