<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\ViewRepository;

/**
 * View counts.
 *
 * The arithmetic is trivial; what is worth testing is that concurrent writes
 * cannot lose a count, that a completion recorded against an already-counted
 * view does not invent a second viewer, and that the window a query string can
 * ask for is bounded.
 */
final class ViewCountTest extends DatabaseTestCase
{
    private ViewRepository $views;

    protected function setUp(): void
    {
        $this->truncate(['video_views', 'videos']);

        $this->views = new ViewRepository($this->db());
    }

    // -------------------------------------------------------------- counting

    public function testRecordingAView(): void
    {
        $id = $this->video('A sermon');

        $this->views->record($id);

        self::assertSame(1, $this->views->totalFor($id));
        self::assertSame(['views' => 1, 'completions' => 0], $this->views->summary());
    }

    /**
     * The upsert, which is the whole concurrency story. Two people finishing
     * the same video at the same moment must not lose a count to a
     * read-then-write.
     */
    public function testViewsAccumulateOnTheSameDay(): void
    {
        $id = $this->video('A sermon');

        $this->views->record($id);
        $this->views->record($id);
        $this->views->record($id);

        self::assertSame(3, $this->views->totalFor($id));
        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {video_views} WHERE video_id = ?', [$id]),
            'A day should be one row however many views it holds.'
        );
    }

    public function testAViewCanBeRecordedAsFinished(): void
    {
        $id = $this->video('A sermon');

        $this->views->record($id, completed: true);

        self::assertSame(['views' => 1, 'completions' => 1], $this->views->summary());
    }

    /**
     * Somebody starts a video and finishes it in the same session. The view was
     * counted when they started; counting another when they finish would report
     * twice the audience.
     */
    public function testACompletionAloneDoesNotAddAView(): void
    {
        $id = $this->video('A sermon');

        $this->views->record($id);
        $this->views->recordCompletion($id);

        self::assertSame(['views' => 1, 'completions' => 1], $this->views->summary());
    }

    public function testACompletionOnADayWithNoViewsStillCounts(): void
    {
        $id = $this->video('A sermon');

        // Started yesterday, finished today. Unusual, and it must not be lost.
        $this->views->recordCompletion($id);

        self::assertSame(['views' => 0, 'completions' => 1], $this->views->summary());
    }

    public function testCountsAreKeptPerVideo(): void
    {
        $first = $this->video('One');
        $second = $this->video('Two');

        $this->views->record($first);
        $this->views->record($first);
        $this->views->record($second);

        self::assertSame(2, $this->views->totalFor($first));
        self::assertSame(1, $this->views->totalFor($second));
    }

    public function testDeletingAVideoTakesItsCountsWithIt(): void
    {
        $id = $this->video('A sermon');
        $this->views->record($id);

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$id]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {video_views}'));
    }

    // --------------------------------------------------------------- windows

    public function testTheWindowExcludesOlderDays(): void
    {
        $id = $this->video('A sermon');

        $this->views->record($id);
        $this->stampDaysAgo($id, 40, views: 5);

        self::assertSame(6, $this->views->totalFor($id), 'totalFor is all time.');
        self::assertSame(1, $this->views->summary(7)['views']);
        self::assertSame(6, $this->views->summary(90)['views']);
    }

    public function testDailyFiguresComeBackOldestFirst(): void
    {
        $id = $this->video('A sermon');

        $this->views->record($id);
        $this->stampDaysAgo($id, 3, views: 2);
        $this->stampDaysAgo($id, 1, views: 4);

        $daily = $this->views->forVideo($id, 30);

        self::assertCount(3, $daily);
        self::assertSame([2, 4, 1], array_column($daily, 'views'));
    }

    /**
     * The window arrives in a query string, so an unbounded INTERVAL is a scan
     * of the whole table anybody can ask for repeatedly.
     */
    public function testThePeriodIsOneOfTheOfferedOnes(): void
    {
        self::assertSame(7, ViewRepository::sanitizePeriod('7'));
        self::assertSame(30, ViewRepository::sanitizePeriod(30));

        self::assertSame(30, ViewRepository::sanitizePeriod('999999'));
        self::assertSame(30, ViewRepository::sanitizePeriod('-1'));
        self::assertSame(30, ViewRepository::sanitizePeriod('all'));
        self::assertSame(30, ViewRepository::sanitizePeriod(null));
    }

    // ------------------------------------------------------------------- top

    public function testTheMostWatchedComeFirst(): void
    {
        $quiet = $this->video('Quiet');
        $popular = $this->video('Popular');

        $this->views->record($quiet);
        for ($i = 0; $i < 5; $i++) {
            $this->views->record($popular);
        }

        $top = $this->views->topVideos();

        self::assertSame('Popular', (string) $top[0]['title']);
        self::assertSame(5, (int) $top[0]['views']);
        self::assertSame('Quiet', (string) $top[1]['title']);
    }

    public function testATrashedVideoIsLeftOut(): void
    {
        $id = $this->video('Deleted later');
        $this->views->record($id);

        $this->db()->execute('UPDATE {videos} SET deleted_at = NOW() WHERE id = ?', [$id]);

        self::assertSame([], $this->views->topVideos());
    }

    public function testAVideoWithNoViewsInTheWindowIsLeftOut(): void
    {
        $id = $this->video('Old news');
        $this->stampDaysAgo($id, 60, views: 9);

        self::assertSame([], $this->views->topVideos(30));
        self::assertCount(1, $this->views->topVideos(90));
    }

    public function testNothingWatchedIsAnEmptyList(): void
    {
        $this->video('Never played');

        self::assertSame([], $this->views->topVideos());
        self::assertSame(['views' => 0, 'completions' => 0], $this->views->summary());
    }

    // --------------------------------------------------------------- fixtures

    /** A day's figures, backdated — there is no other way to age a row. */
    private function stampDaysAgo(int $videoId, int $days, int $views, int $completions = 0): void
    {
        $this->db()->execute(
            'INSERT INTO {video_views} (video_id, day, views, completions)
             VALUES (?, DATE_SUB(CURDATE(), INTERVAL ? DAY), ?, ?)',
            [$videoId, $days, $views, $completions]
        );
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
