<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\AssetPolicy;
use Portal\Content\AssetRepository;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;

/**
 * Serving an attachment.
 *
 * This route is the reason the files live outside the document root. A file
 * under public/ is reachable by URL whatever the database says, so a
 * members-only video's notes would be downloadable by anybody who had the path
 * — and an unguessable filename is obscurity, not permission.
 *
 * Here the video's own visibility decides, re-checked on every request, so
 * unpublishing a video takes its handout with it.
 */
final class AssetDownloadController extends Controller
{
    /** @param array<string, string> $params */
    public function download(Request $request, array $params): Response
    {
        /** @var AssetRepository $assets */
        $assets = $this->container->get(AssetRepository::class);

        $asset = $assets->find((int) ($params['id'] ?? 0));

        if ($asset === null) {
            throw HttpException::notFound('There is no file at that address.');
        }

        $this->assertVisible($asset);

        $path = $assets->absolutePath((string) $asset['path']);

        if ($path === null || !is_file($path)) {
            /*
             * The row exists and the file does not. A 404 rather than a 500:
             * from outside these are the same thing, and the log line is where
             * an administrator finds out it was the second.
             */
            error_log('Portal: attachment ' . $asset['id'] . ' has no file at ' . $asset['path']);

            throw HttpException::notFound('There is no file at that address.');
        }

        return $this->stream($path, $asset);
    }

    /**
     * The attachment inherits its video's rules, exactly.
     *
     * @param array<string, mixed> $asset
     */
    private function assertVisible(array $asset): void
    {
        $videoId = $asset['video_id'] === null ? 0 : (int) $asset['video_id'];

        if ($videoId <= 0) {
            // Not attached to anything. Nothing creates these yet; refusing is
            // the safe reading of a row whose rules are undefined.
            throw HttpException::notFound('There is no file at that address.');
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);
        $video = $videos->find($videoId);

        if ($video === null) {
            throw HttpException::notFound('There is no file at that address.');
        }

        if ($this->guard()->can(Capability::MANAGE_VIDEOS)) {
            return;
        }

        // A 404 rather than a 403 throughout, matching /watch: telling somebody
        // that a file exists but is private is itself a leak.
        if (!$video->isVisible()) {
            throw HttpException::notFound('There is no file at that address.');
        }

        if ($video->memberOnly) {
            $user = $this->user();

            if ($user === null || !($user->isAdmin() || $user->authorized)) {
                throw HttpException::notFound('There is no file at that address.');
            }
        }
    }

    /**
     * Send the file.
     *
     * @param array<string, mixed> $asset
     */
    private function stream(string $path, array $asset): Response
    {
        $name = AssetPolicy::displayName((string) $asset['original_name']);

        /*
         * Read into memory rather than streamed, because Response is a value
         * object that carries a body. The size limit on upload is what makes
         * that acceptable — 25MB is inside the memory limit of every host this
         * targets. A larger limit would need a streaming response, and that is
         * the change to make if this ever holds video files.
         */
        $body = file_get_contents($path);

        if ($body === false) {
            throw HttpException::notFound('There is no file at that address.');
        }

        return (new Response($body))
            /*
             * The type comes from the extension allowlist, never from what the
             * uploader's browser claimed.
             */
            ->header('Content-Type', (string) $asset['content_type'])
            ->header('Content-Length', (string) strlen($body))

            /*
             * attachment, not inline. Even for types a browser would happily
             * render, downloading is the behaviour that cannot surprise
             * anybody — and the filename is quoted after every quote and
             * newline has been stripped out of it.
             */
            ->header('Content-Disposition', 'attachment; filename="' . $name . '"')

            /*
             * The browser must not second-guess the type. Without this, a file
             * served as text/plain that happens to look like HTML is rendered
             * as HTML by some browsers — in this site's origin.
             */
            ->header('X-Content-Type-Options', 'nosniff')

            /*
             * Private, because the answer depends on who asked. A shared cache
             * holding one viewer's copy of a members-only handout and serving
             * it to a stranger is the failure this whole route exists to
             * prevent.
             */
            ->private();
    }
}
