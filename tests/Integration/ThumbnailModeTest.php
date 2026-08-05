<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\ThumbnailPolicy;
use Portal\Content\Video;
use Portal\Content\VideoRepository;

/**
 * Thumbnail modes resolved against a real category tree.
 *
 * The pure policy is tested next door. What this covers is the part that only
 * a database can answer: walking a materialized path, reconciling several
 * categories that disagree, and doing all of it in a fixed number of queries
 * rather than a number that grows with the page.
 */
final class ThumbnailModeTest extends DatabaseTestCase
{
    private VideoRepository $videos;
    private CategoryRepository $categories;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'categories', 'videos']);

        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
    }

    // ------------------------------------------------------------ resolution

    public function testAVideoWithNoCategoryFollowsTheSiteDefault(): void
    {
        $video = $this->video();

        self::assertSame(ThumbnailPolicy::PUBLIC_ART, $this->resolve($video, false));
        self::assertSame(ThumbnailPolicy::MEMBERS, $this->resolve($video, true));
    }

    public function testACategoryLocksTheVideosInIt(): void
    {
        $members = $this->category('Members', mode: ThumbnailPolicy::MEMBERS);
        $video = $this->video(categoryIds: [$members]);

        self::assertSame(ThumbnailPolicy::MEMBERS, $this->resolve($video, false));
    }

    public function testTheLockIsInheritedByNestedCategories(): void
    {
        $members = $this->category('Members', mode: ThumbnailPolicy::MEMBERS);
        $year = $this->category('2026', parentId: $members);
        $video = $this->video(categoryIds: [$year]);

        self::assertSame(ThumbnailPolicy::MEMBERS, $this->resolve($video, false));
    }

    /** A nearer category can re-open artwork its parent withheld. */
    public function testANearerCategoryOverridesADistantOne(): void
    {
        $members = $this->category('Members', mode: ThumbnailPolicy::MEMBERS);
        $open = $this->category('Free samples', parentId: $members, mode: ThumbnailPolicy::PUBLIC_ART);
        $video = $this->video(categoryIds: [$open]);

        self::assertSame(ThumbnailPolicy::PUBLIC_ART, $this->resolve($video, false));
    }

    /**
     * When a video sits in two categories that disagree, the protective answer
     * wins. Anything else means the ordering of a join table decides whether
     * artwork leaks, which nobody could reason about.
     */
    public function testMembersWinsWhenCategoriesDisagree(): void
    {
        $open = $this->category('Public', mode: ThumbnailPolicy::PUBLIC_ART);
        $members = $this->category('Members', mode: ThumbnailPolicy::MEMBERS);

        $video = $this->video(categoryIds: [$open, $members]);
        self::assertSame(ThumbnailPolicy::MEMBERS, $this->resolve($video, false));

        // And the reverse assignment order gives the same answer.
        $other = $this->video(categoryIds: [$members, $open]);
        self::assertSame(ThumbnailPolicy::MEMBERS, $this->resolve($other, false));
    }

    /** The escape hatch: one video can always be forced public. */
    public function testAVideosOwnSettingOverridesEveryCategory(): void
    {
        $members = $this->category('Members', mode: ThumbnailPolicy::MEMBERS);
        $video = $this->video(mode: ThumbnailPolicy::PUBLIC_ART, categoryIds: [$members]);

        self::assertSame(ThumbnailPolicy::PUBLIC_ART, $this->resolve($video, false));
    }

    public function testAVideoCanBeLockedInsideAnOpenCategory(): void
    {
        $open = $this->category('Public', mode: ThumbnailPolicy::PUBLIC_ART);
        $video = $this->video(mode: ThumbnailPolicy::MEMBERS, categoryIds: [$open]);

        self::assertSame(ThumbnailPolicy::MEMBERS, $this->resolve($video, false));
    }

    public function testACategoryThatDefersDoesNotOverrideTheSiteDefault(): void
    {
        $neutral = $this->category('Sermons');
        $video = $this->video(categoryIds: [$neutral]);

        self::assertSame(ThumbnailPolicy::MEMBERS, $this->resolve($video, true));
        self::assertSame(ThumbnailPolicy::PUBLIC_ART, $this->resolve($video, false));
    }

    // -------------------------------------------------------------- batching

    /**
     * The reason this method exists at all.
     *
     * A listing renders up to a hundred cards. The obvious per-video
     * implementation would be two queries each — invisible on a seeded test
     * database and crippling on a real library — so the query count is pinned
     * rather than left to good intentions.
     */
    public function testResolvingManyVideosCostsAFixedNumberOfQueries(): void
    {
        $members = $this->category('Members', mode: ThumbnailPolicy::MEMBERS);

        $videos = [];
        for ($i = 0; $i < 25; $i++) {
            $videos[] = $this->videos->find($this->video(categoryIds: [$members]));
        }

        /** @var list<Video> $videos */
        $videos = array_values(array_filter($videos));

        $before = $this->db()->queryCount();
        $modes = $this->videos->thumbnailModes($videos, false);
        $after = $this->db()->queryCount();

        self::assertCount(25, $modes);
        self::assertLessThanOrEqual(
            3,
            $after - $before,
            'Resolving 25 videos must not scale with the number of videos.'
        );

        foreach ($modes as $mode) {
            self::assertSame(ThumbnailPolicy::MEMBERS, $mode);
        }
    }

    public function testNoVideosCostsNoQueries(): void
    {
        $before = $this->db()->queryCount();
        self::assertSame([], $this->videos->thumbnailModes([], true));
        self::assertSame($before, $this->db()->queryCount());
    }

    // ------------------------------------------------------------ persistence

    public function testTheModeSurvivesARoundTrip(): void
    {
        $id = $this->video();

        $this->videos->update($id, ['thumbnail_mode' => ThumbnailPolicy::MEMBERS]);
        self::assertSame(ThumbnailPolicy::MEMBERS, $this->videos->find($id)?->thumbnailMode);

        $categoryId = $this->category('Anything');
        $this->categories->update($categoryId, ['thumbnail_mode' => ThumbnailPolicy::MEMBERS]);
        self::assertSame(ThumbnailPolicy::MEMBERS, $this->categories->find($categoryId)?->thumbnailMode);
    }

    /** A junk value from a tampered form must not lock anything. */
    public function testAnInvalidSubmittedModeIsRejected(): void
    {
        $id = $this->video(mode: ThumbnailPolicy::PUBLIC_ART);

        $this->videos->update($id, ['thumbnail_mode' => 'members-only-please']);

        self::assertSame(
            ThumbnailPolicy::INHERIT,
            $this->videos->find($id)?->thumbnailMode,
            'An unrecognised value should fall back to inheriting, not to locking.'
        );
    }

    // --------------------------------------------------------------- fixtures

    private function resolve(int $videoId, bool $siteDefault): string
    {
        $video = $this->videos->find($videoId);
        self::assertNotNull($video);

        return $this->videos->thumbnailModes([$video], $siteDefault)[$videoId]
            ?? ThumbnailPolicy::INHERIT;
    }

    /** @param list<int> $categoryIds */
    private function video(
        string $mode = ThumbnailPolicy::INHERIT,
        array $categoryIds = []
    ): int {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        $id = $this->db()->insert('videos', [
            'provider_id'    => 'bunny-' . $suffix,
            'slug'           => 'video-' . $suffix,
            'title'          => 'A video',
            'status'         => 'ready',
            'thumbnail_mode' => $mode,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        if ($categoryIds !== []) {
            $this->videos->setCategories($id, $categoryIds);
        }

        return $id;
    }

    private function category(
        string $name,
        ?int $parentId = null,
        string $mode = ThumbnailPolicy::INHERIT
    ): int {
        $category = $this->categories->create([
            'name'      => $name . ' ' . bin2hex(random_bytes(3)),
            'parent_id' => $parentId,
        ]);

        if ($mode !== ThumbnailPolicy::INHERIT) {
            $this->categories->update($category->id, ['thumbnail_mode' => $mode]);
        }

        return $category->id;
    }
}
