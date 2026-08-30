<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\ServiceWorker;

/**
 * Making the site installable.
 *
 * A manifest, an icon, one service worker, and the page it shows when the
 * network is gone. Nothing here caches content — see ServiceWorker for why.
 */
final class PwaController extends Controller
{
    /**
     * The web app manifest.
     *
     * Built from the theme's own settings rather than a file, because this is
     * a white-label product: the name, colours and start page belong to
     * whoever installed it, and a static manifest would make every install
     * claim to be the same app.
     */
    public function manifest(Request $request): Response
    {
        $themes = $this->themeManager();
        $name = (string) ($themes->setting('site_name', 'Video Portal') ?? 'Video Portal');

        $manifest = [
            'name'       => $name,
            /*
             * Twelve characters is roughly what a home screen shows before it
             * truncates. Cutting it here means choosing where, rather than
             * letting the launcher cut mid-word.
             */
            'short_name' => mb_substr($name, 0, 12),
            'start_url'  => '/',
            /*
             * The scope is the whole site, so following a link inside the app
             * stays in the app. Narrower and every category page would bounce
             * the person out to a browser tab.
             */
            'scope'      => '/',
            'display'    => 'standalone',
            'background_color' => $this->colour('bg', '#0f172a'),
            'theme_color'      => $this->colour('accent', '#38bdf8'),
            /*
             * PNG at 192 and 512, and DELIBERATELY NO SVG.
             *
             * The first version of this shipped a single SVG at sizes:"any",
             * on the assumption that Chrome would use it and only iOS would
             * fall back. That was wrong in the way that matters: Chrome
             * Android then offers "Create shortcut" instead of "Install",
             * because its installability check looks for a declared 192 and a
             * declared 512 — and an SVG at "any" satisfies neither. There is a
             * Chromium bug for exactly this shape (issue 40925759), where
             * removing sizes:"any" from an SVG entry is what makes a site
             * installable again.
             *
             * So these are raster files, served straight off disk by the web
             * server rather than generated: gd is only RECOMMENDED on these
             * hosts, and the one asset that decides whether the app can be
             * installed must not depend on an optional extension, or on PHP
             * running at all.
             *
             * The cost is that every install ships the same mark rather than
             * one in the site's own accent. Replacing public/icon-192.png and
             * public/icon-512.png changes it; a customizer upload would be the
             * better answer and is not built.
             *
             * Both purposes are declared. A maskable-only set fails the check
             * — Chrome wants at least one `any` — and an `any`-only set gets
             * letterboxed inside whatever shape the launcher uses.
             */
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        return Response::json($manifest)
            ->header('Content-Type', 'application/manifest+json; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * The app icon, drawn rather than uploaded.
     *
     * SVG so it needs no image library — gd is recommended, not required, and
     * a product that refuses to be installable on a host without it would be
     * choosing the wrong tradeoff.
     *
     * A play mark on the site's own accent colour. Deliberately geometric: a
     * letter would need a font file, and the first letter of a site name is a
     * poor mark in most alphabets anyway.
     */
    public function icon(Request $request): Response
    {
        $accent = $this->colour('accent', '#38bdf8');

        /*
         * Drawn inside the middle 60% of the canvas. A maskable icon can be
         * cropped to a circle by the launcher, and anything closer to the edge
         * than this loses its corners.
         */
        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img"
             aria-label="App icon">
          <rect width="512" height="512" fill="{$accent}"/>
          <path d="M204 156 L372 256 L204 356 Z" fill="#ffffff"/>
        </svg>
        SVG;

        return Response::text($svg)
            ->header('Content-Type', 'image/svg+xml; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * The service worker.
     *
     * Served by PHP rather than written into the document root, for the reason
     * the push plugin already gave: writing a file into public/ at runtime
     * fails silently on a host with tight permissions and leaves a stale one
     * behind afterwards.
     */
    public function serviceWorker(Request $request): Response
    {
        /*
         * Plugins append here rather than registering workers of their own. A
         * scope has one active worker, so a second registration at `/` silently
         * replaces the first — and looks entirely successful while doing it.
         *
         * @var string $extra
         */
        $extra = (string) apply_filters('service_worker', '');

        return Response::text(ServiceWorker::script($extra))
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            /*
             * NEVER CACHED, by anything.
             *
             * `public, max-age=300` was wrong, and wrong in a way that hides
             * itself: browsers largely bypass their own HTTP cache for a worker
             * script, so it looks fine in testing — but a CDN in front of the
             * site does not, and Cloudflare caches .js by extension. A stale
             * worker then keeps running with no way to replace it, and the
             * symptom is a site whose worker does not match its own source.
             *
             * A service worker is the one file where a stale copy cannot be
             * fixed by shipping a new one, so it is the one file that must not
             * be cached at all.
             */
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            /*
             * Without this a worker served from /sw.js could only control
             * /sw.js. Getting it wrong produces a worker that registers
             * successfully and controls nothing.
             */
            ->header('Service-Worker-Allowed', '/');
    }

    /**
     * What the app shows with no network.
     *
     * Deliberately contains no content and reads no session: it is precached,
     * which means it is stored on the device and shown to whoever opens the app
     * next. Anything personal on it would be a leak with a long tail.
     */
    public function offline(Request $request): Response
    {
        return $this->view(['offline'], [
            'title' => 'You are offline',
        ]);
    }

    /**
     * A theme colour, validated before it reaches a manifest or an SVG.
     *
     * The value comes from the customizer, where somebody can type anything.
     * Unvalidated it would be interpolated straight into an SVG document —
     * which is markup, so a stray quote is not a broken colour but an injected
     * attribute.
     */
    private function colour(string $key, string $fallback): string
    {
        $value = trim((string) ($this->themeManager()->setting($key, '') ?? ''));

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : $fallback;
    }
}
