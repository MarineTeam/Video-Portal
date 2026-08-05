<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\HomeRow;
use Portal\Content\HomeRowRepository;
use Portal\Content\PlaylistRepository;
use Portal\Content\SeriesRepository;
use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;

/**
 * The curated homepage.
 *
 * Two claims carry the feature. A row points at content rather than holding a
 * copy, so curating the source curates the page. And a row that has nothing to
 * show is dropped rather than rendered as a heading over nothing — which is the
 * behaviour that makes deleting a playlist safe.
 */
final class HomeRowTest extends DatabaseTestCase
{
    private HomeRowRepository $rows;
    private VideoRepository $videos;
    private CategoryRepository $categories;
    private SeriesRepository $series;
    private PlaylistRepository $playlists;

    protected function setUp(): void
    {
        $this->truncate([
            'home_rows', 'playlist_items', 'playlists',
            'video_categories', 'categories', 'videos', 'series',
        ]);

        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
        $this->series = new SeriesRepository($this->db());
        $this->playlists = new PlaylistRepository($this->db());

        $this->rows = new HomeRowRepository(
            $this->db(),
            $this->videos,
            $this->categories,
            $this->series,
            $this->playlists,
        );
    }

    // -------------------------------------------------------------- the list

    /**
     * The case every existing install is in. An empty table must not turn the
     * front page blank; it means "keep doing what you were doing".
     */
    public function testAnEmptyTableIsNotAConfiguredHomepage(): void
    {
        self::assertFalse($this->rows->isConfigured());
        self::assertSame([], $this->rows->all());
    }

    public function testAddingARowConfiguresIt(): void
    {
        $this->rows->create(['source_type' => HomeRow::LATEST]);

        self::assertTrue($this->rows->isConfigured());
    }

    /** A row switched off is not a homepage either. */
    public function testDeactivatingEveryRowUnconfiguresIt(): void
    {
        $row = $this->rows->create(['source_type' => HomeRow::LATEST]);
        $this->rows->update($row->id, ['is_active' => false]);

        self::assertFalse($this->rows->isConfigured());
        self::assertSame([], $this->rows->all());
        self::assertCount(1, $this->rows->all(true));
    }

    public function testRowsComeBackInOrder(): void
    {
        $first = $this->rows->create(['source_type' => HomeRow::LATEST, 'title' => 'One']);
        $second = $this->rows->create(['source_type' => HomeRow::FEATURED, 'title' => 'Two']);

        self::assertSame(['One', 'Two'], array_map(
            static fn (HomeRow $r): string => $r->title,
            $this->rows->all()
        ));

        $this->rows->move($second->id, -1);

        self::assertSame(['Two', 'One'], array_map(
            static fn (HomeRow $r): string => $r->title,
            $this->rows->all()
        ));

        // And the first row cannot climb past the top.
        $this->rows->move($first->id, 1);
        $this->rows->move($first->id, 1);

        self::assertCount(2, $this->rows->all());
    }

    // ------------------------------------------------------------- resolving

    public function testALatestRowShowsVideos(): void
    {
        $this->video('One');
        $this->video('Two');

        $row = $this->rows->create(['source_type' => HomeRow::LATEST]);
        $resolved = $this->rows->resolve($row, []);

        self::assertNotNull($resolved);
        self::assertCount(2, $resolved['videos']);
    }

    public function testAFeaturedRowShowsOnlyFeaturedVideos(): void
    {
        $this->video('Ordinary');
        $id = $this->video('Special');
        $this->db()->execute('UPDATE {videos} SET featured = 1 WHERE id = ?', [$id]);

        $row = $this->rows->create(['source_type' => HomeRow::FEATURED]);
        $resolved = $this->rows->resolve($row, []);

        self::assertNotNull($resolved);
        self::assertSame(['Special'], $this->titles($resolved['videos']));
    }

    public function testACategoryRowShowsThatCategory(): void
    {
        $categoryId = $this->categories->create(['name' => 'Sermons'])->id;
        $inside = $this->video('Inside');
        $this->video('Outside');
        $this->videos->setCategories($inside, [$categoryId]);

        $row = $this->rows->create([
            'source_type' => HomeRow::CATEGORY,
            'source_id'   => $categoryId,
        ]);

        $resolved = $this->rows->resolve($row, []);

        self::assertNotNull($resolved);
        self::assertSame(['Inside'], $this->titles($resolved['videos']));
        self::assertSame('Sermons', $resolved['title']);
        self::assertSame('/category/sermons', $resolved['url']);
    }

