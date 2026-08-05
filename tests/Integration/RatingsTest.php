<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Container;
use Portal\Http\Router;
use Portal\Plugins\Hooks;
use Portal\Plugins\PluginManager;
use Portal\Plugins\Ratings\RatingPolicy;
use Portal\Plugins\Ratings\RatingRepository;

/**
 * The ratings plugin against a real database.
 *
 * The claims worth a real database are the ones the schema makes rather than
 * the code: that one person cannot vote twice, that the cached total never
 * disagrees with the rows it summarises, and that the lifecycle creates and
 * removes exactly the tables it says it does.
 */
final class RatingsTest extends DatabaseTestCase
{
    private PluginManager $manager;
    private RatingRepository $ratings;

    protected function setUp(): void
    {
        $this->truncate(['plugin_migrations', 'plugins', 'videos', 'users']);
        $this->db()->execute('DROP TABLE IF EXISTS {rating_totals}');
        $this->db()->execute('DROP TABLE IF EXISTS {ratings}');

        Hooks::reset();
        Container::reset();

        $this->manager = new PluginManager(
            $this->db(),
            new Config('/nonexistent-config.php'),
            Hooks::instance(),
            new Router(),
        );

        $result = $this->manager->activate('ratings');
        self::assertTrue($result['ok'], 'Could not activate ratings: ' . $result['message']);

        $this->ratings = new RatingRepository($this->db());
    }

    protected function tearDown(): void
    {
        Hooks::reset();
        Container::reset();
    }

    // ------------------------------------------------------------- lifecycle

    public function testActivatingCreatesItsTables(): void
    {
        self::assertTrue($this->db()->tableExists('ratings'));
        self::assertTrue($this->db()->tableExists('rating_totals'));
    }

    public function testUninstallingTakesThemAway(): void
    {
        $this->manager->uninstall('ratings');

        self::assertFalse($this->db()->tableExists('ratings'));
        self::assertFalse($this->db()->tableExists('rating_totals'));
    }

    /** Deactivating hides the stars; it must never lose the opinions. */
    public function testDeactivatingKeepsEveryRating(): void
    {
        $video = $this->video();
        $this->ratings->rate($video, 'someone@example.com', 4);

        $this->manager->deactivate('ratings');

        self::assertTrue($this->db()->tableExists('ratings'));
        self::assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {ratings}'));
    }

    // ------------------------------------------------------------ one person

