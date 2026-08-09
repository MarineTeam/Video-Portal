<?php

declare(strict_types=1);

namespace Portal\Video;

use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Support\Http;
use Portal\Http\HttpException;
use Throwable;

/**
 * bunny.net Stream.
 *
 * Credential note, because this trips up almost everyone: bunny.net has TWO
 * separate token-authentication keys on two separate screens, and they are not
 * interchangeable.
 *
 *   token_auth_key  — Stream library -> API -> "Token Authentication Key".
 *                     Signs embed/playback URLs.
 *   cdn_token_key   — Pull Zone -> Security -> "URL Token Authentication Key".
 *                     Signs thumbnail/CDN URLs.
 *
 * Playback working while thumbnails 403 is the signature of having pasted the
 * first key into both fields.
 */
final class BunnyStreamProvider implements VideoProvider, SupportsCaptions
{
    private const API_BASE = 'https://video.bunnycdn.com/library';
    private const TUS_ENDPOINT = 'https://video.bunnycdn.com/tusupload';

    /**
     * Short-lived per-request cache of the full video list.
     *
     * A single page render asks for the library, the collection filter, and
     * the continue-watching strip; without this that's three identical
     * round-trips to bunny.net on every page load.
     *
     * @var array<string, VideoPage>
     */
    private array $pageCache = [];

    /** @param array<string, string> $credentials */
    public function __construct(private readonly array $credentials)
    {
    }

    public static function slug(): string
    {
        return 'bunny';
    }

    public static function label(): string
    {
        return 'bunny.net Stream';
    }

    public static function description(): string
    {
        return 'Video hosting and delivery with signed playback URLs and direct browser uploads.';
    }

    public static function requiredExtensions(): array
    {
        return ['curl', 'openssl'];
    }

    public static function fields(): array
    {
        return [
            SettingField::text(
                'library_id',
                'Stream Library ID',
                'The numeric ID shown at the top of your Stream library page.'
            ),
            SettingField::secret(
                'api_key',
                'Stream API Key',
                'Stream library → API. Used server-side for uploads and video management; never sent to the browser.'
            ),
            SettingField::secret(
                'token_auth_key',
                'Token Authentication Key',
                'Stream library → API → Token Authentication Key. Signs playback URLs.'
            ),
            SettingField::text(
                'cdn_hostname',
                'Pull Zone Hostname',
                'For example vz-a1b2c3d4-e5f.b-cdn.net. Required for thumbnails — without it the library falls back to a text list.',
                required: false
            ),
            SettingField::secret(
                'cdn_token_key',
                'Pull Zone URL Token Key',
                'Pull Zone → Security → URL Token Authentication Key. A DIFFERENT key from the one above. Leave blank to reuse the Token Authentication Key, though that only works if both are genuinely the same.',
                required: false
            ),
            SettingField::text(
                'download_height',
                'Podcast download resolution',
                'The MP4 height podcast feeds link to — 720 by default. Requires MP4 fallback to be enabled on the library, and the resolution must be one it actually encodes: asking for 1080 from a library that stops at 720 makes every episode a 404.',
                required: false
            ),
        ];
    }

    // ------------------------------------------------------------ credentials

    private function libraryId(): string
    {
        return trim($this->credentials['library_id'] ?? '');
    }

    private function apiKey(): string
    {
        return trim($this->credentials['api_key'] ?? '');
    }

    private function tokenAuthKey(): string
    {
        return trim($this->credentials['token_auth_key'] ?? '');
    }

    private function cdnHostname(): string
    {
        // Tolerate someone pasting a full URL rather than a bare hostname.
        $host = trim($this->credentials['cdn_hostname'] ?? '');
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        return rtrim($host, '/');
    }

    private function cdnTokenKey(): string
    {
        $key = trim($this->credentials['cdn_token_key'] ?? '');
        // Falling back to the embed key is wrong more often than it is right,
        // but it is what the predecessor apps did and some setups genuinely
        // share a key. The field help explains the risk.
        return $key !== '' ? $key : $this->tokenAuthKey();
    }

