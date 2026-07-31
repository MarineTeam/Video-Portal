<?php

declare(strict_types=1);

namespace Portal\Http;

/**
 * Standalone error page.
 *
 * Deliberately self-contained — no theme, no database, no plugins. It has to
 * render when the theme is broken, when the database is unreachable, and
 * during a half-finished install, which is exactly when a themed error page
 * would fail to render and produce a blank screen instead.
 */
final class ErrorPage
{
    public static function render(
        int $status,
        string $title,
        string $message,
        ?string $detail = null,
        ?string $homeUrl = null
    ): string {
        $status = e((string) $status);
        $title = e($title);
        $message = e($message);

        $detailBlock = '';
        if ($detail !== null && $detail !== '') {
            $detailBlock = '<pre class="detail">' . e($detail) . '</pre>';
        }

        $homeBlock = '';
        if ($homeUrl !== null) {
            $homeBlock = '<p><a class="btn" href="' . e($homeUrl) . '">Back to the library</a></p>';
        }

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{$title}</title>
        <style>
          :root { color-scheme: dark; }
          * { box-sizing: border-box; }
          body {
            margin: 0; min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 2rem;
            background: #0f172a; color: #e2e8f0;
            font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Inter, sans-serif;
          }
          .card {
            max-width: 34rem; width: 100%;
            background: rgba(30, 41, 59, .6);
            border: 1px solid rgba(148, 163, 184, .18);
            border-radius: 16px;
            padding: 2.5rem;
            backdrop-filter: blur(12px);
          }
          .status { font-size: .75rem; letter-spacing: .12em; text-transform: uppercase;
                    color: #94a3b8; margin: 0 0 .75rem; }
          h1 { font-size: 1.5rem; margin: 0 0 .75rem; font-weight: 600; }
          p { margin: 0 0 1rem; color: #cbd5e1; }
          .detail {
            margin-top: 1.5rem; padding: 1rem;
            background: rgba(15, 23, 42, .8);
            border: 1px solid rgba(148, 163, 184, .15);
            border-radius: 10px;
            font-size: .8125rem; color: #94a3b8;
            white-space: pre-wrap; word-break: break-word; overflow-x: auto;
          }
          .btn {
            display: inline-block; margin-top: .5rem;
            padding: .625rem 1.25rem; border-radius: 10px;
            background: #38bdf8; color: #0f172a;
            font-weight: 600; text-decoration: none;
          }
        </style>
        </head>
        <body>
          <main class="card">
            <p class="status">Error {$status}</p>
            <h1>{$title}</h1>
            <p>{$message}</p>
            {$homeBlock}
            {$detailBlock}
          </main>
        </body>
        </html>
        HTML;
    }
}
