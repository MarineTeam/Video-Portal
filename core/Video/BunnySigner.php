<?php

declare(strict_types=1);

namespace Portal\Video;

/**
 * bunny.net token signing. Pure functions, no I/O, so it can be tested.
 *
 * This class is deliberately separate from the provider because these three
 * formulas are the single most fragile thing in the application. Getting one
 * wrong produces a 401 or 403 with no useful message, and the mistake is
 * invisible in code review — the signature just doesn't match.
 *
 * THREE DIFFERENT SCHEMES, TWO DIFFERENT KEYS:
 *
 *   1. Embed view token   — hex SHA-256, uses the Stream library's
 *                           "Token Authentication" key.
 *   2. CDN thumbnail token — base64url of RAW SHA-256, uses the PULL ZONE's
 *                           "URL Token Authentication" key. A different key
 *                           from (1), on a different bunny.net screen. Using
 *                           the embed key here is the single most common
 *                           misconfiguration and yields a silent 403.
 *   3. TUS upload signature — hex SHA-256 over a four-part concatenation
 *                           including the API key.
 *
 * Every input is trimmed. A trailing newline on a pasted key — which is what
 * happens when someone copies from a terminal or a text file — changes the
 * hash completely and produces exactly the same unhelpful 401.
 */
final class BunnySigner
{
    /**
     * Embed playback token.
     *
     *   token = sha256_hex(tokenAuthKey + videoId + expires)
     *
     * @param int $expires absolute unix timestamp
     */
    public static function embedToken(string $tokenAuthKey, string $videoId, int $expires): string
    {
        return hash('sha256', trim($tokenAuthKey) . trim($videoId) . $expires);
    }

    /**
     * The full embed URL. `autoplay=false` and no `preload` are deliberate:
     * preloading makes the player pull video bytes before anyone presses play,
     * which on a metered bunny.net account is real money for content nobody
     * watched.
     */
    public static function embedUrl(
        string $tokenAuthKey,
        string $libraryId,
        string $videoId,
        int $expires,
        bool $autoplay = false
    ): string {
        $libraryId = trim($libraryId);
        $videoId = trim($videoId);
        $token = self::embedToken($tokenAuthKey, $videoId, $expires);

        return sprintf(
            'https://iframe.mediadelivery.net/embed/%s/%s?token=%s&expires=%d&autoplay=%s',
            rawurlencode($libraryId),
            rawurlencode($videoId),
            $token,
            $expires,
            $autoplay ? 'true' : 'false'
        );
    }

    /**
     * CDN (pull zone) token for a thumbnail or any other static asset.
     *
     *   token = base64url( sha256_raw(cdnTokenKey + path + expires) )
     *
     * Note `true` as the third argument to hash(): this is the RAW digest,
     * base64-encoded — not the hex digest. And the base64 is URL-safe:
     * `+` -> `-`, `/` -> `_`, padding stripped.
     *
     * @param string $path leading-slash path, e.g. "/{guid}/thumbnail.jpg"
     */
    public static function cdnToken(string $cdnTokenKey, string $path, int $expires): string
    {
        $raw = hash('sha256', trim($cdnTokenKey) . $path . $expires, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Signed CDN URL for a video thumbnail.
     *
     * The path is signed exactly as it appears in the URL, so any change to
     * how it is built here must be mirrored in what is requested.
     */
    public static function thumbnailUrl(
        string $cdnTokenKey,
        string $cdnHostname,
        string $videoId,
        ?string $thumbnailFile,
        int $expires
    ): string {
        $file = trim((string) $thumbnailFile);
        if ($file === '') {
            $file = 'thumbnail.jpg';
        }

        $path = '/' . trim($videoId) . '/' . $file;
        $token = self::cdnToken($cdnTokenKey, $path, $expires);

        return sprintf(
            'https://%s%s?token=%s&expires=%d',
            trim($cdnHostname),
            $path,
            $token,
            $expires
        );
    }

    /**
     * TUS resumable-upload signature.
     *
     *   signature = sha256_hex(libraryId + apiKey + expire + videoId)
     *
     * Order matters and is not the same as the embed token's. This is what
     * lets the browser upload without ever seeing the API key.
     */
    public static function uploadSignature(
        string $libraryId,
        string $apiKey,
        int $expire,
        string $videoId
    ): string {
        return hash('sha256', trim($libraryId) . trim($apiKey) . $expire . trim($videoId));
    }

    /**
     * Map bunny.net's numeric status to ours.
     *
     * Bunny's encode states are 0=queued, 1=processing, 2=encoding,
     * 3=finished, 4=resolution-finished, 5=failed, 6=presigned-upload-started.
     *
     * Note that 3 ("finished") is deliberately NOT treated as ready: at that
     * point the first resolution exists but the playable set may not, and
     * showing the video produces a player that fails to start. Ready begins at
     * 4. Anything above 6 is a state newer than this mapping knows about, and
     * the safe reading of "unknown but past the failure codes" is ready —
     * carried over from the predecessor apps, where the stricter version left
     * videos stuck on "Processing" indefinitely.
     */
    public static function mapStatus(int $status): string
    {
        return match (true) {
            $status === 5, $status === 6 => VideoMeta::STATUS_FAILED,
            $status === 4, $status > 6   => VideoMeta::STATUS_READY,
            default                      => VideoMeta::STATUS_PROCESSING,
        };
    }
}