    public function thumbnailsConfigured(): bool
    {
        return $this->cdnHostname() !== '' && $this->cdnTokenKey() !== '';
    }

    /**
     * Enough credentials to create a video and sign an upload ticket.
     *
     * A local check, never a network call: the admin video list asks this on
     * every visit, and reaching bunny.net to answer it would make the page hang
     * whenever bunny.net is slow.
     */
    public function uploadsConfigured(): bool
    {
        return $this->libraryId() !== '' && $this->apiKey() !== '';
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return ['AccessKey' => $this->apiKey(), 'Accept' => 'application/json'];
    }

    private function apiUrl(string $path = ''): string
    {
        return self::API_BASE . '/' . rawurlencode($this->libraryId()) . $path;
    }

    // --------------------------------------------------------------- reading

    public function listVideos(int $page = 1, int $perPage = 100): VideoPage
    {
        $cacheKey = "{$page}:{$perPage}";
        if (isset($this->pageCache[$cacheKey])) {
            return $this->pageCache[$cacheKey];
        }

        $url = $this->apiUrl(sprintf('/videos?page=%d&itemsPerPage=%d&orderBy=date', $page, $perPage));
        $response = Http::get($url, $this->authHeaders());

        if ($response->failed()) {
            throw HttpException::upstream(
                'Could not load videos from bunny.net: ' . $response->errorMessage()
            );
        }

        $json = $response->json();
        $items = [];
        foreach ($json['items'] ?? [] as $item) {
            if (is_array($item)) {
                $items[] = $this->mapVideo($item);
            }
        }

        return $this->pageCache[$cacheKey] = new VideoPage(
            $items,
            (int) ($json['currentPage'] ?? $page),
            $perPage,
            (int) ($json['totalItems'] ?? count($items))
        );
    }

    /**
     * Every video in the library, following pagination.
     *
     * Capped at 5 pages (500 videos). An unbounded loop here is how a large
     * library turns one page render into a 30-second request that a shared
     * host kills halfway through.
     *
     * @return list<VideoMeta>
     */
    public function listAllVideos(int $maxPages = 5, int $perPage = 100): array
    {
        $first = $this->listVideos(1, $perPage);
        $all = $first->items;

        $pages = min($first->totalPages(), $maxPages);
        for ($page = 2; $page <= $pages; $page++) {
            foreach ($this->listVideos($page, $perPage)->items as $item) {
                $all[] = $item;
            }
        }

        return $all;
    }

    public function getVideo(string $providerId): ?VideoMeta
    {
        $response = Http::get(
            $this->apiUrl('/videos/' . rawurlencode($providerId)),
            $this->authHeaders()
        );

        if ($response->status === 404) {
            return null;
        }
        if ($response->failed()) {
            throw HttpException::upstream(
                'Could not load that video from bunny.net: ' . $response->errorMessage()
            );
        }

        return $this->mapVideo($response->json());
    }

    /** @param array<string, mixed> $item */
    private function mapVideo(array $item): VideoMeta
    {
        $created = null;
        if (!empty($item['dateUploaded']) && is_string($item['dateUploaded'])) {
            try {
                $created = new \DateTimeImmutable($item['dateUploaded'], new \DateTimeZone('UTC'));
            } catch (Throwable) {
                $created = null;
            }
        }

        $length = (int) ($item['length'] ?? 0);

        return new VideoMeta(
            id:             (string) ($item['guid'] ?? ''),
            title:          (string) ($item['title'] ?? 'Untitled'),
            status:         BunnySigner::mapStatus((int) ($item['status'] ?? 0)),
            encodeProgress: (int) ($item['encodeProgress'] ?? 0),
            duration:       $length > 0 ? $length : null,
            thumbnailFile:  isset($item['thumbnailFileName']) ? (string) $item['thumbnailFileName'] : null,
            collectionId:   !empty($item['collectionId']) ? (string) $item['collectionId'] : null,
            width:          isset($item['width']) ? (int) $item['width'] : null,
            height:         isset($item['height']) ? (int) $item['height'] : null,
            createdAt:      $created,
            views:          (int) ($item['views'] ?? 0),
        );
    }

