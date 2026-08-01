<?php

declare(strict_types=1);

namespace Portal\Sharing;

/**
 * The pages a share recipient sees.
 *
 * Self-contained, and deliberately not themed. A recipient may never have
 * visited the site; they followed a link somebody sent them. These pages have
 * to render when the active theme is broken, and they should not change
 * appearance because an admin installed a new theme.
 *
 * The refusal pages are the interesting ones. Their wording is a security
 * decision, not copywriting — see gone() and mismatch().
 */
final class ShareView
{
    /**
     * Revoked, expired, unknown, or malformed.
     *
     * One page for all four. Distinguishing "revoked" from "expired" leaks a
     * decision the recipient has no business knowing, and distinguishing
     * "never existed" from "expired" turns the URL into a probe for whether a
     * given id was ever real.
     */
    public static function gone(string $siteName): string
    {
        return self::page($siteName, 'This link is no longer available', <<<HTML
        <p>Private links stop working after a while, and can be turned off at any time.</p>
        <p class="muted">If you still need access, ask whoever sent it to you for a new link.</p>
        HTML);
    }

    /**
     * Signed in as somebody else.
     *
     * Never names the intended recipient. Someone holding a forwarded link
     * must not learn who it was meant for.
     */
    public static function mismatch(string $siteName): string
    {
        return self::page($siteName, 'This link was made for someone else', <<<HTML
        <p>Private links only work for the person they were sent to.</p>
        <p class="muted">If it was sent to you, sign in with the email address where you received it.</p>
        <p><a class="btn" href="/auth/logout">Sign in as someone else</a></p>
        HTML);
    }

    /** The video provider is not answering. Not the recipient's problem. */
    public static function unavailable(string $siteName): string
    {
        return self::page($siteName, 'This video cannot be played right now', <<<HTML
        <p>The video service is not responding. This is a problem at our end, not with your link.</p>
        <p class="muted">Please try again in a few minutes.</p>
        HTML);
    }

    /**
     * The account-free gate's form.
     *
     * Says as little as possible about what it is guarding.
     */
    public static function gateForm(string $siteName, string $action, string $token): string
    {
        $actionAttr = e($action);
        $tokenAttr = e($token);

        return self::page($siteName, 'Confirm your email address', <<<HTML
        <p>To open this, confirm the email address it was sent to. We will send you a link.</p>
        <form method="post" action="{$actionAttr}">
          <input type="hidden" name="_token" value="{$tokenAttr}">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" required autofocus autocomplete="email">
          <button class="btn" type="submit">Send me a link</button>
        </form>
        HTML);
    }

    /**
     * Shown after a link request.
     *
     * Phrased so it is true whether or not anything was sent. The gate never
     * reveals whether an address was right, and this page must not undo that
     * by promising a message that is not coming.
     */
    public static function linkSent(string $siteName): string
    {
        return self::page($siteName, 'Check your email', <<<HTML
        <p>If that address has access, a sign-in link is on its way. It works once and expires in an hour.</p>
        <p class="muted">Nothing arrived? The link may have been sent to a different address, or it may
           no longer be available.</p>
        HTML);
    }

    /**
     * The player.
     *
     * The embed URL is signed and short-lived, minted for this request and
     * never stored.
     */
    public static function player(
        string $siteName,
        string $title,
        string $embedUrl,
        string $shareId,
        string $viewerEmail,
        string $overlay = '',
        ?string $bundleUrl = null
    ): string {
        $titleAttr = e($title);
        $embedAttr = e($embedUrl);
        $shareAttr = e($shareId);

        $bundleLink = $bundleUrl === null
            ? ''
            : '<p class="muted"><a href="' . e($bundleUrl) . '">See everything shared with you</a></p>';

        return self::page($siteName, $title, <<<HTML
        <div class="player">
          <iframe
            src="{$embedAttr}"
            title="{$titleAttr}"
            loading="lazy"
            allow="accelerometer; gyroscope; encrypted-media; picture-in-picture; fullscreen"
            allowfullscreen></iframe>
          {$overlay}
        </div>
        {$bundleLink}
        <div id="share-tracking" data-share-id="{$shareAttr}" hidden></div>
        <script src="/assets/share-track.js" defer></script>
        HTML, wide: true);
    }

    /**
     * The bundle index.
     *
     * @param list<array{title: string, url: string, expires: string}> $items
     */
    public static function bundle(string $siteName, array $items): string
    {
        $rows = '';

        foreach ($items as $item) {
            $rows .= sprintf(
                '<li><a href="%s"><span class="item-title">%s</span>
                   <span class="muted">Available %s</span></a></li>',
                e($item['url']),
                e($item['title']),
                e($item['expires'])
            );
        }

        $count = count($items);
        $heading = $count === 1 ? '1 video shared with you' : "{$count} videos shared with you";

        return self::page($siteName, $heading, '<ul class="items">' . $rows . '</ul>');
    }

    // ----------------------------------------------------------------- shell

    private static function page(string $siteName, string $heading, string $body, bool $wide = false): string
    {
        $site = e($siteName);
        $title = e($heading);
        $maxWidth = $wide ? '56rem' : '32rem';
        $css = self::css();

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{$title} — {$site}</title>
        <style>{$css}</style>
        </head>
        <body>
          <main class="card" style="max-width:{$maxWidth}">
            <p class="site">{$site}</p>
            <h1>{$title}</h1>
            {$body}
          </main>
        </body>
        </html>
        HTML;
    }

    private static function css(): string
    {
        return <<<'CSS'
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
               padding:2rem 1.25rem; background:#0f172a; color:#e2e8f0;
               font:16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Inter, sans-serif; }
        .card { width:100%; background:rgba(30,41,59,.6); border:1px solid rgba(148,163,184,.18);
                border-radius:16px; padding:2rem; backdrop-filter:blur(12px); }
        .site { margin:0 0 1rem; font-size:.75rem; letter-spacing:.12em; text-transform:uppercase;
                color:#94a3b8; }
        h1 { font-size:1.375rem; margin:0 0 1rem; font-weight:600; line-height:1.3; }
        p { margin:0 0 1rem; }
        .muted { color:#94a3b8; font-size:.9375rem; }
        a { color:#38bdf8; }
        label { display:block; font-size:.875rem; font-weight:550; margin:1.25rem 0 .375rem; }
        input { width:100%; padding:.625rem .875rem; border-radius:10px;
                border:1px solid rgba(148,163,184,.28); background:rgba(15,23,42,.55);
                color:#e2e8f0; font:inherit; font-size:.9375rem; }
        input:focus-visible, .btn:focus-visible { outline:2px solid #38bdf8; outline-offset:2px; }
        .btn { display:inline-block; margin-top:1.25rem; padding:.625rem 1.25rem; border-radius:10px;
               border:1px solid transparent; background:#38bdf8; color:#0b1220; font:inherit;
               font-weight:600; font-size:.9375rem; cursor:pointer; text-decoration:none; }
        .player { position:relative; aspect-ratio:16/9; width:100%; border-radius:12px;
                  overflow:hidden; background:#000; margin-bottom:1.25rem; }
        .player iframe { width:100%; height:100%; border:0; display:block; }
        .items { list-style:none; margin:0; padding:0; }
        .items li { border-bottom:1px solid rgba(148,163,184,.15); }
        .items a { display:flex; justify-content:space-between; gap:1rem; align-items:baseline;
                   padding:.875rem .25rem; color:#e2e8f0; text-decoration:none; flex-wrap:wrap; }
        .items a:hover { color:#38bdf8; }
        .item-title { font-weight:550; }
        CSS;
    }
}
