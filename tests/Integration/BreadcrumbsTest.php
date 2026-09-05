<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\Breadcrumbs;
use Portal\Content\Category;
use Portal\Content\CategoryRepository;
use Portal\Content\SeriesRepository;
use Portal\Content\Video;

/**
 * Where a page sits, and what the trail is not allowed to say.
 *
 * Against a real database because the chain comes from the materialized path,
 * which is the thing being trusted: a trail assembled in the wrong order, or
 * from the wrong ancestors, is worse than none.
 */
final class BreadcrumbsTest extends DatabaseTestCase
{
    private CategoryRepository $categories;
    private SeriesRepository $series;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'videos', 'series', 'categories']);

        $this->categories = new CategoryRepository($this->db());
        $this->series = new SeriesRepository($this->db());
    }

    /**
     * @param callable(Category): bool|null $visible defaults to "everything"
     */
    private function crumbs(?callable $visible = null): Breadcrumbs
    {
        return new Breadcrumbs(
            $this->categories,
            static fn (string $path): string => 'https://example.test' . $path,
            $visible ?? static fn (Category $c): bool => true,
        );
    }

    /** @param list<array{name: string, url: string}> $trail */
    private function names(array $trail): array
    {
        return array_map(static fn (array $crumb): string => $crumb['name'], $trail);
    }

    /** @param array<string, mixed> $flags */
    private function category(string $name, ?int $parentId = null, array $flags = []): Category
    {
        $created = $this->categories->create(['name' => $name, 'parent_id' => $parentId]);

        if ($flags === []) {
            return $created;
        }

        /*
         * Updated AND RE-READ.
         *
         * The first version of this fixture kept the object create() returned,
         * which still said member_only = false — so two leak checks failed
         * against perfectly correct code, and the temptation there is to loosen
         * the check rather than fix the fixture. A fixture has to state the
         * whole state it wants, not the difference from one it has not looked
         * at.
         */
        $this->categories->update($created->id, $flags);

        return $this->categories->find($created->id) ?? $created;
    }

    // ------------------------------------------------------ the whole chain

    /**
     * The defect this replaces: the trail was flat.
     *
     * "Library / 2019" told somebody nothing about where they were, and on a
     * tree three deep it is the difference between browsing and guessing at
     * URLs.
     */
    public function testACategoryTrailCarriesEveryAncestor(): void
    {
        $sermons = $this->category('Sermons');
        $y2019 = $this->category('2019', $sermons->id);
        $january = $this->category('January', $y2019->id);

        self::assertSame(
            ['Library', 'Sermons', '2019', 'January'],
            $this->names($this->crumbs()->forCategory($january))
        );
    }

    /** Root first, deepest last, and the order is the query's not a sort. */
    public function testATopLevelCategoryIsJustLibraryAndItself(): void
    {
        $sermons = $this->category('Sermons');

        self::assertSame(['Library', 'Sermons'], $this->names($this->crumbs()->forCategory($sermons)));
    }

    /**
     * Every crumb carries both forms, and they are not interchangeable.
     *
     * `url` is absolute for the BreadcrumbList, which a machine reads with no
     * page to resolve a relative path against. `path` is relative for the
     * visible link, so a site reachable at more than one address does not send
     * a reader to the canonical one halfway through browsing.
     *
     * A smoke check found this by looking for the href it expected and getting
     * an absolute URL — the trail had one form and two consumers.
     */
    public function testEachCrumbCarriesAnAbsoluteUrlAndARelativePath(): void
    {
        $sermons = $this->category('Sermons');
        $trail = $this->crumbs()->forCategory($sermons);

        self::assertSame('https://example.test/', $trail[0]['url']);
        self::assertSame('/', $trail[0]['path']);

        self::assertSame('https://example.test/category/sermons', $trail[1]['url']);
        self::assertSame('/category/sermons', $trail[1]['path']);
    }

    // ------------------------------------------------------------ THE RULE

    /**
     * THE RULE: a restricted ancestor is never named.
     *
     * A public category can sit inside a members-only one, and
     * /category/2019 resolves directly for anybody — so printing the chain
     * naively puts the restricted section's NAME on a stranger's page. The
     * title is a leak too.
     *
     * Both directions in one test, because a check that only asserts the
     * absence cannot tell a working filter from one that hides everything.
     */
    public function testARestrictedAncestorIsNotNamedToSomebodyWhoCannotSeeIt(): void
    {
        $members = $this->category('Members Teaching', null, ['member_only' => true]);

        $y2019 = $this->category('2019', $members->id);

        $stranger = $this->crumbs(
            static fn (Category $c): bool => !$c->memberOnly
        );

        self::assertSame(
            ['Library', '2019'],
            $this->names($stranger->forCategory($y2019)),
            'A MEMBERS-ONLY SECTION WAS NAMED IN A STRANGER\'S BREADCRUMB TRAIL'
        );

        // And a member sees the whole thing, which is what proves the filter
        // was the rule rather than a trail that drops ancestors generally.
        self::assertSame(
            ['Library', 'Members Teaching', '2019'],
            $this->names($this->crumbs()->forCategory($y2019))
        );
    }

    /**
     * The gap closes rather than the trail being cut short.
     *
     * Truncating at the first restriction would take the Library link with it —
     * the one crumb that is always safe — leaving a page with no way up.
     */
    public function testTheTrailClosesOverAHiddenAncestorRatherThanStopping(): void
    {
        $top = $this->category('Top');
        $middle = $this->category('Middle', $top->id, ['hidden' => true]);
        $bottom = $this->category('Bottom', $middle->id);

        $stranger = $this->crumbs(static fn (Category $c): bool => !$c->hidden);

        self::assertSame(
            ['Library', 'Top', 'Bottom'],
            $this->names($stranger->forCategory($bottom))
        );
    }

    /** The node itself is asked too, not only its ancestors. */
    public function testARestrictedCategoryDoesNotNameItselfEither(): void
    {
        $secret = $this->category('Secret', null, ['member_only' => true]);

        $stranger = $this->crumbs(static fn (Category $c): bool => !$c->memberOnly);

        self::assertSame(['Library'], $this->names($stranger->forCategory($secret)));
    }

    // ------------------------------------------------------------- series

    public function testASeriesTrailGoesThroughItsCategory(): void
    {
        $sermons = $this->category('Sermons');
        $series = $this->series->create(['title' => 'Advent', 'category_id' => $sermons->id]);

        self::assertSame(
            ['Library', 'Sermons', 'Advent'],
            $this->names($this->crumbs()->forSeries($series, $sermons))
        );
    }

    public function testASeriesWithNoCategoryStillHasATrail(): void
    {
        $series = $this->series->create(['title' => 'Advent']);

        self::assertSame(['Library', 'Advent'], $this->names($this->crumbs()->forSeries($series, null)));
    }

    // -------------------------------------------------------------- video

    /**
     * The series wins when there is one.
     *
     * Somebody watching part three of a course is in the course; the category
     * is where the course is filed, and both appear — but the series is the
     * crumb immediately above the video.
     */
    public function testAVideoInASeriesIsShownInsideIt(): void
    {
        $sermons = $this->category('Sermons');
        $series = $this->series->create(['title' => 'Advent', 'category_id' => $sermons->id]);
        $video = $this->video('Part Three');

        self::assertSame(
            ['Library', 'Sermons', 'Advent', 'Part Three'],
            $this->names($this->crumbs()->forVideo($video, $series, $sermons))
        );
    }

    public function testAVideoWithNoSeriesFallsBackToItsCategory(): void
    {
        $sermons = $this->category('Sermons');
        $video = $this->video('A sermon');

        self::assertSame(
            ['Library', 'Sermons', 'A sermon'],
            $this->names($this->crumbs()->forVideo($video, null, $sermons))
        );
    }

    public function testAVideoFiledNowhereStillHasATrail(): void
    {
        $video = $this->video('Orphan');

        self::assertSame(['Library', 'Orphan'], $this->names($this->crumbs()->forVideo($video, null, null)));
    }

    /** And a restricted category is dropped from a video's trail too. */
    public function testAVideoTrailDoesNotNameARestrictedCategory(): void
    {
        $members = $this->category('Members Teaching', null, ['member_only' => true]);
        $video = $this->video('A sermon');

        $stranger = $this->crumbs(static fn (Category $c): bool => !$c->memberOnly);

        self::assertSame(
            ['Library', 'A sermon'],
            $this->names($stranger->forVideo($video, null, $members))
        );
    }

    // --------------------------------------------------------------- root

    public function testTheLibraryTrailIsJustTheRoot(): void
    {
        self::assertSame(['Library'], $this->names($this->crumbs()->root()));
    }

    private function video(string $title): Video
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $id = (int) $this->db()->insert('videos', [
            'provider_id' => 'bunny-' . $suffix,
            'slug'        => 'video-' . $suffix,
            'title'       => $title,
            'status'      => 'ready',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $row = $this->db()->first('SELECT * FROM {videos} WHERE id = ?', [$id]);

        return Video::fromRow((array) $row);
    }
}
