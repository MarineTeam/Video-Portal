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
use Portal\Support\FileStream;

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
        // Through the one place that knows every header this needs. See
        // Portal\Support\FileStream — a second copy of that list eventually
        // drifts, and the way it shows is a missing nosniff.
        return FileStream::send($path, $asset);
    }
}
