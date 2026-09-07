<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Content\AssetPolicy;
use Portal\Http\HttpException;
use Portal\Http\Response;

/**
 * Handing an uploaded file to a browser, once.
 *
 * Extracted so there is exactly ONE of these. Every header below is load-bearing
 * — nosniff, the disposition, the private cache directive — and a second copy of
 * this logic somewhere else would eventually drift from it. The way that shows
 * up is a file served without nosniff being rendered as HTML in this site's own
 * origin, which is a hole rather than a cosmetic difference.
 *
 * Read into memory rather than streamed, because Response is a value object that
 * carries a body. The upload size limit is what makes that acceptable; a larger
 * limit would need a streaming response, and that is the change to make if this
 * ever holds video files.
 */
final class FileStream
{
    /**
     * @param array<string, mixed> $asset a {file_assets} row
     * @param bool $inline true to let the browser display it in place
     */
    public static function send(string $path, array $asset, bool $inline = false): Response
    {
        $body = @file_get_contents($path);

        if ($body === false) {
            throw HttpException::notFound('There is no file at that address.');
        }

        $name = AssetPolicy::displayName((string) $asset['original_name']);

        return (new Response($body))
            /*
             * The type comes from the extension allowlist, never from what the
             * uploader's browser claimed.
             */
            ->header('Content-Type', (string) $asset['content_type'])
            ->header('Content-Length', (string) strlen($body))

            /*
             * inline only where the point is to display it — a book has to be
             * rendered by a PDF or EPUB reader in the page, and a download
             * prompt instead of a hymnal on a Sunday morning is the feature not
             * working. Everything else is an attachment, which is the behaviour
             * that cannot surprise anybody.
             *
             * The filename is quoted after every quote and newline has been
             * stripped out of it.
             */
            ->header(
                'Content-Disposition',
                ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"'
            )

            /*
             * The browser must not second-guess the type. Without this, a file
             * served as text/plain that happens to look like HTML is rendered
             * as HTML by some browsers — in this site's origin.
             */
            ->header('X-Content-Type-Options', 'nosniff')

            /*
             * Private, because the answer depends on who asked. A shared cache
             * holding one viewer's copy of a members-only book and serving it
             * to a stranger is the failure the access-checked route exists to
             * prevent, and a cache header is all that stands between the two.
             */
            ->private();
    }
}
