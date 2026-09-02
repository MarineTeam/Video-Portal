<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Video\BunnyStreamProvider;
use Portal\Video\Mp4Source;

/**
 * Which rendition gets signed, decided without asking anyone.
 *
 * `mp4SourceFrom()` is the half of the resolver that has no network in it, so
 * it can be tested against the real provider rather than a double — which
 * matters, because the URL it builds is the one the CDN either accepts or
 * rejects, and a double would happily agree with a wrong one.
 */
final class Mp4RenditionChoiceTest extends TestCase
{
    private function provider(string $cap = ''): BunnyStreamProvider
    {
        return new BunnyStreamProvider([
            'library_id'      => '1234',
            'api_key'         => 'api-key',
            'token_auth_key'  => 'embed-key',
            'cdn_hostname'    => 'vz-test.b-cdn.net',
            'cdn_token_key'   => 'pull-zone-key',
            'download_height' => $cap,
        ]);
    }

    public function testTheLargestRenditionWithinTheCapIsChosen(): void
    {
        $source = $this->provider('720')->mp4SourceFrom('vid', true, [360, 480, 720, 1080]);

        self::assertTrue($source->ok());
        self::assertSame(720, $source->height);
        self::assertStringContainsString('/vid/play_720p.mp4', (string) $source->url);
        self::assertStringContainsString('token=', (string) $source->url);
    }

    /**
     * A cap no rendition reaches is the case that used to produce a 404 nobody
     * could diagnose: the old code signed the cap regardless.
     */
    public function testNothingWithinTheCapIsReportedRatherThanSignedAnyway(): void
    {
        $source = $this->provider('480')->mp4SourceFrom('vid', true, [720, 1080]);

        self::assertFalse($source->ok());
        self::assertSame(Mp4Source::NO_RENDITION, $source->reason);
    }

    public function testAnUnorderedCachedListStillPicksTheLargest(): void
    {
        // A list that has been through a database column and back carries no
        // ordering guarantee, and picking the first match would give 360.
        $source = $this->provider('720')->mp4SourceFrom('vid', true, [1080, 360, 720]);

        self::assertSame(720, $source->height);
    }

    public function testTheDefaultCapIsSevenTwenty(): void
    {
        self::assertSame(720, $this->provider()->mp4SourceFrom('vid', true, [480, 720, 1080])->height);
        self::assertSame(720, $this->provider('0')->mp4SourceFrom('vid', true, [720, 1080])->height);
    }

    public function testNoFallbackIsItsOwnReason(): void
    {
        self::assertSame(
            Mp4Source::NO_FALLBACK,
            $this->provider('720')->mp4SourceFrom('vid', false, [720])->reason
        );
    }

    /**
     * No pull zone means nothing can be signed, and that is answered before the
     * renditions are even considered — a site with no CDN configured has a
     * credentials problem, not an encoding one.
     */
    public function testWithoutAPullZoneNothingIsSigned(): void
    {
        $bare = new BunnyStreamProvider(['library_id' => '1234', 'api_key' => 'k']);

        self::assertSame(Mp4Source::NOT_CONFIGURED, $bare->mp4SourceFrom('vid', true, [720])->reason);
        self::assertSame(Mp4Source::NOT_CONFIGURED, $bare->signAtCap('vid')->reason);
    }

    /**
     * The could-not-ask fallback signs the same URL the resolved path would,
     * when the cap happens to be a real rendition.
     *
     * The two drifting apart is the failure that would matter: an outage would
     * start handing out URLs the CDN rejects, which looks like a signing bug
     * rather than an outage.
     */
    public function testTheFallbackSignsTheSameFileAsTheResolvedPath(): void
    {
        $provider = $this->provider('480');

        $resolved = $provider->mp4SourceFrom('vid', true, [360, 480]);
        $fallback = $provider->signAtCap('vid');

        // Compared on the path, not the whole URL: the token is derived from
        // an expiry taken at signing time, so two calls a second apart differ
        // there legitimately.
        self::assertSame($this->path((string) $resolved->url), $this->path((string) $fallback->url));
        self::assertSame('/vid/play_480p.mp4', $this->path((string) $resolved->url));
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }
}
