<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;

/**
 * Scheduled publishing, end dates, and premieres.
 *
 * The rules are evaluated twice — in SQL for listings and in PHP for a single
 * video — so the tests check both and, where it matters, check that they agree.
 * A schedule that a listing and a watch page disagree about is worse than no
 * schedule: something is listed and 404s, or is unlisted and plays.
 */
final class SchedulingTest extends DatabaseTestCase
{
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'categories', 'videos']);

        $this->videos = new VideoRepository($this->db(), new CategoryRepository($this->db()));
    }

    // ------------------------------------------------------------- publishing

    public function testAVideoWithNoDatesIsVisible(): void
    {
        $id = $this->video('Plain');

        self::assertSame(['Plain'], $this->listed());
        self::assertTrue($this->videos->find($id)?->isVisible());
    }

    public function testAFutureDateKeepsItOutOfListings(): void
    {
        $this->video('Later', publishedAt: '+2 days');

        self::assertSame([], $this->listed());
    }

    public function testAPastDateLetsItThrough(): void
    {
        $this->video('Already out', publishedAt: '-1 hour');

        self::assertSame(['Already out'], $this->listed());
    }

    /** The model must agree with the listing, or something 404s that is listed. */
    public function testTheModelAgreesWithTheListing(): void
    {
        $scheduled = $this->videos->find($this->video('Later', publishedAt: '+2 days'));
        $out = $this->videos->find($this->video('Already out', publishedAt: '-1 hour'));

        self::assertTrue($scheduled?->isScheduled());
        self::assertFalse($scheduled?->isVisible());

        self::assertFalse($out?->isScheduled());
        self::assertTrue($out?->isVisible());
    }

    /** An editor still sees what they scheduled, or they cannot check it. */
    public function testSomeoneWhoCanManageStillSeesScheduledVideos(): void
    {
        $this->video('Later', publishedAt: '+2 days');

        self::assertSame(['Later'], $this->listed(['includeUnpublished' => true]));
    }

    // ------------------------------------------------------------- end dates

    public function testAPastEndDateHidesIt(): void
    {
        $this->video('Expired', publishedAt: '-2 days', unpublishAt: '-1 day');

        self::assertSame([], $this->listed());
    }

    public function testAFutureEndDateLeavesItAlone(): void
    {
        $this->video('Still up', publishedAt: '-2 days', unpublishAt: '+1 day');

        self::assertSame(['Still up'], $this->listed());
    }

    public function testTheModelAgreesAboutExpiry(): void
    {
        $expired = $this->videos->find($this->video('Expired', unpublishAt: '-1 day'));
        $live = $this->videos->find($this->video('Still up', unpublishAt: '+1 day'));

        self::assertTrue($expired?->hasExpired());
        self::assertFalse($expired?->isVisible());

        self::assertFalse($live?->hasExpired());
        self::assertTrue($live?->isVisible());
    }

    /**
     * An expired video is gone for everybody. There is no premiere-style
     * exception at the far end — "this was up and is not any more" has no
     * useful public rendering.
     */
    public function testAnExpiredPremiereIsStillGone(): void
    {
        $this->video('Was a premiere', publishedAt: '-2 days', unpublishAt: '-1 day', premiere: true);

        self::assertSame([], $this->listed());
    }

    // -------------------------------------------------------------- premieres

    public function testAPremiereIsListedBeforeItsDate(): void
    {
        $this->video('Coming Sunday', publishedAt: '+2 days', premiere: true);

        self::assertSame(['Coming Sunday'], $this->listed());
    }

    /** And an ordinary scheduled video beside it still is not. */
    public function testAnOrdinaryScheduledVideoIsStillHidden(): void
    {
        $this->video('Coming Sunday', publishedAt: '+2 days', premiere: true);
        $this->video('Quietly later', publishedAt: '+2 days');

        self::assertSame(['Coming Sunday'], $this->listed());
    }

    public function testAPremiereIsMarkedAsOne(): void
    {
        $video = $this->videos->find($this->video('Coming', publishedAt: '+2 days', premiere: true));

        self::assertTrue($video?->isPremiering());
        // Still not visible in the ordinary sense: the watch page must refuse
        // to play it, and isVisible() is what that check reads.
        self::assertFalse($video?->isVisible());
    }

    /**
     * Once the date passes, a premiere is just a published video. Nothing has
     * to clear the flag, which is what makes this survivable without a job.
     */
    public function testAPremiereStopsPremieringOnceItsDatePasses(): void
    {
        $video = $this->videos->find($this->video('Out now', publishedAt: '-1 hour', premiere: true));

        self::assertFalse($video?->isPremiering());
        self::assertTrue($video?->isVisible());
    }

    public function testAPremiereFlagOnAVideoWithNoDateDoesNothing(): void
    {
        $video = $this->videos->find($this->video('No date', premiere: true));

        self::assertFalse($video?->isPremiering());
        self::assertTrue($video?->isVisible());
    }

    /** A draft is a draft: the premiere flag must not publish it. */
    public function testAPremiereOnAnUnpublishedVideoStaysHidden(): void
    {
        $id = $this->video('Draft premiere', publishedAt: '+2 days', premiere: true);
        $this->db()->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$id]);

        self::assertSame([], $this->listed());
        self::assertFalse($this->videos->find($id)?->isPremiering());
    }

    public function testAHiddenPremiereIsNotListed(): void
    {
        $id = $this->video('Hidden premiere', publishedAt: '+2 days', premiere: true);
        $this->db()->execute('UPDATE {videos} SET hidden = 1 WHERE id = ?', [$id]);

        self::assertSame([], $this->listed());
        self::assertFalse($this->videos->find($id)?->isPremiering());
    }

    /**
     * Feeds do not ask for premieres, and must not get them: an episode
     * announced before it can be downloaded is one every podcast client
     * reports as broken.
     */
    public function testAFeedStyleQueryExcludesPremieres(): void
    {
        $this->video('Coming Sunday', publishedAt: '+2 days', premiere: true);
        $this->video('Out now', publishedAt: '-1 hour');

        // Deliberately not through listed(), which adds includePremieres the
        // way the public library does. This is the bare filter set a feed
        // passes.
        $titles = array_map(
            static fn (Video $v): string => $v->title,
            $this->videos->query([], 1, 50)['items']
        );

        self::assertSame(['Out now'], $titles);
    }

    // -------------------------------------------------------------- writing

    public function testDatesRoundTripThroughUpdate(): void
    {
        $id = $this->video('Editable');

        $this->videos->update($id, [
            'published_at' => '2026-09-01T10:30',
            'unpublish_at' => '2026-09-30T23:00',
        ]);

        $video = $this->videos->find($id);

        // Stored canonically, whatever shape the form sent.
        self::assertSame('2026-09-01 10:30:00', $video?->publishedAt);
        self::assertSame('2026-09-30 23:00:00', $video?->unpublishAt);
    }

    public function testAnEmptyDateClearsIt(): void
    {
        $id = $this->video('Editable', publishedAt: '+2 days');

        $this->videos->update($id, ['published_at' => '']);

        self::assertNull($this->videos->find($id)?->publishedAt);
    }

    /**
     * Refused, not stored. A date the comparison cannot read either publishes
     * something early or hides it forever, and both look like a bug in the
     * schedule rather than a typo in a field.
     */
    public function testAnUnusableDateIsRefused(): void
    {
        $id = $this->video('Editable');

        $this->expectException(HttpException::class);
        $this->videos->update($id, ['published_at' => 'next Thorsday']);
    }

    /**
     * A window that never opens is always a mistake, and accepting it silently
     * produces a video that never appears with nothing to explain why.
     */
    public function testAnEndDateBeforeTheStartIsRefused(): void
    {
        $id = $this->video('Editable');

        $this->expectException(HttpException::class);
        $this->videos->update($id, [
            'published_at' => '2026-09-30 10:00:00',
            'unpublish_at' => '2026-09-01 10:00:00',
        ]);
    }

    /** And it is refused when only one half is being changed. */
    public function testAnEndDateBeforeAnAlreadyStoredStartIsRefused(): void
    {
        $id = $this->video('Editable');
        $this->videos->update($id, ['published_at' => '2026-09-30 10:00:00']);

        $this->expectException(HttpException::class);
        $this->videos->update($id, ['unpublish_at' => '2026-09-01 10:00:00']);
    }

    public function testTheSameInstantIsRefused(): void
    {
        $id = $this->video('Editable');

        $this->expectException(HttpException::class);
        $this->videos->update($id, [
            'published_at' => '2026-09-01 10:00:00',
            'unpublish_at' => '2026-09-01 10:00:00',
        ]);
    }

    // --------------------------------------------------------------- fixtures

    /**
     * @param  array<string, mixed> $filters
     * @return list<string>
     */
    private function listed(array $filters = []): array
    {
        // What the public library asks for.
        $filters += ['includePremieres' => true];

        return array_map(
            static fn (Video $v): string => $v->title,
            $this->videos->query($filters, 1, 50)['items']
        );
    }

    private function video(
        string $title,
        ?string $publishedAt = null,
        ?string $unpublishAt = null,
        bool $premiere = false,
    ): int {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'premiere'     => $premiere ? 1 : 0,
            'published_at' => $publishedAt === null ? null : date('Y-m-d H:i:s', strtotime($publishedAt)),
            'unpublish_at' => $unpublishAt === null ? null : date('Y-m-d H:i:s', strtotime($unpublishAt)),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