    // ------------------------------------------------------------------ URLs

    public function embedUrl(string $providerId, int $ttlSeconds = 10800): string
    {
        return BunnySigner::embedUrl(
            $this->tokenAuthKey(),
            $this->libraryId(),
            $providerId,
            time() + max(60, $ttlSeconds)
        );
    }

    public function thumbnailUrl(string $providerId, ?string $thumbnailFile, int $ttlSeconds = 21600): ?string
    {
        if (!$this->thumbnailsConfigured()) {
            // Callers must handle null: the library grid degrades to a text
            // list rather than rendering a wall of broken images.
            return null;
        }

        return BunnySigner::thumbnailUrl(
            $this->cdnTokenKey(),
            $this->cdnHostname(),
            $providerId,
            $thumbnailFile,
            time() + max(60, $ttlSeconds)
        );
    }

    /**
     * A signed link to the MP4 itself.
     *
     * bunny.net serves direct-play files from the same pull zone as thumbnails,
     * at /{videoId}/play_{height}p.mp4, and they are signed with the same CDN
     * token — so this needs no new credential and no new configuration beyond
     * the resolution to offer.
     *
     * Two things it depends on, both stated on the podcast settings screen
     * because neither can be detected from here without a request per video:
     * MP4 fallback must be enabled on the bunny.net library, and the chosen
     * resolution must be one the library actually encodes. Ask for 1080p from a
     * library that stops at 720 and every enclosure is a 404 — which a podcast
     * client reports as "episode unavailable" and nothing else notices.
     */
    public function downloadUrl(string $providerId, int $ttlSeconds = 3600): ?string
    {
        if (!$this->thumbnailsConfigured()) {
            return null;
        }

        $height = (int) trim($this->credentials['download_height'] ?? '');
        if ($height <= 0) {
            $height = 720;
        }

        $path = '/' . trim($providerId) . '/play_' . $height . 'p.mp4';
        $expires = time() + max(60, $ttlSeconds);

        return sprintf(
            'https://%s%s?token=%s&expires=%d',
            trim($this->cdnHostname()),
            $path,
            BunnySigner::cdnToken($this->cdnTokenKey(), $path, $expires),
            $expires
        );
    }

    // ---------------------------------------------------------------- writing

    public function createUploadTicket(string $title, ?string $collectionId = null): UploadTicket
    {
        $payload = ['title' => $title];
        if ($collectionId !== null && $collectionId !== '') {
            $payload['collectionId'] = $collectionId;
        }

        $response = Http::postJson(
            $this->apiUrl('/videos'),
            $payload,
            ['AccessKey' => $this->apiKey()]
        );

        if ($response->failed()) {
            throw HttpException::upstream(
                'bunny.net would not create the video: ' . $response->errorMessage()
            );
        }

        $videoId = (string) ($response->json()['guid'] ?? '');
        if ($videoId === '') {
            throw HttpException::upstream('bunny.net created the video but returned no id.');
        }

        // Six hours: long enough for a big file on a slow connection to finish,
        // short enough that a leaked ticket stops working the same day.
        $expire = time() + 21600;

        return new UploadTicket(
            providerId: $videoId,
            endpoint:   self::TUS_ENDPOINT,
            headers:    [
                'AuthorizationSignature' => BunnySigner::uploadSignature(
                    $this->libraryId(),
                    $this->apiKey(),
                    $expire,
                    $videoId
                ),
                'AuthorizationExpire'    => (string) $expire,
                'VideoId'                => $videoId,
                'LibraryId'              => $this->libraryId(),
            ],
            expiresAt:  $expire,
            protocol:   'tus',
            metadata:   ['filetype' => '', 'title' => $title],
        );
    }

