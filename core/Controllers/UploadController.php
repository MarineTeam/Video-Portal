<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;
use Portal\Support\RateLimit;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * Direct browser-to-provider uploads.
 *
 * The file never passes through this server. That is not an optimisation — on
 * shared hosting a 2GB upload through PHP would hit the memory limit, the POST
 * size limit, and the request timeout, in roughly that order. Instead the
 * server signs a short-lived ticket authorising the upload of one specific
 * video, and the browser talks to the provider directly.
 *
 * The API key is used to compute the signature and never leaves this process.
 */
final class UploadController extends Controller
{
    /** Creating a video record at the provider is cheap but not free. */
    private const TICKETS_PER_HOUR = 60;

    public function ticket(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_VIDEOS);

        $user = $this->user();
        $limiter = new RateLimit($this->db());

        if (!$limiter->allow('upload:' . ($user?->id ?? 0), self::TICKETS_PER_HOUR, 3600)) {
            throw HttpException::tooManyRequests('Too many uploads started. Try again shortly.');
        }

        $title = trim((string) ($request->data('title') ?? ''));
        if ($title === '') {
            throw HttpException::badRequest('The upload needs a title.');
        }
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }

        $collectionId = $request->data('collectionId');
        $collectionId = is_string($collectionId) && $collectionId !== '' ? $collectionId : null;

        /** @var VideoProvider $provider */
        $provider = $this->container->get(VideoProvider::class);

        try {
            $ticket = $provider->createUploadTicket($title, $collectionId);
        } catch (Throwable $e) {
            throw HttpException::upstream('The video service would not accept the upload: ' . $e->getMessage());
        }

        // Record it immediately: the upload UI needs something to track, and a
        // cancelled upload needs something to clean up.
        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);
        $video = $videos->createPlaceholder($ticket->providerId, $title);

        Audit::log($this->db(), $user?->email, 'video.upload.start', 'video', (string) $video->id, $title);

        return $this->json([
            'videoId' => $video->id,
            'upload'  => $ticket->toArray(),
        ]);
    }

    /**
     * Called by the browser once the bytes are transferred.
     *
     * Only a bookkeeping step — the provider is already encoding. Nothing here
     * is trusted for correctness, because the client controls it; the eventual
     * sync from the provider is what establishes real state.
     */
    public function complete(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_VIDEOS);

        $videoId = (int) ($request->data('videoId') ?? 0);
        if ($videoId <= 0) {
            throw HttpException::badRequest('A video id is required.');
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);
        $video = $videos->find($videoId);

        if ($video === null) {
            throw HttpException::notFound('That upload is not recognised.');
        }

        // Refresh from the provider so the row reflects reality rather than
        // what the browser claims happened.
        try {
            /** @var VideoProvider $provider */
            $provider = $this->container->get(VideoProvider::class);
            $meta = $provider->getVideo($video->providerId);

            if ($meta !== null) {
                $videos->syncFromProvider([$meta]);
            }
        } catch (Throwable $e) {
            error_log('Portal: could not refresh a finished upload: ' . $e->getMessage());
        }

        Audit::log($this->db(), $this->user()?->email, 'video.upload.finish', 'video', (string) $videoId, $video->title);

        return $this->json(['ok' => true, 'videoId' => $videoId]);
    }

    /**
     * Abandon an upload.
     *
     * Deletes the half-created video at the provider as well as locally.
     * Without this, every cancelled upload leaves an empty video in the
     * library that has to be tidied by hand.
     */
    public function cancel(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_VIDEOS);

        $videoId = (int) ($request->data('videoId') ?? 0);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);
        $video = $videos->find($videoId);

        if ($video === null) {
            // Already gone is the state the caller wanted.
            return $this->json(['ok' => true]);
        }

        try {
            /** @var VideoProvider $provider */
            $provider = $this->container->get(VideoProvider::class);
            $provider->deleteVideo($video->providerId);
        } catch (Throwable $e) {
            // Still remove it locally: a stray provider record is a smaller
            // problem than a permanently broken row in the library.
            error_log('Portal: could not delete a cancelled upload at the provider: ' . $e->getMessage());
        }

        $videos->forceDelete($videoId);

        Audit::log($this->db(), $this->user()?->email, 'video.upload.cancel', 'video', (string) $videoId, $video->title);

        return $this->json(['ok' => true]);
    }

    /**
     * Encoding progress, polled by the admin UI.
     */
    public function status(Request $request): Response
    {
        $this->require(Capability::MANAGE_VIDEOS);

        $ids = array_values(array_filter(
            array_map('intval', $request->inputArray('ids')),
            static fn (int $id): bool => $id > 0
        ));

        if ($ids === []) {
            $single = (int) ($request->query('videoId') ?? 0);
            if ($single > 0) {
                $ids = [$single];
            }
        }

        if ($ids === []) {
            return $this->json(['videos' => []]);
        }

        // Bounded so a crafted request cannot ask about ten thousand videos.
        $ids = array_slice($ids, 0, 50);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        /** @var VideoProvider|null $provider */
        $provider = null;
        try {
            $provider = $this->container->get(VideoProvider::class);
        } catch (Throwable) {
            $provider = null;
        }

        $out = [];
        foreach ($ids as $id) {
            $video = $videos->find($id);
            if ($video === null) {
                continue;
            }

            // Ask the provider while still encoding; once ready the local row
            // is authoritative and polling would be wasted calls.
            if ($provider !== null && $video->status === 'processing') {
                try {
                    $meta = $provider->getVideo($video->providerId);
                    if ($meta !== null) {
                        $videos->syncFromProvider([$meta]);
                        $video = $videos->find($id) ?? $video;
                    }
                } catch (Throwable) {
                    // Report what we have.
                }
            }

            $out[] = [
                'id'       => $video->id,
                'status'   => $video->status,
                'progress' => $video->encodeProgress,
                'title'    => $video->title,
            ];
        }

        return $this->json(['videos' => $out]);
    }
}
