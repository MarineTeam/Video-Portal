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
            'icons' => [
                [
                    'src'   => '/icon.svg',
                    /*
                     * "any" is what an SVG declares: it has no fixed pixel
                     * size, which is the reason for using one here — a PNG set
                     * would mean generating images, and gd is only RECOMMENDED
                     * on these hosts, not required.
                     *
                     * The cost is stated rather than hidden: iOS ignores SVG
                     * for a home-screen icon and will use a screenshot of the
                     * page instead. Android and desktop Chrome use this.
                     */
                    'sizes' => 'any',
                    'type'  => 'image/svg+xml',
                    /*
                     * `maskable` lets a launcher crop this to whatever shape it
                     * uses without clipping the mark, which the icon is drawn
                     * with padding to allow.
                     */
                    'purpose' => 'any maskable',
                ],
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
            // Short, because this is how a fix to the worker reaches anybody.
            ->header('Cache-Control', 'public, max-age=300')
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
