<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\SeriesRepository;
use Portal\Content\VideoRepository;

/**
 * Series, and the running order inside them.
 *
 * The ordering is the part with teeth. Everything else here is CRUD; the
 * position arithmetic is where an off-by-one silently reshuffles somebody's
 * teaching series and nobody notices until a viewer says episode 4 came before
 * episode 3.
 */
final class SeriesTest extends DatabaseTestCase
{
    private SeriesRepository $series;
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'videos', 'series', 'categories', 'slug_aliases']);

        $categories = new CategoryRepository($this->db());
        $this->series = new SeriesRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $categories);
    }

    // ------------------------------------------------------------------ CRUD

    public function testCreatingASeriesDerivesASlug(): void
    {
        $series = $this->series->create(['title' => 'Advent 2026']);

        self::assertSame('advent-2026', $series->slug);
        self::assertTrue($series->isPublished);
    }

    public function testASeriesNeedsATitle(): void
    {
        $this->expectExceptionMessage('A series needs a title.');
        $this->series->create(['title' => '   ']);
    }

    public function testSlugsAreMadeUniqueRatherThanRejected(): void
    {
        $first = $this->series->create(['title' => 'Romans']);
        $second = $this->series->create(['title' => 'Romans']);

        self::assertSame('romans', $first->slug);
        self::assertSame('romans-2', $second->slug);
    }

    /**
     * A series address may have been printed somewhere. Fixing a typo in the
     * title must not break it.
     */
    public function testRenamingKeepsTheOldAddressWorking(): void
    {
        $series = $this->series->create(['title' => 'Advint']);
        $this->series->update($series->id, ['slug' => 'advent']);

        self::assertSame('advent', $this->series->find($series->id)?->slug);
        self::assertSame($series->id, $this->series->findByAlias('advint')?->id);
    }

    /**
     * Deleting the grouping must not delete the content. The foreign key nulls
     * series_id; this pins that it actually behaves that way.
     */
    public function testDeletingASeriesKeepsItsVideos(): void
    {
        $series = $this->series->create(['title' => 'Temporary']);
        $video = $this->video('Kept');
        $this->series->setVideos($series->id, [$video]);

        $this->series->delete($series->id);

        self::assertNull($this->series->find($series->id));
        self::assertNotNull($this->videos->find($video), 'The video should survive its series.');
        self::assertNull($this->videos->find($video)?->seriesId);
    }

    public function testTheVideoCountComesBackWithTheList(): void
    {
        $series = $this->series->create(['title' => 'Counted']);
        $this->series->setVideos($series->id, [$this->video('One'), $this->video('Two')]);

        $all = $this->series->all(true);

        self::assertCount(1, $all);
        self::assertSame(2, $all[0]->videoCount);
    }

    /** An empty series is the one an admin has just made and is looking for. */
    public function testAnEmptySeriesStillAppearsInTheList(): void
    {
        $this->series->create(['title' => 'Brand new']);

        self::assertCount(1, $this->series->all(true));
    }

    // -------------------------------------------------------------- ordering

    public function testSetVideosStoresThemInTheGivenOrder(): void
    {
        $series = $this->series->create(['title' => 'Ordered']);
        $a = $this->video('A');
        $b = $this->video('B');
        $c = $this->video('C');

        $this->series->setVideos($series->id, [$c, $a, $b]);

        self::assertSame(['C', 'A', 'B'], $this->titles($series->id));
    }

    /**
     * A video removed from the form must actually leave. Updating only the ones
     * present would leave a stale row still pointing at the series.
     */
    public function testSetVideosRemovesOnesNoLongerListed(): void
    {
        $series = $this->series->create(['title' => 'Shrinking']);
        $a = $this->video('A');
        $b = $this->video('B');

        $this->series->setVideos($series->id, [$a, $b]);
        $this->series->setVideos($series->id, [$a]);

        self::assertSame(['A'], $this->titles($series->id));
        self::assertNull($this->videos->find($b)?->seriesId);
    }

    public function testMovingAVideoUpSwapsItWithItsNeighbour(): void
    {
        $series = $this->series->create(['title' => 'Movable']);
        $a = $this->video('A');
        $b = $this->video('B');
        $c = $this->video('C');
        $this->series->setVideos($series->id, [$a, $b, $c]);

        $this->series->move($b, -1);

        self::assertSame(['B', 'A', 'C'], $this->titles($series->id));
    }

    public function testMovingAVideoDownSwapsItTheOtherWay(): void
    {
        $series = $this->series->create(['title' => 'Movable']);
        $a = $this->video('A');
        $b = $this->video('B');
        $c = $this->video('C');
        $this->series->setVideos($series->id, [$a, $b, $c]);

        $this->series->move($b, 1);

        self::assertSame(['A', 'C', 'B'], $this->titles($series->id));
    }

    /** At the end, a move is a no-op rather than an error or a wrap-around. */
    public function testMovingPastTheEndChangesNothing(): void
    {
        $series = $this->series->create(['title' => 'Bounded']);
        $a = $this->video('A');
        $b = $this->video('B');
        $this->series->setVideos($series->id, [$a, $b]);

        $this->series->move($a, -1);
        $this->series->move($b, 1);

        self::assertSame(['A', 'B'], $this->titles($series->id));
    }

    public function testMovingAVideoInNoSeriesIsHarmless(): void
    {
        $orphan = $this->video('Orphan');

        $this->series->move($orphan, -1);

        self::assertNull($this->videos->find($orphan)?->seriesId);
    }

    /**
     * Ordering is per-series: a move must only ever consider videos in the same
     * series as the one being moved.
     *
     * Constructed so the wrong answer is UNAMBIGUOUS rather than a coin flip.
     * The obvious version of this test — two series of two, move one up — is
     * vacuous: both series number their videos 0 and 1, so an unfiltered query
     * hits a tie and whichever row the database happens to return first decides
     * whether the test passes. It passed against a deliberately broken
     * implementation for exactly that reason.
     *
     * Here the short series is moved DOWN and only the OTHER series has a video
     * at the next position. Filtered, there is no neighbour and nothing moves.
     * Unfiltered, the only candidate belongs to the other series, so the two
     * get swapped across the boundary and both assertions fail.
     */
    public function testAMoveNeverReachesIntoAnotherSeries(): void
    {
        $short = $this->series->create(['title' => 'Short']);
        $long = $this->series->create(['title' => 'Long']);

        $a = $this->video('A');
        $b = $this->video('B');
        $this->series->setVideos($short->id, [$a, $b]);

        // Positions 0, 1, 2 — so position 2 exists ONLY in this series.
        $this->series->setVideos($long->id, [$this->video('X'), $this->video('Y'), $this->video('Z')]);

        $before = $this->positions();

        // B is last in its own series, so this must do nothing at all.
        $this->series->move($b, 1);

        // Compared as raw (series_id, series_position) pairs rather than as the
        // resulting order. A cross-series swap can leave both listings reading
        // correctly while the stored positions are quietly wrong — which is
        // exactly what a title-only assertion missed here once already.
        self::assertSame($before, $this->positions(), 'A move at the end of a series changed something.');
    }

    // --------------------------------------------------------------- fixtures

    /**
     * Every video's stored place, keyed by title.
     *
     * @return array<string, string> title => "seriesId:position"
     */
    private function positions(): array
    {
        $out = [];

        foreach ($this->db()->all(
            'SELECT title, series_id, series_position FROM {videos} ORDER BY title'
        ) as $row) {
            $out[(string) $row['title']] = ($row['series_id'] ?? 'null') . ':' . $row['series_position'];
        }

        return $out;
    }

    /** @return list<string> */
    private function titles(int $seriesId): array
    {
        return array_map(
            static fn ($video): string => $video->title,
            $this->videos->forSeries($seriesId, true)
        );
    }

    private function video(string $title): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id' => 'bunny-' . $suffix,
            'slug'        => 'video-' . $suffix,
            'title'       => $title,
            'status'      => 'ready',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }
}