    /** A series row is in running order — the reason to have one at all. */
    public function testASeriesRowKeepsTheRunningOrder(): void
    {
        $seriesId = $this->series->create(['title' => 'Advent'])->id;
        $a = $this->video('Part one');
        $b = $this->video('Part two');
        $this->series->setVideos($seriesId, [$b, $a]);

        $row = $this->rows->create(['source_type' => HomeRow::SERIES, 'source_id' => $seriesId]);
        $resolved = $this->rows->resolve($row, []);

        self::assertNotNull($resolved);
        self::assertSame(['Part two', 'Part one'], $this->titles($resolved['videos']));
    }

    public function testAPlaylistRowKeepsItsArrangedOrder(): void
    {
        $playlistId = $this->playlists->create(['title' => 'Best of'])->id;
        $a = $this->video('A');
        $b = $this->video('B');
        $this->playlists->setVideos($playlistId, [$b, $a]);

        $row = $this->rows->create(['source_type' => HomeRow::PLAYLIST, 'source_id' => $playlistId]);
        $resolved = $this->rows->resolve($row, []);

        self::assertNotNull($resolved);
        self::assertSame(['B', 'A'], $this->titles($resolved['videos']));
    }

    /**
     * The claim the whole design rests on: a row is a pointer, so editing the
     * playlist edits the homepage. There is one place to curate, not two.
     */
    public function testChangingThePlaylistChangesTheRow(): void
    {
        $playlistId = $this->playlists->create(['title' => 'Best of'])->id;
        $a = $this->video('A');
        $b = $this->video('B');
        $this->playlists->setVideos($playlistId, [$a]);

        $row = $this->rows->create(['source_type' => HomeRow::PLAYLIST, 'source_id' => $playlistId]);
        self::assertSame(['A'], $this->titles($this->rows->resolve($row, [])['videos'] ?? []));

        $this->playlists->setVideos($playlistId, [$b, $a]);

        self::assertSame(['B', 'A'], $this->titles($this->rows->resolve($row, [])['videos'] ?? []));
    }

    public function testTheEditorsTitleWinsOverTheSourceName(): void
    {
        $categoryId = $this->categories->create(['name' => 'Sermons'])->id;
        $video = $this->video('Inside');
        $this->videos->setCategories($video, [$categoryId]);

        $row = $this->rows->create([
            'source_type' => HomeRow::CATEGORY,
            'source_id'   => $categoryId,
            'title'       => 'This Sunday',
        ]);

        self::assertSame('This Sunday', $this->rows->resolve($row, [])['title'] ?? '');
    }

