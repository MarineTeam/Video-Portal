<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * The page a visitor sees while the site is closed for work.
 *
 * Self-contained ON PURPOSE — no theme, no stylesheet, no asset request.
 *
 * The theme is frequently the thing being changed during a deploy, and a
 * maintenance page rendered through a half-updated theme is a maintenance page
 * that can fatal. This is also the response served while migrations are
 * pending, so it must not touch a table whose shape is currently in doubt: it
 * takes two strings and returns HTML, and that is the whole of it.
 *
 * The inline CSS is the same reason. A notice that loads without its stylesheet
 * reads as a broken site, which is precisely the impression the feature exists
 * to prevent.
 */
final class MaintenanceView
{
    public static function render(string $siteName, string $message): string
    {
        $siteName = htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        /*
         * `noindex` matters here. A 503 already tells a well-behaved crawler to
         * come back rather than drop the page, and this says so a second way —
         * because the cost of getting it wrong is a site quietly deindexed by a
         * deploy that took a minute.
         */
        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <meta name="robots" content="noindex">
          <title>Back shortly — {$siteName}</title>
          <style>
            :root { color-scheme: dark; }
            body {
              margin: 0; min-height: 100vh; display: grid; place-items: center;
              background: #0b1120; color: #e2e8f0; padding: 2rem;
              font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
            }
            main { max-width: 32rem; text-align: center; }
            h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
            p { margin: 0; color: #94a3b8; }
            .name { display: block; font-size: .8125rem; letter-spacing: .08em;
                    text-transform: uppercase; color: #64748b; margin-bottom: 1.5rem; }
          </style>
        </head>
        <body>
          <main>
            <span class="name">{$siteName}</span>
            <h1>Back shortly</h1>
            <p>{$message}</p>
          </main>
        </body>
        </html>
        HTML;
    }
}
