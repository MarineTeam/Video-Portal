<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginHeader;
use Portal\Themes\ThemeManifest;

/**
 * Serves files out of themes/ and plugins/.
 *
 * Those directories sit outside the document root on purpose — it is what
 * stops anyone fetching a theme's functions.php or a plugin's uninstall.php as
 * plain text and reading whatever is in it. Serving their assets therefore has
 * to go through PHP, which makes this a path-traversal target and the reason
 * for the paranoia below.
 */
final class AssetController extends Controller
{
    /**
     * Extensions that may be served. An allowlist rather than a blocklist:
     * a blocklist forgets .phtml, .phar, .inc, and whatever the next PHP
     * handler extension turns out to be.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'text/javascript; charset=utf-8',
        'mjs'   => 'text/javascript; charset=utf-8',
        'map'   => 'application/json; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'txt'   => 'text/plain; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
    ];

    /** @param array<string, string> $params */
    public function theme(Request $request, array $params): Response
    {
        $slug = ThemeManifest::sanitizeSlug($params['theme'] ?? '');
        if ($slug === '') {
            throw HttpException::notFound();
        }

        // Confined to assets/, exactly like plugins. Serving from the theme
        // root would make theme.json fetchable — harmless in itself, but it
        // means the served set is defined by the extension allowlist rather
        // than by the author's intent, and .json is on that list.
        return $this->serve(PORTAL_THEMES . '/' . $slug . '/assets', $params['path'] ?? '');
    }

    /** @param array<string, string> $params */
    public function plugin(Request $request, array $params): Response
    {
        $slug = PluginHeader::sanitizeSlug($params['plugin'] ?? '');
        if ($slug === '') {
            throw HttpException::notFound();
        }

        // Plugin assets are confined to an assets/ subdirectory, so a plugin's
        // PHP is never even a candidate path.
        return $this->serve(PORTAL_PLUGINS . '/' . $slug . '/assets', $params['path'] ?? '');
    }

    /**
     * Serve $relative from inside $root, or 404.
     *
     * The containment check is done on the REAL path after symlinks are
     * resolved. Checking the requested string instead is the classic mistake:
     * "assets/../../config.php" normalises away, and a symlink pointing
     * outside the tree defeats string comparison entirely.
     */
    private function serve(string $root, string $relative): Response
    {
        $realRoot = realpath($root);
        if ($realRoot === false) {
            throw HttpException::notFound();
        }

        // Reject traversal and NUL bytes before touching the filesystem.
        $relative = str_replace('\\', '/', $relative);
        if (
            $relative === ''
            || str_contains($relative, "\0")
            || str_contains($relative, '..')
            || str_starts_with($relative, '/')
        ) {
            throw HttpException::notFound();
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$extension])) {
            throw HttpException::notFound();
        }

        $path = realpath($realRoot . '/' . $relative);
        if ($path === false || !is_file($path)) {
            throw HttpException::notFound();
        }

        // The decisive check: after resolution, is the file still inside?
        $normalisedRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
        $normalisedPath = str_replace('\\', '/', $path);

        if (!str_starts_with($normalisedPath, $normalisedRoot)) {
            throw HttpException::notFound();
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw HttpException::notFound();
        }

        $etag = '"' . md5($contents) . '"';

        $response = new Response($contents, 200);
        $response->header('Content-Type', self::ALLOWED[$extension]);
        $response->header('Content-Length', (string) strlen($contents));
        $response->header('ETag', $etag);
        // Theme assets are public and change only when the theme does.
        $response->header('Cache-Control', 'public, max-age=3600');
        // SVG can carry script; refusing to sniff keeps a served image an image.
        $response->header('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