    public function testTheRowRespectsItsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->video('Video ' . $i);
        }

        $row = $this->rows->create(['source_type' => HomeRow::LATEST, 'max_items' => 2]);

        self::assertCount(2, $this->rows->resolve($row, [])['videos'] ?? []);
    }

    // ------------------------------------------------------------- disappearing

    /**
     * A heading over nothing is worse than one row fewer, and this is what
     * makes deleting a playlist safe rather than something that breaks the
     * front page.
     */
    public function testARowWhoseTargetIsDeletedDisappears(): void
    {
        $playlistId = $this->playlists->create(['title' => 'Best of'])->id;
        $this->playlists->setVideos($playlistId, [$this->video('A')]);

        $row = $this->rows->create(['source_type' => HomeRow::PLAYLIST, 'source_id' => $playlistId]);
        self::assertNotNull($this->rows->resolve($row, []));

        $this->playlists->delete($playlistId);

        self::assertNull($this->rows->resolve($row, []));
    }

    public function testARowWithNothingToShowDisappears(): void
    {
        $categoryId = $this->categories->create(['name' => 'Empty'])->id;

        $row = $this->rows->create(['source_type' => HomeRow::CATEGORY, 'source_id' => $categoryId]);

        self::assertNull($this->rows->resolve($row, []));
    }

    public function testAFeaturedRowWithNothingFeaturedDisappears(): void
    {
        $this->video('Ordinary');

        $row = $this->rows->create(['source_type' => HomeRow::FEATURED]);

        self::assertNull($this->rows->resolve($row, []));
    }

    /**
     * Continue-watching survives being empty, because only the controller can
     * fill it — the repository returning nothing is not the same as there being
     * nothing.
     */
    public function testAContinueRowSurvivesBeingEmptyHere(): void
    {
        $row = $this->rows->create(['source_type' => HomeRow::CONTINUE]);
        $resolved = $this->rows->resolve($row, []);

        self::assertNotNull($resolved);
        self::assertSame([], $resolved['videos']);
        self::assertTrue($row->isPersonal());
    }

    // ------------------------------------------------------------- visibility

    public function testARowDoesNotRevealUnpublishedVideos(): void
    {
        $id = $this->video('Draft');
        $this->db()->execute('UPDATE {videos} SET is_published = 0 WHERE id = ?', [$id]);
        $this->video('Live');

        $row = $this->rows->create(['source_type' => HomeRow::LATEST]);

        self::assertSame(['Live'], $this->titles($this->rows->resolve($row, [])['videos'] ?? []));
    }

    public function testAPlaylistRowDoesNotRevealMemberOnlyVideos(): void
    {
        $playlistId = $this->playlists->create(['title' => 'Mixed'])->id;
        $open = $this->video('Public');
        $members = $this->video('Members');
        $this->db()->execute('UPDATE {videos} SET member_only = 1 WHERE id = ?', [$members]);
        $this->playlists->setVideos($playlistId, [$open, $members]);

        $row = $this->rows->create(['source_type' => HomeRow::PLAYLIST, 'source_id' => $playlistId]);

        self::assertSame(['Public'], $this->titles($this->rows->resolve($row, [])['videos'] ?? []));
        self::assertSame(
            ['Public', 'Members'],
            $this->titles($this->rows->resolve($row, ['includeMemberOnly' => true])['videos'] ?? [])
        );
    }

    // --------------------------------------------------------------- writing

    /**
     * Refused rather than defaulted. A row silently pointing somewhere other
     * than where an editor aimed it is worse than one that would not save.
     */
    public function testAnUnknownSourceIsRefused(): void
    {
        $this->expectException(HttpException::class);
        $this->rows->create(['source_type' => 'carousel-of-wonders']);
    }

    public function testASourceThatNeedsATargetAndHasNoneIsRefused(): void
    {
        $this->expectException(HttpException::class);
        $this->rows->create(['source_type' => HomeRow::CATEGORY]);
    }

    public function testASourceThatNeedsNoTargetIgnoresOne(): void
    {
        $row = $this->rows->create(['source_type' => HomeRow::LATEST, 'source_id' => 42]);

        self::assertNull($row->sourceId);
    }

    /**
     * A target that made sense for a series is meaningless once the row points
     * at a playlist. Keeping the old number would silently show the wrong
     * thing — the same id exists in both tables and means different content.
     */
    public function testChangingTheSourceRequiresANewTarget(): void
    {
        $seriesId = $this->series->create(['title' => 'Advent'])->id;
        $row = $this->rows->create(['source_type' => HomeRow::SERIES, 'source_id' => $seriesId]);

        $this->expectException(HttpException::class);
        $this->rows->update($row->id, ['source_type' => HomeRow::PLAYLIST]);
    }

    public function testTheItemCountIsClamped(): void
    {
        $low = $this->rows->create(['source_type' => HomeRow::LATEST, 'max_items' => 0]);
        $high = $this->rows->create(['source_type' => HomeRow::LATEST, 'max_items' => 9999]);

        self::assertSame(1, $low->maxItems);
        self::assertSame(50, $high->maxItems);
    }

    public function testDeletingARowLeavesTheContentAlone(): void
    {
        $playlistId = $this->playlists->create(['title' => 'Best of'])->id;
        $this->playlists->setVideos($playlistId, [$this->video('A')]);

        $row = $this->rows->create(['source_type' => HomeRow::PLAYLIST, 'source_id' => $playlistId]);
        $this->rows->delete($row->id);

        self::assertSame([], $this->rows->all());
        self::assertNotNull($this->playlists->find($playlistId));
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {videos}'));
    }

    // --------------------------------------------------------------- fixtures

    /**
     * @param  list<Video> $videos
     * @return list<string>
     */
    private function titles(array $videos): array
    {
        return array_map(static fn (Video $v): string => $v->title, $videos);
    }

    private function video(string $title): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
