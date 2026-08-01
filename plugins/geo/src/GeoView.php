<?php

declare(strict_types=1);

namespace Portal\Plugins\Geo;

/**
 * The pages a blocked request sees.
 *
 * Self-contained, like the share pages and for the same reason: this renders
 * before anything else has run, and it has to work when the theme does not.
 */
final class GeoView
{
    /**
     * Blocked from the public site.
     *
     * Says what happened and nothing else. Naming the allowed countries would
     * turn the page into a lookup table for anyone probing which regions to
     * appear from.
     */
    public static function blocked(): string
    {
        return self::page(
            'Not available in your location',
            '<p>This site is not available from where you are connecting.</p>'
            . '<p class="muted">If you believe that is wrong, contact whoever runs this site.</p>'
        );
    }

    /**
     * Blocked from the admin area.
     *
     * This one deliberately explains itself, because the person most likely to
     * be reading it is the site owner who has just locked themselves out — and
     * the fix is a file edit they cannot guess. What it gives away (that the
     * site restricts admin access by country) is worth far less than a site
     * owner who can get back in without support.
     */
    public static function blockedAdmin(string $country): string
    {
        $seen = $country === '' ? 'unknown' : e($country);

        return self::page(
            'The admin area is restricted here',
            '<p>Administration is limited to certain countries. This request appears to come from '
            . "<strong>{$seen}</strong>.</p>"
            . '<p class="muted">If this is your site: the country list is <code>admin_geo_whitelist</code> '
            . 'in <code>config.php</code>. Edit it over FTP, or clear it to remove the restriction. '
            . 'It is kept in that file, and not in the database, precisely so this page is recoverable.</p>'
        );
    }

    private static function page(string $heading, string $body): string
    {
        $title = e($heading);

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{$title}</title>
        <style>
          :root { color-scheme: dark; }
          body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
                 padding:2rem 1.25rem; background:#0f172a; color:#e2e8f0;
                 font:16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
          main { max-width:32rem; background:rgba(30,41,59,.6); border:1px solid rgba(148,163,184,.18);
                 border-radius:16px; padding:2rem; }
          h1 { font-size:1.375rem; margin:0 0 1rem; font-weight:600; line-height:1.3; }
          p { margin:0 0 1rem; }
          .muted { color:#94a3b8; font-size:.9375rem; }
          code { background:rgba(15,23,42,.8); padding:.125rem .375rem; border-radius:5px; font-size:.875rem; }
        </style>
        </head>
        <body><main><h1>{$title}</h1>{$body}</main></body>
        </html>
        HTML;
    }
}
