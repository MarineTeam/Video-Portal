<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Portal\Video\BunnySigner;
use Portal\Video\VideoMeta;

/**
 * The signing formulas are the most fragile code in the application: a wrong
 * one produces a 401/403 with no diagnostic, and the error is invisible on
 * review. These tests pin each formula to an independently-computed expected
 * value, so a "harmless tidy-up" of the concatenation order fails loudly here
 * rather than silently on a live host.
 */
final class BunnySignerTest extends TestCase
{
    private const TOKEN_KEY = 'embed-token-key-abc123';
    private const CDN_KEY   = 'cdn-pullzone-key-xyz789';
    private const LIBRARY   = '12345';
    private const VIDEO     = 'a1b2c3d4-0000-1111-2222-333344445555';
    private const EXPIRES   = 1800000000;

    public function testEmbedTokenMatchesTheDocumentedFormula(): void
    {
        // token = sha256_hex(key + videoId + expires)
        $expected = hash('sha256', self::TOKEN_KEY . self::VIDEO . self::EXPIRES);

        self::assertSame(
            $expected,
            BunnySigner::embedToken(self::TOKEN_KEY, self::VIDEO, self::EXPIRES)
        );
    }

    public function testEmbedTokenIsHexNotBase64(): void
    {
        $token = BunnySigner::embedToken(self::TOKEN_KEY, self::VIDEO, self::EXPIRES);

        self::assertSame(64, strlen($token), 'A hex SHA-256 is 64 characters.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    /**
     * The single most consequential behaviour in this class. A pasted key
     * routinely carries a trailing newline; if it reached the hash the
     * signature would be wrong and bunny.net would answer 401 with no clue.
     */
    public function testCredentialsAreTrimmedBeforeHashing(): void
    {
        $clean = BunnySigner::embedToken(self::TOKEN_KEY, self::VIDEO, self::EXPIRES);
        $dirty = BunnySigner::embedToken(
            "  " . self::TOKEN_KEY . "\n",
            "\t" . self::VIDEO . "  ",
            self::EXPIRES
        );

        self::assertSame($clean, $dirty);
    }

    public function testCdnTokenIsBase64UrlOfTheRawDigest(): void
    {
        $path = '/' . self::VIDEO . '/thumbnail.jpg';

        // base64url( raw sha256 ), padding stripped — NOT the hex digest.
        $expected = rtrim(strtr(
            base64_encode(hash('sha256', self::CDN_KEY . $path . self::EXPIRES, true)),
            '+/',
            '-_'
        ), '=');

        self::assertSame($expected, BunnySigner::cdnToken(self::CDN_KEY, $path, self::EXPIRES));
    }

    public function testCdnTokenIsUrlSafeAndUnpadded(): void
    {
        // Sweep enough inputs that a digest containing + and / is certain.
        for ($i = 0; $i < 200; $i++) {
            $token = BunnySigner::cdnToken(self::CDN_KEY, "/video{$i}/thumbnail.jpg", self::EXPIRES);

            self::assertStringNotContainsString('+', $token);
            self::assertStringNotContainsString('/', $token);
            self::assertStringNotContainsString('=', $token);
        }
    }

    /**
     * The embed key and the pull-zone key are different keys from different
     * bunny.net screens. Confusing them is the most common misconfiguration
     * and shows up as thumbnails 403ing while playback works fine.
     */
    public function testEmbedAndCdnSchemesAreNotInterchangeable(): void
    {
        $path = '/' . self::VIDEO . '/thumbnail.jpg';

        $embed = BunnySigner::embedToken(self::CDN_KEY, self::VIDEO, self::EXPIRES);
        $cdn   = BunnySigner::cdnToken(self::CDN_KEY, $path, self::EXPIRES);

        self::assertNotSame($embed, $cdn);
    }

    public function testThumbnailUrlSignsExactlyThePathItRequests(): void
    {
        $url = BunnySigner::thumbnailUrl(
            self::CDN_KEY,
            'vz-abc-123.b-cdn.net',
            self::VIDEO,
            'thumbnail_a1.jpg',
            self::EXPIRES
        );

        $parts = parse_url($url);
        self::assertIsArray($parts);
        parse_str($parts['query'] ?? '', $query);

        // Re-sign the path as it actually appears in the URL. If the builder
        // ever signs one thing and requests another, this fails.
        self::assertSame(
            BunnySigner::cdnToken(self::CDN_KEY, $parts['path'] ?? '', self::EXPIRES),
            $query['token'] ?? null
        );
        self::assertSame('/' . self::VIDEO . '/thumbnail_a1.jpg', $parts['path'] ?? '');
    }

    public function testThumbnailUrlFallsBackToTheDefaultFilename(): void
    {
        foreach ([null, '', '   '] as $empty) {
            $url = BunnySigner::thumbnailUrl(
                self::CDN_KEY,
                'vz-abc-123.b-cdn.net',
                self::VIDEO,
                $empty,
                self::EXPIRES
            );
            self::assertStringContainsString('/thumbnail.jpg?', $url);
        }
    }

    public function testUploadSignatureUsesLibraryApiKeyExpireVideoInThatOrder(): void
    {
        $apiKey = 'stream-api-key-secret';
        $expire = self::EXPIRES;

        $expected = hash('sha256', self::LIBRARY . $apiKey . $expire . self::VIDEO);

        self::assertSame(
            $expected,
            BunnySigner::uploadSignature(self::LIBRARY, $apiKey, $expire, self::VIDEO)
        );
    }

    /**
     * Guards against someone "simplifying" the concatenation. Every reordering
     * must produce a different signature.
     */
    public function testUploadSignatureIsOrderSensitive(): void
    {
        $apiKey = 'stream-api-key-secret';

        $correct = BunnySigner::uploadSignature(self::LIBRARY, $apiKey, self::EXPIRES, self::VIDEO);
        $swapped = hash('sha256', $apiKey . self::LIBRARY . self::EXPIRES . self::VIDEO);

        self::assertNotSame($swapped, $correct);
    }

    public function testEmbedUrlDisablesAutoplayByDefault(): void
    {
        $url = BunnySigner::embedUrl(self::TOKEN_KEY, self::LIBRARY, self::VIDEO, self::EXPIRES);

        self::assertStringContainsString('autoplay=false', $url);
        self::assertStringNotContainsString('preload=true', $url);
    }

    public function testEmbedUrlCarriesTheMatchingTokenAndExpiry(): void
    {
        $url = BunnySigner::embedUrl(self::TOKEN_KEY, self::LIBRARY, self::VIDEO, self::EXPIRES);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame(
            BunnySigner::embedToken(self::TOKEN_KEY, self::VIDEO, self::EXPIRES),
            $query['token'] ?? null
        );
        self::assertSame((string) self::EXPIRES, $query['expires'] ?? null);
    }

    #[DataProvider('statusProvider')]
    public function testStatusMapping(int $bunnyStatus, string $expected): void
    {
        self::assertSame($expected, BunnySigner::mapStatus($bunnyStatus));
    }

    /** @return list<array{int, string}> */
    public static function statusProvider(): array
    {
        return [
            [0, VideoMeta::STATUS_PROCESSING],
            [1, VideoMeta::STATUS_PROCESSING],
            [2, VideoMeta::STATUS_PROCESSING],
            // 3 is "finished" but not yet playable — deliberately not ready.
            [3, VideoMeta::STATUS_PROCESSING],
            [4, VideoMeta::STATUS_READY],
            [5, VideoMeta::STATUS_FAILED],
            [6, VideoMeta::STATUS_FAILED],
            // Unknown future states past the failure codes read as ready.
            [7, VideoMeta::STATUS_READY],
            [99, VideoMeta::STATUS_READY],
        ];
    }
}