    public function deleteVideo(string $providerId): void
    {
        $response = Http::request(
            'DELETE',
            $this->apiUrl('/videos/' . rawurlencode($providerId)),
            null,
            $this->authHeaders()
        );

        // 404 means it is already gone, which is the state the caller wanted.
        if ($response->failed() && $response->status !== 404) {
            throw HttpException::upstream(
                'Could not delete that video at bunny.net: ' . $response->errorMessage()
            );
        }

        $this->pageCache = [];
    }

    /** @param array<string, mixed> $fields */
    public function updateVideo(string $providerId, array $fields): void
    {
        $response = Http::postJson(
            $this->apiUrl('/videos/' . rawurlencode($providerId)),
            $fields,
            ['AccessKey' => $this->apiKey()]
        );

        if ($response->failed()) {
            throw HttpException::upstream(
                'Could not update that video at bunny.net: ' . $response->errorMessage()
            );
        }

        $this->pageCache = [];
    }

    // -------------------------------------------------------------- captions

    /**
     * The caption tracks bunny.net holds for one video.
     *
     * Read from the video record rather than from a captions endpoint, because
     * there is no endpoint that lists them — bunny.net returns the tracks as
     * part of the video, and asking for the video is the only way to find out
     * what is really there.
     *
     * An unreachable provider returns an empty list rather than throwing, and
     * waits a short time rather than the usual fifteen seconds. This renders a
     * page: an edit screen that 502s because bunny.net was slow is worse than
     * one that says there are no captions, and either is worse than one that
     * sits there. The upload form beneath it works regardless.
     *
     * @return list<array{language: string, label: string}>
     */
    public function listCaptions(string $providerId): array
    {
        // Nothing to ask, so nothing is asked. A fresh install has no
        // credentials and should not spend a round trip finding that out.
        if (!$this->uploadsConfigured()) {
            return [];
        }

        try {
            $response = Http::get(
                $this->apiUrl('/videos/' . rawurlencode($providerId)),
                $this->authHeaders(),
                ['timeout' => 6]
            );
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $captions = [];

        foreach ($response->json()['captions'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $language = (string) ($item['srclang'] ?? '');

            if ($language === '') {
                continue;
            }

            $captions[] = [
                'language' => $language,
                'label'    => (string) ($item['label'] ?? $language),
            ];
        }

        return $captions;
    }

    /**
     * Send a caption track to bunny.net.
     *
     * The file goes base64-encoded inside a JSON body, which is bunny.net's
     * API and not a choice made here. It is the one place in this class where
     * a whole file passes through the app server — acceptable only because
     * CaptionFile caps the input at two megabytes, and worth contrasting with
     * video upload, which goes browser-to-bunny precisely because it cannot.
     *
     * Uploading over an existing language replaces it. bunny.net treats the
     * language tag as the key, which is why CaptionFile lowercases it: "EN"
     * and "en" would otherwise be two tracks for one language and the player
     * would offer the viewer both.
     */
    public function uploadCaption(string $providerId, string $language, string $label, string $vtt): void
    {
        $response = Http::postJson(
            $this->apiUrl(
                '/videos/' . rawurlencode($providerId) . '/captions/' . rawurlencode($language)
            ),
            [
                'srclang'      => $language,
                'label'        => $label,
                'captionsFile' => base64_encode($vtt),
            ],
            ['AccessKey' => $this->apiKey()]
        );

        if ($response->failed()) {
            throw HttpException::upstream(
                'bunny.net would not store those captions: ' . $response->errorMessage()
            );
        }
    }

    public function deleteCaption(string $providerId, string $language): void
    {
        $response = Http::request(
            'DELETE',
            $this->apiUrl(
                '/videos/' . rawurlencode($providerId) . '/captions/' . rawurlencode($language)
            ),
            null,
            $this->authHeaders()
        );

        // 404 means the track is already gone, which is the state the caller
        // asked for.
        if ($response->failed() && $response->status !== 404) {
            throw HttpException::upstream(
                'Could not remove those captions at bunny.net: ' . $response->errorMessage()
            );
        }
    }

    // ----------------------------------------------------------- collections

    public function listCollections(): array
    {
        $response = Http::get(
            $this->apiUrl('/collections?page=1&itemsPerPage=100'),
            $this->authHeaders()
        );

        if ($response->failed()) {
            throw HttpException::upstream(
                'Could not load collections from bunny.net: ' . $response->errorMessage()
            );
        }

        $collections = [];
        foreach ($response->json()['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $collections[] = [
                'id'         => (string) ($item['guid'] ?? ''),
                'name'       => (string) ($item['name'] ?? 'Untitled'),
                'videoCount' => (int) ($item['videoCount'] ?? 0),
            ];
        }

        return $collections;
    }

    // ------------------------------------------------------------- analytics

    public function statistics(int $days = 30): array
    {
        $from = (new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC')))->format('Y-m-d');
        $to = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        $response = Http::get(
            $this->apiUrl("/statistics?dateFrom={$from}&dateTo={$to}"),
            $this->authHeaders()
        );

        if ($response->failed()) {
            // Analytics is a nice-to-have. Returning zeroes keeps the rest of
            // the dashboard rendering rather than 502-ing the whole page.
            return ['views' => 0, 'watchTime' => 0, 'chart' => []];
        }

        $json = $response->json();

        // bunny.net has returned viewsChart as both a date-keyed map and a
        // list of {timestamp, value} objects. Handle both.
        $chart = [];
        $raw = $json['viewsChart'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_array($value)) {
                    $label = (string) ($value['timestamp'] ?? $key);
                    $chart[$label] = (int) ($value['value'] ?? 0);
                } else {
                    $chart[(string) $key] = (int) $value;
                }
            }
        }

        return [
            'views'     => (int) ($json['viewsCount'] ?? 0),
            'watchTime' => (int) ($json['totalWatchTime'] ?? 0),
            'chart'     => $chart,
        ];
    }

    // ------------------------------------------------------------------ test

    public function test(): TestResult
    {
        if ($this->libraryId() === '' || $this->apiKey() === '') {
            return TestResult::fail('Library ID and API key are both required.');
        }

        if (!function_exists('curl_init')) {
            return TestResult::unavailable(
                'The curl PHP extension is not enabled, so this server cannot reach bunny.net.'
            );
        }

        try {
            $response = Http::get($this->apiUrl('/videos?page=1&itemsPerPage=1'), $this->authHeaders());
        } catch (Throwable $e) {
            return TestResult::fail('Could not reach bunny.net.', $e->getMessage());
        }

        if ($response->transportFailed()) {
            return TestResult::fail(
                'Could not reach video.bunnycdn.com. Your host may be blocking outbound HTTPS.',
                $response->transportError
            );
        }

        if ($response->status === 401) {
            return TestResult::fail(
                'bunny.net rejected the API key. Check for a stray space or newline when pasting it.'
            );
        }

        if ($response->status === 404) {
            return TestResult::fail('No Stream library with that ID. Check the Library ID.');
        }

        if ($response->failed()) {
            return TestResult::fail('bunny.net returned an error.', $response->errorMessage());
        }

        $total = (int) ($response->json()['totalItems'] ?? 0);

        // Warn rather than fail: thumbnails are optional, and blocking the
        // install over them would be worse than a text-list library.
        if (!$this->thumbnailsConfigured()) {
            return TestResult::pass(
                sprintf('Connected. %d video(s) found.', $total),
                'No pull zone hostname is set, so thumbnails will not load and the library will show a text list.'
            );
        }

        if ($this->tokenAuthKey() !== '' && $this->cdnTokenKey() === $this->tokenAuthKey()
            && trim($this->credentials['cdn_token_key'] ?? '') === '') {
            return TestResult::pass(
                sprintf('Connected. %d video(s) found.', $total),
                'No separate pull zone token key was given, so the playback key is being reused. '
                . 'If thumbnails come back 403, that is why — they are usually different keys.'
            );
        }

        return TestResult::pass(sprintf('Connected. %d video(s) found.', $total));
    }
}
