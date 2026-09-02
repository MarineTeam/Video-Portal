<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\CategoryRepository;
use Portal\Content\DownloadPolicy;
use Portal\Content\VideoRepository;

/**
 * The download chain resolved against real rows.
 *
 * `DownloadPolicyTest` proves the rule; this proves the repository asks it the
 * right questions — that it reads the series the video is actually in, walks
 * the category ancestors rather than only the categories themselves, and
 * reconciles a disagreement the same way the pure rule does.
 *
 * The two are separate because the pure test cannot catch a query that reads
 * the wrong column, and an integration test alone would make the rule itself
 * hard to see.
 */
final class DownloadResolutionTest extends DatabaseTestCase
{
    private VideoRepository $videos;
    private CategoryRepository $categories;

    protected function setUp(): void
    {
        $this->truncate(['video_categories', 'videos', 'series', 'categories']);

        $this->categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $this->categories);
    }

    public function testWithNothingSetAnywhereTheSiteDefaultDecides(): void
    {
        $id = $this->video();

        self::assertSame(DownloadPolicy::BLOCK, $this->resolve($id, false));
        self::assertSame(DownloadPolicy::ALLOW, $this->resolve($id, true));
    }

    public function testTheVideosOwnSettingIsReadFromItsRow(): void
    {
        $id = $this->video();
        $this->setMode('videos', $id, DownloadPolicy::ALLOW);

        self::assertSame(DownloadPolicy::ALLOW, $this->resolve($id, false));
    }

    public function testASeriesAllowsEveryEpisode(): void
    {
        $seriesId = $this->series();
        $id = $this->video($seriesId);
        $this->setMode('series', $seriesId, DownloadPolicy::ALLOW);

        self::assertSame(DownloadPolicy::ALLOW, $this->resolve($id, false));
    }

    /**
     * And a blocking series closes an episode the site default would have
     * opened — the level existing at all is only useful if it can say no.
     */
    public function testABlockingSeriesOutranksAPermissiveSite(): void
    {
        $seriesId = $this->series();
        $id = $this->video($seriesId);
        $this->setMode('series', $seriesId, DownloadPolicy::BLOCK);

        self::assertSame(DownloadPolicy::BLOCK, $this->resolve($id, true));
    }

    public function testTheVideoOutranksItsSeries(): void
    {
        $seriesId = $this->series();
        $id = $this->video($seriesId);
        $this->setMode('series', $seriesId, DownloadPolicy::BLOCK);
        $this->setMode('videos', $id, DownloadPolicy::ALLOW);

        self::assertSame(DownloadPolicy::ALLOW, $this->resolve($id, false));
    }

    /**
     * The video's series is read, not just any series. A query missing its
     * WHERE would pick up whichever row the database happened to return — the
     * shape that has been vacuous three times in this suite's history.
     */
    public function testAnotherSeriesSettingDoesNotLeakAcross(): void
    {
        $mine = $this->series('Mine');
        $theirs = $this->series('Theirs');
        $this->setMode('series', $theirs, DownloadPolicy::ALLOW);

        $id = $this->video($mine);

        self::assertSame(
            DownloadPolicy::BLOCK,
            $this->resolve($id, false),
            'a setting on a series this video is not in decided the answer'
        );
    }

    // ------------------------------------------------------------ categories

    public function testACategoryAllowsTheVideosInIt(): void
    {
        $categoryId = $this->category('Sermons');
        $id = $this->video();
        $this->assign($id, $categoryId);
        $this->setMode('categories', $categoryId, DownloadPolicy::ALLOW);

        self::assertSame(DownloadPolicy::ALLOW, $this->resolve($id, false));
    }

    /**
     * An ANCESTOR's setting applies, which is the half a naive query misses:
     * the video is filed in the child, and nothing on the child says anything.
     */
    public function testAParentCategorySettingReachesTheChild(): void
    {
        $parent = $this->category('Teaching');
        $child = $this->category('Romans', $parent);
        $this->setMode('categories', $parent, DownloadPolicy::ALLOW);

        $id = $this->video();
        $this->assign($id, $child);

        self::assertSame(DownloadPolicy::ALLOW, $this->resolve($id, false));
    }

    public function testTheNearestCategoryWins(): void
    {
        $parent = $this->category('Teaching');
        $child = $this->category('Romans', $parent);
        $this->setMode('categories', $parent, DownloadPolicy::ALLOW);
        $this->setMode('categories', $child, DownloadPolicy::BLOCK);

        $id = $this->video();
        $this->assign($id, $child);

        self::assertSame(DownloadPolicy::BLOCK, $this->resolve($id, false));
    }

    /**
     * Two categories disagreeing blocks, whichever order the join table
     * returns them in. Asserted both ways round, because a rule that happens to
     * hold for one row order is not a rule.
     */
    public function testTwoCategoriesDisagreeingBlocks(): void
    {
        $allowing = $this->category('Public');
        $blocking = $this->category('Restricted');
        $this->setMode('categories', $allowing, DownloadPolicy::ALLOW);
        $this->setMode('categories', $blocking, DownloadPolicy::BLOCK);

        $first = $this->video();
        $this->assign($first, $allowing);
        $this->assign($first, $blocking);

        $second = $this->video();
        $this->assign($second, $blocking);
        $this->assign($second, $allowing);

        self::assertSame(DownloadPolicy::BLOCK, $this->resolve($first, true));
        self::assertSame(DownloadPolicy::BLOCK, $this->resolve($second, true));
    }

    public function testTheSeriesOutranksTheCategoriesAgainstRealRows(): void
    {
        $categoryId = $this->category('Sermons');
        $this->setMode('categories', $categoryId, DownloadPolicy::BLOCK);

        $seriesId = $this->series();
        $this->setMode('series', $seriesId, DownloadPolicy::ALLOW);

        $id = $this->video($seriesId);
        $this->assign($id, $categoryId);

        self::assertSame(DownloadPolicy::ALLOW, $this->resolve($id, false));
    }

    // --------------------------------------------------------------- fixture

    private function resolve(int $videoId, bool $siteDefault): string
    {
        $video = $this->videos->find($videoId);
        self::assertNotNull($video);

        return $this->videos->downloadModeFor($video, $siteDefault);
    }

    private function setMode(string $table, int $id, string $mode): void
    {
        $this->db()->update($table, ['download_mode' => $mode], ['id' => $id]);
    }

    private function assign(int $videoId, int $categoryId): void
    {
        $this->db()->insert('video_categories', [
            'video_id'    => $videoId,
            'category_id' => $categoryId,
        ]);
    }

    private function category(string $name, ?int $parentId = null): int
    {
        return $this->categories->create([
            'name'      => $name,
            'parent_id' => $parentId,
        ])->id;
    }

    private function series(string $title = 'A course'): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('series', [
            'slug'       => 'series-' . $suffix,
            'title'      => $title,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function video(?int $seriesId = null): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => 'A sermon',
            'status'       => 'ready',
            'is_published' => 1,
            'series_id'    => $seriesId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
