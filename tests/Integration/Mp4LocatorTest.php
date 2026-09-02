<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Tests\Support\RecordingMp4Provider;
use Portal\Tests\Support\RecordingVideoProvider;
use Portal\Video\Mp4Locator;
use Portal\Video\Mp4Source;

/**
 * Where a video's MP4 comes from, and what it costs to ask.
 *
 * Two claims, and the second is the one that will be broken by accident:
 *
 *   1. A cached answer produces the same URL a live lookup would.
 *   2. A cached answer produces it WITHOUT contacting the provider.
 *
 * Only the first is visible in the return value, so a version that asks every
 * time and agrees would satisfy any test written against the URL alone — while
 * costing exactly the fifty outbound calls per listing this exists to prevent.
 * Hence the call counts.
 *
 * The third claim has no return value at all: a row nobody has asked about must
 * not be READ as though somebody had. Every install upgrading to this release
 * has has_mp4 = 0 on every row, and that zero is a column default rather than a
 * verdict. Reading it as one tells a site with MP4 Fallback plainly switched on
 * that none of its videos has a file.
 */
final class Mp4LocatorTest extends DatabaseTestCase
{
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['videos']);

        $this->videos = new VideoRepository($this->db(), new CategoryRepository($this->db()));
    }

    // ------------------------------------------------------- the cached path

    public function testAKnownVideoIsResolvedWithoutAskingTheProvider(): void
    {
        $id = $this->video(hasMp4: true, heights: '360,480,720', checked: true);
        $provider = new RecordingMp4Provider();

        $source = $this->locate($provider, $id);

        self::assertTrue($source->ok());
        self::assertSame(720, $source->height);
        self::assertSame(0, $provider->getVideoCalls, 'A cached answer must cost no API call.');
        self::assertSame(1, $provider->mp4SourceFromCalls);
    }

    /** The cap still applies to a cached list, not only to a fresh one. */
    public function testTheCapAppliesToCachedRenditions(): void
    {
        $id = $this->video(hasMp4: true, heights: '360,720,1080', checked: true);
        $provider = new RecordingMp4Provider();
        $provider->cap = 480;

        $source = $this->locate($provider, $id);

        self::assertSame(360, $source->height, 'The best rendition at or under the cap, not the largest.');
    }

    public function testACachedNoIsReportedAsTheLibrarySetting(): void
    {
        $id = $this->video(hasMp4: false, heights: '', checked: true);
        $provider = new RecordingMp4Provider();

        $source = $this->locate($provider, $id);

        self::assertFalse($source->ok());
        self::assertSame(Mp4Source::NO_FALLBACK, $source->reason);
        self::assertSame(0, $provider->getVideoCalls);
    }

    /**
     * The flag on with nothing encoded is a video still processing, and it is
     * a different fix from the setting being off.
     */
    public function testCachedFallbackWithNoRenditionsIsNotTheSettingBeingOff(): void
    {
        $id = $this->video(hasMp4: true, heights: '', checked: true);

        $source = $this->locate(new RecordingMp4Provider(), $id);

        self::assertSame(Mp4Source::NO_RENDITION, $source->reason);
    }

    // --------------------------------------------------------- the cold path

    /**
     * The claim the whole design rests on: an unasked row is not a "no".
     *
     * has_mp4 is 0 here and the provider says the video has a 1080. If the
     * locator trusted the column it would answer NO_FALLBACK — the answer every
     * video on every upgraded install would get.
     */
    public function testAnUnaskedVideoIsResolvedFromTheProviderNotFromTheColumn(): void
    {
        $id = $this->video(hasMp4: false, heights: '', checked: false);

        $provider = new RecordingMp4Provider();
        $provider->stage($this->providerIdOf($id), hasFallback: true, resolutions: [480, 720]);

        $source = $this->locate($provider, $id);

        self::assertTrue($source->ok(), 'The column default must not be read as an answer.');
        self::assertSame(720, $source->height);
        self::assertSame(1, $provider->getVideoCalls);
    }

    public function testTheProvidersAnswerIsKeptSoTheNextRequestIsFree(): void
    {
        $id = $this->video(hasMp4: false, heights: '', checked: false);

        $provider = new RecordingMp4Provider();
        $provider->stage($this->providerIdOf($id), hasFallback: true, resolutions: [360, 720]);

        $this->locate($provider, $id);

        $stored = $this->videos->find($id);
        self::assertNotNull($stored);
        self::assertTrue($stored->hasMp4);
        self::assertSame([360, 720], $stored->mp4Heights);
        self::assertTrue($stored->mp4IsKnown(), 'The timestamp is what licenses trusting the other two.');

        // And the second request spends nothing.
        $before = $provider->getVideoCalls;
        $this->locate($provider, $id);
        self::assertSame($before, $provider->getVideoCalls);
    }

    /**
     * A provider that cannot be reached must not leave a verdict behind.
     *
     * This is the cache's version of the sync bug: writing "no MP4" here would
     * make one bad afternoon at bunny.net permanent, because nothing would ever
     * ask again.
     */
    public function testAnUnreachableProviderRecordsNothing(): void
    {
        $id = $this->video(hasMp4: false, heights: '', checked: false);

        $provider = new RecordingMp4Provider();
        $provider->failWith = 'connection refused';

        $source = $this->locate($provider, $id);

        self::assertTrue($source->ok(), 'Falls back to signing the cap, as it did before it could ask.');

        $stored = $this->videos->find($id);
        self::assertNotNull($stored);
        self::assertFalse($stored->mp4IsKnown(), 'Could not ask is not an answer and must not be stored.');
    }

    /**
     * Reached the provider, and its reply said nothing about MP4s. Same
     * situation, same rule — and the one the nullable flag exists for.
     */
    public function testAReplyThatOmitsTheFieldRecordsNothing(): void
    {
        $id = $this->video(hasMp4: false, heights: '', checked: false);

        $provider = new RecordingMp4Provider();
        $provider->stage($this->providerIdOf($id), hasFallback: null);

        $this->locate($provider, $id);

        $stored = $this->videos->find($id);
        self::assertNotNull($stored);
        self::assertFalse($stored->mp4IsKnown());
    }

    /** A definitive 404 is a verdict, but not one about the library setting. */
    public function testAVideoTheProviderDoesNotHaveIsReportedAsSuch(): void
    {
        $id = $this->video(hasMp4: false, heights: '', checked: false);

        $source = $this->locate(new RecordingMp4Provider(), $id);

        self::assertSame(Mp4Source::NOT_AT_PROVIDER, $source->reason);
        self::assertFalse($this->videos->find($id)?->mp4IsKnown());
    }

    // ------------------------------------------------------ another provider

    /**
     * A provider that cannot explain itself still answers, and nothing about
     * its answer is cached — nothing here knows what it depends on.
     */
    public function testAProviderWithoutMp4SupportFallsBackToTheInterface(): void
    {
        $id = $this->video(hasMp4: false, heights: '', checked: false);
        $provider = new RecordingVideoProvider();

        $source = $this->locate($provider, $id);

        self::assertSame(RecordingVideoProvider::DOWNLOAD, $source->url);
        self::assertSame(1, $provider->downloadCalls);
        self::assertFalse($this->videos->find($id)?->mp4IsKnown());
    }

    // ---------------------------------------------------------------- fixture

    private function locate(RecordingVideoProvider $provider, int $videoId): \Portal\Video\Mp4Source
    {
        $video = $this->videos->find($videoId);
        self::assertNotNull($video);

        return (new Mp4Locator($provider, $this->videos))->locate($video, 600);
    }

    private function providerIdOf(int $videoId): string
    {
        return (string) $this->db()->value('SELECT provider_id FROM {videos} WHERE id = ?', [$videoId]);
    }

    private function video(bool $hasMp4, string $heights, bool $checked): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'    => 'bunny-' . $suffix,
            'slug'           => 'video-' . $suffix,
            'title'          => 'A sermon',
            'status'         => 'ready',
            'is_published'   => 1,
            'has_mp4'        => $hasMp4 ? 1 : 0,
            'mp4_heights'    => $heights,
            'mp4_checked_at' => $checked ? $now : null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }
}