    /**
     * The unique index is the whole guarantee. Rating again replaces, and the
     * row count proves it rather than the average happening to look right.
     */
    public function testRatingTwiceReplacesRatherThanAdds(): void
    {
        $video = $this->video();

        $this->ratings->rate($video, 'a@example.com', 2);
        $this->ratings->rate($video, 'a@example.com', 5);

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {ratings} WHERE video_id = ?', [$video])
        );
        self::assertSame(5, $this->ratings->scoreBy($video, 'a@example.com'));
        self::assertSame(['count' => 1, 'sum' => 5, 'average' => 5.0], $this->ratings->forVideo($video));
    }

    /** Identity is the address, so casing must not buy a second vote. */
    public function testTheSamePersonInDifferentCasingIsStillOnePerson(): void
    {
        $video = $this->video();

        $this->ratings->rate($video, 'Mixed@Example.COM', 3);
        $this->ratings->rate($video, 'mixed@example.com', 5);

        self::assertSame(1, $this->ratings->forVideo($video)['count']);
        self::assertSame(5, $this->ratings->scoreBy($video, 'MIXED@EXAMPLE.COM'));
    }

    public function testDifferentPeopleEachCount(): void
    {
        $video = $this->video();

        $this->ratings->rate($video, 'a@example.com', 5);
        $this->ratings->rate($video, 'b@example.com', 3);
        $this->ratings->rate($video, 'c@example.com', 4);

        self::assertSame(['count' => 3, 'sum' => 12, 'average' => 4.0], $this->ratings->forVideo($video));
    }

    /** One person's opinion of two videos is two ratings, not a conflict. */
    public function testOnePersonCanRateEveryVideo(): void
    {
        $first = $this->video();
        $second = $this->video();

        $this->ratings->rate($first, 'a@example.com', 5);
        $this->ratings->rate($second, 'a@example.com', 1);

        self::assertSame(5, $this->ratings->scoreBy($first, 'a@example.com'));
        self::assertSame(1, $this->ratings->scoreBy($second, 'a@example.com'));
    }

    // ---------------------------------------------------------- locked down

    public function testWithChangesOffASecondRatingIsRefusedAndTheFirstStands(): void
    {
        $video = $this->video();

        self::assertTrue($this->ratings->rate($video, 'a@example.com', 2, allowChanges: false));
        self::assertFalse($this->ratings->rate($video, 'a@example.com', 5, allowChanges: false));

        self::assertSame(2, $this->ratings->scoreBy($video, 'a@example.com'));
        self::assertSame(2.0, $this->ratings->forVideo($video)['average']);
    }

    /** Somebody who has not rated yet is unaffected by the setting. */
    public function testWithChangesOffAFirstRatingStillWorks(): void
    {
        $video = $this->video();

        self::assertTrue($this->ratings->rate($video, 'fresh@example.com', 4, allowChanges: false));
        self::assertSame(4, $this->ratings->scoreBy($video, 'fresh@example.com'));
    }

    // --------------------------------------------------------------- removal

    public function testRemovingARatingTakesItOutOfTheAverage(): void
    {
        $video = $this->video();

        $this->ratings->rate($video, 'a@example.com', 5);
        $this->ratings->rate($video, 'b@example.com', 1);
        self::assertSame(3.0, $this->ratings->forVideo($video)['average']);

        $this->ratings->remove($video, 'b@example.com');

        self::assertSame(['count' => 1, 'sum' => 5, 'average' => 5.0], $this->ratings->forVideo($video));
        self::assertNull($this->ratings->scoreBy($video, 'b@example.com'));
    }

    /**
     * Removing the last one leaves no totals row at all.
     *
     * A row of zeroes would be a second way to say "unrated", and the two would
     * eventually disagree.
     */
    public function testRemovingTheLastRatingLeavesNoTotalsRow(): void
    {
        $video = $this->video();

        $this->ratings->rate($video, 'a@example.com', 5);
        $this->ratings->remove($video, 'a@example.com');

        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {rating_totals} WHERE video_id = ?', [$video])
        );
        self::assertSame(['count' => 0, 'sum' => 0, 'average' => 0.0], $this->ratings->forVideo($video));
    }

    public function testRemovingARatingThatWasNeverGivenIsHarmless(): void
    {
        $video = $this->video();
        $this->ratings->rate($video, 'a@example.com', 4);

        $this->ratings->remove($video, 'stranger@example.com');

        self::assertSame(['count' => 1, 'sum' => 4, 'average' => 4.0], $this->ratings->forVideo($video));
    }

    // ----------------------------------------------------------- the cache

    /**
     * The cached total is derived data, and the only thing that makes it
     * trustworthy is that it is rebuilt from the rows rather than adjusted.
     * Corrupt it behind the repository's back and one more write must repair
     * it — an incrementing implementation would carry the damage forever.
     */
    public function testTheCachedTotalIsRebuiltRatherThanAdjusted(): void
    {
        $video = $this->video();

        $this->ratings->rate($video, 'a@example.com', 5);
        $this->ratings->rate($video, 'b@example.com', 3);

        $this->db()->execute(
            'UPDATE {rating_totals} SET vote_count = 99, score_sum = 400, average = 4.04 WHERE video_id = ?',
            [$video]
        );

        $this->ratings->rate($video, 'c@example.com', 4);

        self::assertSame(['count' => 3, 'sum' => 12, 'average' => 4.0], $this->ratings->forVideo($video));
    }

    public function testRecountRepairsTheCacheWithoutAnyoneRating(): void
    {
        $video = $this->video();
        $this->ratings->rate($video, 'a@example.com', 2);

        $this->db()->execute('DELETE FROM {rating_totals} WHERE video_id = ?', [$video]);
        self::assertSame(0, $this->ratings->forVideo($video)['count']);

        $this->ratings->recount($video);

        self::assertSame(['count' => 1, 'sum' => 2, 'average' => 2.0], $this->ratings->forVideo($video));
    }

    // ---------------------------------------------------------- listings

    public function testTotalsForManyVideosComeBackInOneQuery(): void
    {
        $rated = $this->video();
        $unrated = $this->video();

        $this->ratings->rate($rated, 'a@example.com', 5);

        $before = $this->db()->queryCount();
        $totals = $this->ratings->forVideos([$rated, $unrated]);
        $after = $this->db()->queryCount();

        self::assertSame(1, $after - $before, 'Totals for a listing must not be a query per card.');

        self::assertSame(5.0, $totals[$rated]['average']);
        // Asked about, so answered — an unrated video is a zero, not a gap the
        // caller has to remember to check for.
        self::assertSame(['count' => 0, 'sum' => 0, 'average' => 0.0], $totals[$unrated]);
    }

    public function testNoVideosCostsNoQueries(): void
    {
        $before = $this->db()->queryCount();
        self::assertSame([], $this->ratings->forVideos([]));
        self::assertSame($before, $this->db()->queryCount());
    }

    // ------------------------------------------------------------ ranking

    /**
     * The leaderboard, against real rows.
     *
     * The pure ranking function is tested next door; what this proves is that
     * the SQL expression ordering the table computes the same thing. They are
     * written twice, in two languages, and nothing but a test like this would
     * notice them drifting apart.
     */
    public function testTheLeaderboardWeightsAgainstASingleVote(): void
    {
        // A body of ordinary content first, so the site average means what it
        // is supposed to mean. This is not scaffolding: the prior is "what a
        // typical video here scores", and on a site where everything scores 4.8
        // a single 5.0 genuinely is near-typical and should not be pushed down
        // much. The weighting is relative to the library, not absolute.
        for ($v = 0; $v < 3; $v++) {
            $ordinary = $this->video('Ordinary ' . $v);
            for ($i = 0; $i < 4; $i++) {
                $this->ratings->rate($ordinary, "ordinary{$v}-{$i}@example.com", 3);
            }
        }

        $lonely = $this->video('One perfect rating');
        $popular = $this->video('Widely liked');

        $this->ratings->rate($lonely, 'solo@example.com', 5);

        for ($i = 0; $i < 20; $i++) {
            $this->ratings->rate($popular, "person{$i}@example.com", $i < 16 ? 5 : 4);
        }

        $board = $this->ratings->leaderboard();

        self::assertSame('Widely liked', (string) $board[0]['title']);
        self::assertSame('One perfect rating', (string) $board[1]['title']);

        // And the plain averages really do go the other way, so the ordering
        // above is the weighting doing something rather than a coincidence.
        self::assertGreaterThan(
            (float) $board[0]['average'],
            (float) $board[1]['average']
        );
    }

    public function testTheLeaderboardMatchesTheRankingFunction(): void
    {
        $video = $this->video('Somewhere in the middle');
        $this->ratings->rate($video, 'a@example.com', 4);
        $this->ratings->rate($video, 'b@example.com', 3);

        // A second, badly rated video, so the site average sits well away from
        // this one's. Without it the prior and the average coincide and the
        // comparison below would hold for a plain average too — which is
        // exactly how a test ends up asserting nothing.
        $other = $this->video('Poorly rated');
        foreach (['c', 'd', 'e', 'f'] as $who) {
            $this->ratings->rate($other, $who . '@example.com', 1);
        }

        $site = $this->ratings->siteAverage();
        $row = $this->ratings->leaderboard()[0];

        self::assertSame('Somewhere in the middle', (string) $row['title']);

        self::assertEqualsWithDelta(
            RatingPolicy::ranking(2, 7, $site),
            (float) $row['ranking'],
            0.0001,
            'The SQL ranking and the PHP ranking must agree.'
        );

        self::assertNotEqualsWithDelta(
            (float) $row['average'],
            (float) $row['ranking'],
            0.1,
            'With only two votes the ranking must differ visibly from the plain average.'
        );
    }

    public function testAnUnratedVideoIsNotOnTheLeaderboard(): void
    {
        $this->video('Nobody rated this');
        $rated = $this->video('This one was rated');
        $this->ratings->rate($rated, 'a@example.com', 3);

        $board = $this->ratings->leaderboard();

        self::assertCount(1, $board);
        self::assertSame('This one was rated', (string) $board[0]['title']);
    }

    /** With nothing rated, the prior is the midpoint rather than 0 or 5. */
    public function testTheSiteAverageStartsAtTheMidpoint(): void
    {
        self::assertSame(3.0, $this->ratings->siteAverage());
        self::assertSame(0, $this->ratings->ratedVideoCount());
    }

    public function testTheSiteAverageSpansEveryVideo(): void
    {
        $first = $this->video();
        $second = $this->video();

        $this->ratings->rate($first, 'a@example.com', 5);
        $this->ratings->rate($first, 'b@example.com', 5);
        $this->ratings->rate($second, 'c@example.com', 2);

        self::assertSame(4.0, $this->ratings->siteAverage());
        self::assertSame(2, $this->ratings->ratedVideoCount());
    }

    // -------------------------------------------------------------- deletion

    public function testDeletingAVideoTakesItsRatingsAndItsTotalWithIt(): void
    {
        $video = $this->video();
        $this->ratings->rate($video, 'a@example.com', 5);

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$video]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {ratings}'));
        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {rating_totals}'));
    }

    // --------------------------------------------------------------- fixtures

    private function video(string $title = 'A video'): int
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
