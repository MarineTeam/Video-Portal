<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Http\HttpException;

/**
 * Deleting a category must not destroy what is under it.
 *
 * Until now it did. `delete()` removed the row, and fk_category_parent is
 * ON DELETE CASCADE, so deleting "Sermons" permanently destroyed every
 * subcategory beneath it — on a host with no shell and no database access, and
 * with a confirmation that read "Delete this category? Videos in it are kept."
 * That sentence was true. It was also the only thing anybody was told, and it
 * described the half that survived.
 *
 * Against a real MySQL database rather than a double, deliberately: the whole
 * subject here is what the CASCADE on the foreign key does, and a double has
 * no foreign keys, so a mock would report every one of these passing against
 * the original bug.
 */
final class CategoryTrashTest extends DatabaseTestCase
{
    private CategoryRepository $categories;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'videos', 'taggables', 'categories']);
        $this->categories = new CategoryRepository($this->db());
    }

    private function make(string $name, ?int $parentId = null): int
    {
        return $this->categories->create(['name' => $name, 'parent_id' => $parentId])->id;
    }

    /** Straight to the table, because what a listing says is the thing in question. */
    private function rowExists(int $id): bool
    {
        return $this->db()->value('SELECT COUNT(*) FROM {categories} WHERE id = ?', [$id]) > 0;
    }

    private function parentOf(int $id): ?int
    {
        $value = $this->db()->value('SELECT parent_id FROM {categories} WHERE id = ?', [$id]);

        return $value === null ? null : (int) $value;
    }

    // ------------------------------------------------- the rule that matters

    /**
     * THE RULE: trashing a parent touches nothing beneath it.
     *
     * This is the test that fails if the soft delete is ever taken back out.
     * It asserts on raw rows rather than on any listing, because a listing
     * that has learned to hide the subtree would report this as passing while
     * the rows were being destroyed.
     */
    public function testTrashingAParentLeavesItsChildrenInThePlaceTheyWere(): void
    {
        $sermons = $this->make('Sermons');
        $y2019 = $this->make('2019', $sermons);
        $y2020 = $this->make('2020', $sermons);
        $january = $this->make('January', $y2019);

        $this->categories->softDelete($sermons);

        self::assertTrue($this->rowExists($y2019), '2019 was destroyed by trashing its parent');
        self::assertTrue($this->rowExists($y2020), '2020 was destroyed by trashing its parent');
        self::assertTrue($this->rowExists($january), 'a grandchild was destroyed');

        // And they still point at the parent, which is what makes the restore
        // put the tree back rather than reassemble a guess at it.
        self::assertSame($sermons, $this->parentOf($y2019));
        self::assertSame($sermons, $this->parentOf($y2020));
        self::assertSame($y2019, $this->parentOf($january));
    }

    /** Only its own flag is written. A child in the trash would not come back. */
    public function testTrashingAParentDoesNotFlagItsChildren(): void
    {
        $sermons = $this->make('Sermons');
        $y2019 = $this->make('2019', $sermons);

        $this->categories->softDelete($sermons);

        self::assertNotNull($this->categories->findTrashed($sermons));
        self::assertNull($this->categories->findTrashed($y2019), 'the child was trashed too');
        self::assertNotNull($this->categories->find($y2019));
    }

    public function testRestoringPutsTheWholeSubtreeBackAsItWas(): void
    {
        $sermons = $this->make('Sermons');
        $y2019 = $this->make('2019', $sermons);

        $before = $this->db()->all('SELECT id, parent_id, path, depth, position FROM {categories} ORDER BY id');

        $this->categories->softDelete($sermons);
        $this->categories->restore($sermons);

        $after = $this->db()->all('SELECT id, parent_id, path, depth, position FROM {categories} ORDER BY id');

        self::assertEquals($before, $after);
        self::assertNotNull($this->categories->find($sermons));
        self::assertSame($sermons, $this->categories->find($y2019)?->parentId);
    }

    // ------------------------------------------ permanent delete is guarded

    /**
     * Permanent deletion is REFUSED while children exist, and this is the
     * guard that stands in for the cascade still being on the table.
     *
     * The next assertion is the important half: it is not enough that the call
     * threw. Nothing may have been destroyed on the way out.
     */
    public function testDeletingForGoodIsRefusedWhileChildrenExist(): void
    {
        $sermons = $this->make('Sermons');
        $y2019 = $this->make('2019', $sermons);

        $this->categories->softDelete($sermons);

        try {
            $this->categories->forceDelete($sermons);
            self::fail('a category with a subcategory was deleted for good');
        } catch (HttpException $e) {
            self::assertStringContainsString('subcategories', $e->getMessage());
        }

        self::assertTrue($this->rowExists($sermons));
        self::assertTrue($this->rowExists($y2019));
    }

    /**
     * A child sitting in the trash still counts.
     *
     * The cascade fires on the foreign key, which knows nothing about
     * deleted_at — so a guard that only counted live children would let the
     * database destroy a trashed one that somebody was about to restore.
     */
    public function testAChildInTheTrashStillBlocksAPermanentDelete(): void
    {
        $sermons = $this->make('Sermons');
        $y2019 = $this->make('2019', $sermons);

        $this->categories->softDelete($y2019);
        $this->categories->softDelete($sermons);

        $this->expectException(HttpException::class);
        $this->categories->forceDelete($sermons);
    }

    /** With the subtree emptied first, it goes. */
    public function testDeletingForGoodWorksOnceThereAreNoChildren(): void
    {
        $sermons = $this->make('Sermons');
        $y2019 = $this->make('2019', $sermons);

        $this->categories->softDelete($y2019);
        $this->categories->forceDelete($y2019);
        $this->categories->softDelete($sermons);
        $this->categories->forceDelete($sermons);

        self::assertFalse($this->rowExists($y2019));
        self::assertFalse($this->rowExists($sermons));
    }

    /** Videos are kept — the original promise, which was always true. */
    public function testDeletingForGoodKeepsTheVideos(): void
    {
        $sermons = $this->make('Sermons');
        $videoId = (int) $this->db()->insert('videos', [
            'provider_id' => 'v-trash-1',
            'slug'        => 'a-sermon',
            'title'       => 'A sermon',
            'status'      => 'ready',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->db()->insert('video_categories', ['video_id' => $videoId, 'category_id' => $sermons]);

        $this->categories->softDelete($sermons);
        $this->categories->forceDelete($sermons);

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {videos} WHERE id = ?', [$videoId]),
            'the video was deleted with its category'
        );
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {video_categories} WHERE video_id = ?', [$videoId]),
            'the association survived the category it pointed at'
        );
    }

    // --------------------------------------------------- it leaves the site

    /**
     * Both directions, because a check that only asserts the trashed one is
     * absent cannot tell "correctly hidden" from "hides everything".
     */
    public function testATrashedCategoryLeavesEveryListingAndALiveOneStays(): void
    {
        $sermons = $this->make('Sermons');
        $music = $this->make('Music');

        $names = static fn (array $list): array => array_map(
            static fn ($category): string => $category->name,
            $list
        );

        self::assertSame(['Sermons', 'Music'], $names($this->categories->roots(true)));

        $this->categories->softDelete($sermons);

        self::assertSame(['Music'], $names($this->categories->roots(true)));
        self::assertSame(['Music'], $names($this->categories->all(true)));

        $this->categories->restore($sermons);

        self::assertSame(['Sermons', 'Music'], $names($this->categories->roots(true)));
    }

    /**
     * The lookups every page uses hide it; exactly one lookup does not.
     *
     * findBySlug() is what /category/{slug} calls, so this is what makes a
     * trashed category 404 rather than render.
     */
    public function testTheOrdinaryLookupsHideItAndFindTrashedIsTheOneThatDoesNot(): void
    {
        $sermons = $this->make('Sermons');
        $slug = $this->categories->find($sermons)?->slug ?? '';

        $this->categories->softDelete($sermons);

        self::assertNull($this->categories->find($sermons));
        self::assertNull($this->categories->findBySlug($slug));
        self::assertSame($sermons, $this->categories->findTrashed($sermons)?->id);

        $this->categories->restore($sermons);

        self::assertNotNull($this->categories->findBySlug($slug));
        self::assertNull($this->categories->findTrashed($sermons));
    }

    /**
     * A trashed subcategory takes its videos out of the parent's listing.
     *
     * Otherwise trashing it removes the heading and leaves everything that was
     * under it on the parent's page, and the person who trashed it concludes
     * the trash did not work.
     */
    public function testDescendantIdsDropsATrashedSubtree(): void
    {
        $sermons = $this->make('Sermons');
        $y2019 = $this->make('2019', $sermons);

        self::assertEqualsCanonicalizing([$sermons, $y2019], $this->categories->descendantIds($sermons));

        $this->categories->softDelete($y2019);

        self::assertSame([$sermons], $this->categories->descendantIds($sermons));
        self::assertSame([], $this->categories->descendantIds($y2019), 'a trashed category has descendants');
    }

    // ------------------------------------------------------------ the model

    public function testTheModelAnswersTrashedAndIsNotPublicWhileItIs(): void
    {
        $sermons = $this->make('Sermons');

        self::assertFalse($this->categories->find($sermons)?->isTrashed());
        self::assertTrue($this->categories->find($sermons)?->isPublic());

        $this->categories->softDelete($sermons);

        self::assertTrue($this->categories->findTrashed($sermons)?->isTrashed());
        self::assertFalse($this->categories->findTrashed($sermons)?->isPublic());
    }

    public function testTheTrashListAndItsCountAgree(): void
    {
        $sermons = $this->make('Sermons');
        $this->make('Music');

        self::assertSame(0, $this->categories->trashedCount());
        self::assertSame([], $this->categories->trashed());

        $this->categories->softDelete($sermons);

        self::assertSame(1, $this->categories->trashedCount());
        self::assertSame([$sermons], array_map(
            static fn ($category): int => $category->id,
            $this->categories->trashed()
        ));
    }
}
