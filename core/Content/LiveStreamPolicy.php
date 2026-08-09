<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * When a stream is live, and what may be embedded.
 *
 * Pure. Two decisions live here, and they are unrelated except that both are
 * things a person would otherwise get wrong quietly.
 *
 * WHAT A LIVE STREAM IS IN THIS PRODUCT
 *
 * Not a video. bunny.net Stream has no live ingest, and neither does any other
 * provider this ships with — so a live stream here is an EMBED somebody else
 * hosts (a YouTube or Vimeo live page, an Owncast instance, a restream) plus a
 * schedule and a place on this site. That is the honest shape: this portal
 * announces and frames the stream, and something else carries the pixels.
 *
 * The alternative — pretending to host it — would mean an ingest endpoint, a
 * transcoder and a CDN, none of which exist on shared hosting.
 */
final class LiveStreamPolicy
{
    /**
     * How long a stream with no end time stays "live" before it stops saying so.
     *
     * The safety net, and the most important number here. Somebody starts a
     * stream on Sunday morning and does not come back to end it; without this,
     * the site says LIVE NOW for a month, and after the second week nobody
     * believes the badge on the week it is true. Twelve hours is longer than
     * any service and shorter than the next one.
     */
    public const MAX_UNENDED_HOURS = 12;

    public const SCHEDULED = 'scheduled';
    public const LIVE = 'live';
    public const ENDED = 'ended';

    /**
     * What state a stream is in, right now.
     *
     * Evaluated from timestamps rather than from a flag a job flips, for the
     * reason scheduled publishing gives: cron is optional on these hosts and
     * pseudo-cron only fires on traffic, so a job-driven "go live" would go
     * live late, or on a quiet morning not at all. A comparison cannot be late.
     *
     * @param ?string $startsAt when it is due to begin; null means "no schedule"
     * @param ?string $endsAt   when it is due to finish, if anybody said
     * @param ?string $endedAt  when somebody actually ended it
     * @param ?int    $now      injected by the tests
     */
    public static function state(
        ?string $startsAt,
        ?string $endsAt,
        ?string $endedAt = null,
        ?int $now = null
    ): string {
        $now ??= time();

        // Ended by hand beats every other consideration. Somebody pressed a
        // button that says "this is over"; no schedule outranks that.
        if ($endedAt !== null && self::timestamp($endedAt) !== null && self::timestamp($endedAt) <= $now) {
            return self::ENDED;
        }

        $start = $startsAt === null ? null : self::timestamp($startsAt);
        $end = $endsAt === null ? null : self::timestamp($endsAt);

        /*
         * No start time at all means somebody made a stream and never
         * scheduled it. Treated as not yet live rather than live forever: the
         * failure of the first reading is an announcement nobody sees, and of
         * the second a permanent LIVE badge on a site with no stream.
         */
        if ($start === null) {
            return self::SCHEDULED;
        }

        if ($now < $start) {
            return self::SCHEDULED;
        }

        if ($end !== null) {
            return $now < $end ? self::LIVE : self::ENDED;
        }

        // No end time, so the safety net decides.
        return $now < $start + (self::MAX_UNENDED_HOURS * 3600) ? self::LIVE : self::ENDED;
    }

    /**
     * Why this embed URL cannot be used, or null.
     *
     * The security decision in this class. The value goes into an iframe's src
     * attribute, and an attribute is a place where a scheme other than http(s)
     * is not merely wrong but executable: `javascript:` in a src runs, and
     * `data:` can carry a whole document with a script in it. Escaping the
     * attribute does not help, because the string is legal HTML — it is the
     * SCHEME that is the problem.
     *
     * An administrator typed this, so it is not a defence against a hostile
     * admin. It is a defence against one who pasted something from an email,
     * and against a stored value that later reaches a context nobody checked.
     */
    public static function rejectionReason(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'Enter the address of the stream to embed.';
        }

        if (strlen($url) > 500) {
            return 'That address is too long.';
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            return 'That does not look like a full address. It needs to start with https://.';
        }

        /*
         * The scheme is checked BEFORE anything else about the URL, and the
         * order is deliberate.
         *
         * An earlier version required a host first, which happened to catch
         * `javascript:` and `data:` — they have no host — and so the scheme
         * check appeared to be about http versus https. It was not doing the
         * work its comment claimed, and a mutation removing it killed only one
         * test. Now the dangerous schemes are refused by the rule that exists
         * to refuse them.
         *
         * https only, and not merely for tidiness in either direction:
         * `javascript:` in an iframe src EXECUTES, and escaping the attribute
         * does not help because the string is legal HTML; while plain http is
         * refused because a browser will not load an insecure frame inside a
         * secure page, so it renders as a blank rectangle with the explanation
         * in a console nobody has open.
         */
        if (strtolower($parts['scheme']) !== 'https') {
            return 'Use an https address. Anything else either will not load inside a secure page or '
                 . 'is not an address a browser should be asked to open at all.';
        }

        if (!isset($parts['host']) || $parts['host'] === '') {
            return 'That address has no site in it. It should look like https://example.com/…';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Remove the username and password from the address — they would be visible to anybody '
                 . 'who viewed the page source.';
        }

        return null;
    }

    /**
     * A best-effort nudge for the commonest mistake.
     *
     * People paste the page they are WATCHING rather than the embed, and the
     * result is a frame the other site refuses to render — with, again, an
     * explanation only the console has. This does not rewrite anything: a
     * silent rewrite is worse, because the stored value would stop matching
     * what somebody typed and the next edit would be a surprise.
     */
    public static function embedWarning(string $url): ?string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');

        if (str_contains($host, 'youtube.com') && !str_starts_with($path, '/embed/')) {
            return 'YouTube watch pages cannot be framed. Use the address from Share → Embed, '
                 . 'which looks like https://www.youtube.com/embed/…';
        }

        if ($host === 'youtu.be') {
            return 'A youtu.be link cannot be framed. Use the address from Share → Embed, '
                 . 'which looks like https://www.youtube.com/embed/…';
        }

        if (str_contains($host, 'vimeo.com') && !str_contains($host, 'player.')) {
            return 'Vimeo pages cannot be framed. Use the player address, which looks like '
                 . 'https://player.vimeo.com/video/…';
        }

        return null;
    }

    private static function timestamp(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }
}
