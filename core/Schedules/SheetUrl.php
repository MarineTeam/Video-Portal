<?php

declare(strict_types=1);

namespace Portal\Schedules;

/**
 * Turning what somebody pastes into something that can be fetched.
 *
 * Whoever keeps a rota pastes what is in their address bar, which is an /edit
 * URL with a fragment on the end. Asking them to construct an export URL by
 * hand is asking most of them to give up, so this does it — including carrying
 * the `gid` across, because a sheet with four tabs exports the first one unless
 * told otherwise, and "it synced the wrong tab" is a confusing failure.
 *
 * # ONLY GOOGLE
 *
 * Any URL would mean an SSRF check on every sync — this runs on a schedule, on
 * a host with no shell, and a hostname that resolved publicly on Tuesday can
 * resolve to 127.0.0.1 on Wednesday, which is the reasoning the webhook
 * delivery already carries. Restricting the source to docs.google.com makes the
 * question not arise rather than answering it repeatedly. The cost is real and
 * is stated on the screen: a CSV kept somewhere else has to be republished
 * through a sheet.
 */
final class SheetUrl
{
    private const HOST = 'docs.google.com';

    /**
     * The CSV a sync should fetch, or null if this is not a sheet.
     */
    public static function toCsv(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $parts = parse_url($raw);

        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== self::HOST
        ) {
            return null;
        }

        $path = $parts['path'] ?? '';

        // Already an export or a published-to-web CSV. Left exactly as it is:
        // somebody who built one deliberately knows which tab they meant.
        if (str_contains($path, '/export') || str_contains($path, '/pub')) {
            return $raw;
        }

        if (preg_match('#^/spreadsheets/d/([A-Za-z0-9_-]{10,})#', $path, $m) !== 1) {
            return null;
        }

        $url = sprintf('https://%s/spreadsheets/d/%s/export?format=csv', self::HOST, $m[1]);

        $gid = self::gid($parts);

        return $gid === null ? $url : $url . '&gid=' . $gid;
    }

    /**
     * Which tab. It lives in the fragment on an /edit URL and in the query on
     * a link somebody was sent, so both are looked at.
     *
     * @param array<string, string|int> $parts
     */
    private static function gid(array $parts): ?string
    {
        foreach ([$parts['fragment'] ?? '', $parts['query'] ?? ''] as $source) {
            if (preg_match('/(?:^|&)gid=(\d+)/', (string) $source, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }
}
