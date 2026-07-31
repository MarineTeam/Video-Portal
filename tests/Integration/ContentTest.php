<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Video\VideoMeta;

/**
 * The category tree and the video taxonomy.
 *
 * The materialized `path` column is derived data, and derived data that drifts
 * is worse than no cache at all — the permission resolver and the plugin
 * override resolver both read it, so a stale path silently grants or denies
 * access. Most of these tests exist to prove it stays correct through moves.
 */
final class ContentTest extends DatabaseTestCase
{
    private CategoryRepository $categories;
    private VideoRepository $videos;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'slug_aliases', 'videos', 'series', 'categories']);

        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
    }

    // ------------------------------------------------------------ tree shape

    public function testCreatingARootCategorySetsPathAndDepth(): void
    {
        $category = $this->categories->create(['name' => 'Sermons']);

        self::assertSame('/' . $category->id . '/', $category->path);
        self::assertSame(0, $category->depth);
        self::assertTrue($category->isRoot());
        self::assertSame('sermons', $category->slug);
    }

    public function testNestingBuildsThePathFromTheParent(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $year    = $this->categories->create(['name' => '2026', 'parent_id' => $sermons->id]);
        $advent  = $this->categories->create(['name' => 'Advent', 'parent_id' => $year->id]);

        self::assertSame("/{$sermons->id}/{$year->id}/", $year->path);
        self::assertSame("/{$sermons->id}/{$year->id}/{$advent->id}/", $advent->path);
        self::assertSame(2, $advent->depth);
    }

    public function testSlugsAreUniqueAndSuffixOnCollision(): void
    {
        $first  = $this->categories->create(['name' => 'Teaching']);
        $second = $this->categories->create(['name' => 'Teaching']);

        self::assertSame('teaching', $first->slug);
        self::assertSame('teaching-2', $second->slug);
    }

    public function testAncestorsReturnTheBreadcrumbTrail(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $year    = $this->categories->create(['name' => '2026', 'parent_id' => $sermons->id]);
        $advent  = $this->categories->create(['name' => 'Advent', 'parent_id' => $year->id]);

        $trail = array_map(
            static fn ($c): string => $c->name,
            $this->categories->ancestors($advent->id)
        );

        self::assertSame(['Sermons', '2026'], $trail);
    }

    public function testDescendantIdsIncludeTheWholeSubtree(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $year    = $this->categories->create(['name' => '2026', 'parent_id' => $sermons->id]);
        $advent  = $this->categories->create(['name' => 'Advent', 'parent_id' => $year->id]);
        $other   = $this->categories->create(['name' => 'Classes']);

        $ids = $this->categories->descendantIds($sermons->id);

        self::assertContains($sermons->id, $ids);
        self::assertContains($year->id, $ids);
        self::assertContains($advent->id, $ids);
        self::assertNotContains($other->id, $ids);
    }

    /**
     * A path prefix match must not treat `_` as a wildcard, or /1/2/ would
     * match /1/20/ and a category would inherit a sibling's permissions.
     */
    public function testDescendantLookupIsNotFooledByAdjacentIds(): void
    {
        // Create enough roots that a two-digit id exists alongside its
        // one-digit prefix.
        $created = [];
        for ($i = 0; $i < 12; $i++) {
            $created[] = $this->categories->create(['name' => "Root {$i}"]);
        }

        foreach ($created as $category) {
            $ids = $this->categories->descendantIds($category->id);
            self::assertSame(
                [$category->id],
                $ids,
                "A childless root should return only itself, got: " . implode(',', $ids)
            );
        }
    }

    // ------------------------------------------------------------------ moves

    public function testMovingACategoryRewritesItsSubtree(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $classes = $this->categories->create(['name' => 'Classes']);
        $year    = $this->categories->create(['name' => '2026', 'parent_id' => $sermons->id]);
        $advent  = $this->categories->create(['name' => 'Advent', 'parent_id' => $year->id]);

        $this->categories->update($year->id, ['parent_id' => $classes->id]);

        $movedYear   = $this->categories->find($year->id);
        $movedAdvent = $this->categories->find($advent->id);

        self::assertNotNull($movedYear);
        self::assertNotNull($movedAdvent);

        self::assertSame("/{$classes->id}/{$year->id}/", $movedYear->path);
        self::assertSame(1, $movedYear->depth);

        self::assertSame(
            "/{$classes->id}/{$year->id}/{$advent->id}/",
            $movedAdvent->path,
            'A grandchild must be rewritten too, or ancestor lookups go stale.'
        );
        self::assertSame(2, $movedAdvent->depth);
    }

    public function testMovingToRootRewritesThePath(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $year    = $this->categories->create(['name' => '2026', 'parent_id' => $sermons->id]);
        $advent  = $this->categories->create(['name' => 'Advent', 'parent_id' => $year->id]);

        $this->categories->update($year->id, ['parent_id' => null]);

        $movedYear   = $this->categories->find($year->id);
        $movedAdvent = $this->categories->find($advent->id);

        self::assertNotNull($movedYear);
        self::assertNotNull($movedAdvent);
        self::assertSame("/{$year->id}/", $movedYear->path);
        self::assertSame(0, $movedYear->depth);
        self::assertSame("/{$year->id}/{$advent->id}/", $movedAdvent->path);
        self::assertSame(1, $movedAdvent->depth);
    }

    /**
     * A cycle makes every ancestor walk run forever. The check has to be
     * unconditional, not a UI nicety.
     */
    public function testACategoryCannotBeMovedIntoItsOwnDescendant(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $year    = $this->categories->create(['name' => '2026', 'parent_id' => $sermons->id]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('own subcategories');

        $this->categories->update($sermons->id, ['parent_id' => $year->id]);
    }

    public function testACategoryCannotBeItsOwnParent(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);

        $this->expectException(HttpException::class);

        $this->categories->update($sermons->id, ['parent_id' => $sermons->id]);
    }

    public function testNestingIsBoundedByAMaximumDepth(): void
    {
        $parent = $this->categories->create(['name' => 'Level 0']);

        // Push well past the limit so the refusal is definitely reached.
        for ($depth = 1; $depth <= 20; $depth++) {
            try {
                $parent = $this->categories->create(['name' => "Level {$depth}", 'parent_id' => $parent->id]);
            } catch (HttpException) {
                self::assertGreaterThan(5, $depth, 'The depth limit should not bite this early.');
                return;
            }
        }

        self::fail('Nesting should have been refused somewhere in the first 20 levels.');
    }

    public function testRenamingRecordsASlugAliasSoOldLinksKeepWorking(): void
    {
        $category = $this->categories->create(['name' => 'Sermons']);
        $this->categories->update($category->id, ['slug' => 'messages']);

        $updated = $this->categories->find($category->id);
        self::assertNotNull($updated);
        self::assertSame('messages', $updated->slug);

        self::assertNotNull(
            $this->db()->value(
                'SELECT 1 FROM {slug_aliases} WHERE target_type = "category" AND slug = ?',
                ['sermons']
            ),
            'The previous slug should be kept as an alias.'
        );
    }

    // ------------------------------------------------------ provider import

    public function testImportingCollectionsCreatesCategories(): void
    {
        $result = $this->categories->importCollections([
            ['id' => 'coll-1', 'name' => 'Sunday Mornings', 'videoCount' => 4],
            ['id' => 'coll-2', 'name' => 'Midweek',         'videoCount' => 2],
        ]);

        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['skipped']);
        self::assertCount(2, $this->categories->all());
    }

    /**
     * The contract that makes local taxonomy authoritative: re-importing must
     * never undo an editor's rename.
     */
    public function testReImportingDoesNotRenameAnEditedCategory(): void
    {
        $this->categories->importCollections([
            ['id' => 'coll-1', 'name' => 'Untitled Collection', 'videoCount' => 0],
        ]);

        $category = $this->categories->all()[0];
        $this->categories->update($category->id, ['name' => 'Sunday Mornings']);

        $result = $this->categories->importCollections([
            ['id' => 'coll-1', 'name' => 'Untitled Collection', 'videoCount' => 0],
        ]);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['skipped']);

        $unchanged = $this->categories->find($category->id);
        self::assertNotNull($unchanged);
        self::assertSame('Sunday Mornings', $unchanged->name);
    }

    // -------------------------------------------------- local taxonomy wins

    public function testLocalCategoryOverridesTheProviderCollection(): void
    {
        $this->categories->importCollections([
            ['id' => 'coll-1', 'name' => 'Imported', 'videoCount' => 1],
        ]);
        $imported = $this->categories->all()[0];
        $local = $this->categories->create(['name' => 'Where I Actually Want It']);

        $video = $this->makeVideo('A sermon', 'coll-1');

        // With no local assignment, the imported collection is the answer.
        self::assertSame($imported->id, $this->videos->effectiveCategoryId($video));

        $this->videos->setCategories($video->id, [$local->id]);
        $reloaded = $this->videos->find($video->id);
        self::assertNotNull($reloaded);

        self::assertSame(
            $local->id,
            $this->videos->effectiveCategoryId($reloaded),
            'A local assignment must win over the provider collection.'
        );
    }

    public function testUncategorisedVideoWithNoCollectionHasNoCategory(): void
    {
        $video = $this->makeVideo('Orphan', null);

        self::assertNull($this->videos->effectiveCategoryId($video));
    }

    // ------------------------------------------------------------- listings

    public function testListingExcludesUnpublishedAndProcessingByDefault(): void
    {
        $ready = $this->makeVideo('Published', null, ['status' => 'ready', 'is_published' => 1]);
        $this->makeVideo('Draft',       null, ['status' => 'ready',      'is_published' => 0]);
        $this->makeVideo('Encoding',    null, ['status' => 'processing', 'is_published' => 1]);
        $this->makeVideo('Hidden',      null, ['status' => 'ready', 'is_published' => 1, 'hidden' => 1]);
        $this->makeVideo('Members',     null, ['status' => 'ready', 'is_published' => 1, 'member_only' => 1]);

        $result = $this->videos->query();

        self::assertSame(1, $result['total']);
        self::assertSame($ready->id, $result['items'][0]->id);
    }

    public function testAdminListingCanIncludeEverything(): void
    {
        $this->makeVideo('Published', null, ['status' => 'ready', 'is_published' => 1]);
        $this->makeVideo('Draft',     null, ['status' => 'ready', 'is_published' => 0]);
        $this->makeVideo('Encoding',  null, ['status' => 'processing']);

        $result = $this->videos->query([
            'includeUnpublished' => true,
            'includeProcessing'  => true,
            'includeHidden'      => true,
            'includeMemberOnly'  => true,
        ]);

        self::assertSame(3, $result['total']);
    }

    /** A future publish date must not surface the video early. */
    public function testScheduledVideosStayHiddenUntilTheirTime(): void
    {
        $this->makeVideo('Tomorrow', null, [
            'status'       => 'ready',
            'is_published' => 1,
            'published_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        self::assertSame(0, $this->videos->query()['total']);
    }

    public function testCategoryListingIncludesSubcategories(): void
    {
        $sermons = $this->categories->create(['name' => 'Sermons']);
        $year    = $this->categories->create(['name' => '2026', 'parent_id' => $sermons->id]);

        $parentVideo = $this->makeVideo('In parent', null, ['status' => 'ready', 'is_published' => 1]);
        $childVideo  = $this->makeVideo('In child',  null, ['status' => 'ready', 'is_published' => 1]);

        $this->videos->setCategories($parentVideo->id, [$sermons->id]);
        $this->videos->setCategories($childVideo->id, [$year->id]);

        $result = $this->videos->query(['categoryId' => $sermons->id]);

        self::assertSame(2, $result['total'], 'A parent category should include its descendants.');
    }

    public function testSearchMatchesTitleAndDescription(): void
    {
        $this->makeVideo('Romans chapter 1', null, ['status' => 'ready', 'is_published' => 1]);
        $this->makeVideo('Unrelated', null, [
            'status' => 'ready', 'is_published' => 1, 'description' => 'A study in Romans.',
        ]);
        $this->makeVideo('Nothing to do with it', null, ['status' => 'ready', 'is_published' => 1]);

        self::assertSame(2, $this->videos->query(['search' => 'Romans'])['total']);
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        $this->makeVideo('Real title', null, ['status' => 'ready', 'is_published' => 1]);

        self::assertSame(
            0,
            $this->videos->query(['search' => '%'])['total'],
            'A percent sign must be a literal, not a match-everything wildcard.'
        );
    }

    public function testPaginationSlicesAndReportsTheTotal(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeVideo("Video {$i}", null, ['status' => 'ready', 'is_published' => 1]);
        }

        $page1 = $this->videos->query([], 1, 10);
        $page3 = $this->videos->query([], 3, 10);

        self::assertSame(25, $page1['total']);
        self::assertCount(10, $page1['items']);
        self::assertCount(5, $page3['items']);
    }

    // ----------------------------------------------------------------- sync

    public function testSyncCreatesNewVideosUnpublished(): void
    {
        $result = $this->videos->syncFromProvider([
            new VideoMeta(id: 'abc-1', title: 'Fresh upload', status: VideoMeta::STATUS_READY, duration: 120),
        ]);

        self::assertSame(1, $result['created']);

        $video = $this->videos->findByProviderId('abc-1');
        self::assertNotNull($video);
        self::assertFalse(
            $video->isPublished,
            'A video appearing at the provider must not publish itself onto a public site.'
        );
    }

    /**
     * The rule that protects editorial work: sync refreshes provider-owned
     * fields only. Someone who renamed final_v3_REALFINAL.mp4 keeps their name.
     */
    public function testSyncRefreshesProviderFieldsButNeverTheTitle(): void
    {
        $this->videos->syncFromProvider([
            new VideoMeta(id: 'abc-1', title: 'final_v3_REALFINAL.mp4', status: VideoMeta::STATUS_PROCESSING),
        ]);

        $video = $this->videos->findByProviderId('abc-1');
        self::assertNotNull($video);
        $this->videos->update($video->id, ['title' => 'Sunday Morning Service']);

        $this->videos->syncFromProvider([
            new VideoMeta(
                id: 'abc-1',
                title: 'final_v3_REALFINAL.mp4',
                status: VideoMeta::STATUS_READY,
                duration: 3600,
            ),
        ]);

        $after = $this->videos->findByProviderId('abc-1');
        self::assertNotNull($after);
        self::assertSame('Sunday Morning Service', $after->title, 'Sync must not overwrite an edited title.');
        self::assertSame(Video::STATUS_READY, $after->status, 'Status is provider-owned and should refresh.');
        self::assertSame(3600, $after->duration);
    }

    /**
     * Deleting local rows on the strength of one API response would destroy
     * categorisation and share history if that response were ever wrong.
     */
    public function testAVideoMissingFromTheProviderIsFlaggedNotDeleted(): void
    {
        $this->videos->syncFromProvider([
            new VideoMeta(id: 'abc-1', title: 'Will vanish', status: VideoMeta::STATUS_READY),
            new VideoMeta(id: 'abc-2', title: 'Stays',       status: VideoMeta::STATUS_READY),
        ]);

        $this->videos->syncFromProvider([
            new VideoMeta(id: 'abc-2', title: 'Stays', status: VideoMeta::STATUS_READY),
        ]);

        $vanished = $this->videos->findByProviderId('abc-1');
        self::assertNotNull($vanished, 'The row must survive.');
        self::assertSame(Video::STATUS_FAILED, $vanished->status);
    }

    public function testSoftDeleteHidesTheVideoButKeepsTheRow(): void
    {
        $video = $this->makeVideo('Doomed', null, ['status' => 'ready', 'is_published' => 1]);

        $this->videos->softDelete($video->id);

        self::assertNull($this->videos->find($video->id));
        self::assertSame(0, $this->videos->query()['total']);
        self::assertNotNull($this->db()->value('SELECT 1 FROM {videos} WHERE id = ?', [$video->id]));

        $this->videos->restore($video->id);
        self::assertNotNull($this->videos->find($video->id));
    }

    // -------------------------------------------------------------- fixtures

    /** @param array<string, mixed> $overrides */
    private function makeVideo(string $title, ?string $collectionId, array $overrides = []): Video
    {
        $now = date('Y-m-d H:i:s');

        // $overrides on the LEFT: PHP's + keeps the left operand's keys, so
        // writing it the other way round silently discards every override and
        // makes the fixture ignore what the test asked for.
        $id = $this->db()->insert('videos', $overrides + [
            'provider'               => 'bunny',
            'provider_id'            => bin2hex(random_bytes(8)),
            'provider_collection_id' => $collectionId,
            'slug'                   => $this->videos->uniqueSlug($title),
            'title'                  => $title,
            'status'                 => 'ready',
            'is_published'           => 1,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        $video = $this->videos->find($id);
        if ($video === null) {
            // A fixture created as unpublished is still findable; only a soft
            // delete would hide it from find().
            self::fail('Fixture video could not be read back.');
        }

        return $video;
    }
}
