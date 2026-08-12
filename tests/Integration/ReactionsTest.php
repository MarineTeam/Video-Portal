<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Container;
use Portal\Http\Router;
use Portal\Plugins\Hooks;
use Portal\Plugins\PluginManager;
use Portal\Plugins\Reactions\ReactionRepository;

/**
 * The reactions plugin against a real database.
 *
 * The claims worth a real database are the ones the SCHEMA makes rather than
 * the code — above all the unique key, which is where the difference between a
 * reaction and a rating actually lives:
 *
 *   ratings: UNIQUE(video_id, rater_email)        -- one answer, replaced
 *   reactions: UNIQUE(video_id, reactor_email, kind) -- one of each, kept
 */
final class ReactionsTest extends DatabaseTestCase
{
    private PluginManager $manager;
    private ReactionRepository $reactions;

    protected function setUp(): void
    {
        $this->truncate(['plugin_migrations', 'plugins', 'videos', 'users']);
        $this->db()->execute('DROP TABLE IF EXISTS {reactions}');

        Hooks::reset();
        Container::reset();

        $this->manager = new PluginManager(
            $this->db(),
            new Config('/nonexistent-config.php'),
            Hooks::instance(),
            new Router(),
        );

        $result = $this->manager->activate('reactions');
        self::assertTrue($result['ok'], 'Could not activate reactions: ' . $result['message']);

        $this->reactions = new ReactionRepository($this->db());
    }

    protected function tearDown(): void
    {
        $this->db()->execute('DROP TABLE IF EXISTS {reactions}');
        Hooks::reset();
        Container::reset();
    }

    /**
     * THE design, as a test.
     *
     * A person may say several things about one video, because "this moved me"
     * and "I am praying about this" are not points on one scale. A rating
     * replaces; a reaction accumulates.
     */
    public function testOnePersonMayLeaveSeveralKindsOnOneVideo(): void
    {
        $video = $this->makeVideo();

        $this->reactions->toggle($video, null, 'someone@example.com', 'amen');
        $this->reactions->toggle($video, null, 'someone@example.com', 'thankful');

        $counts = $this->reactions->forVideo($video);

        self::assertSame(1, $counts['amen']);
        self::assertSame(1, $counts['thankful']);
        self::assertSame(0, $counts['helpful']);

        $yours = $this->reactions->byPerson($video, 'someone@example.com');
        sort($yours);
        self::assertSame(['amen', 'thankful'], $yours);
    }

    /**
     * Pressing the same button twice takes it back.
     *
     * The button is the only thing showing the state, so a person who reacted
     * by mistake has nowhere else to go. Pressing again is the obvious gesture
     * and it is the one that works.
     */
    public function testPressingTheSameKindAgainRemovesIt(): void
    {
        $video = $this->makeVideo();

        self::assertTrue($this->reactions->toggle($video, null, 'someone@example.com', 'amen'));
        self::assertFalse($this->reactions->toggle($video, null, 'someone@example.com', 'amen'));

        self::assertSame(0, $this->reactions->forVideo($video)['amen']);
        self::assertSame([], $this->reactions->byPerson($video, 'someone@example.com'));
    }

    /**
     * The unique key, not a read-then-write.
     *
     * Two tabs or one impatient double-click is all a read-then-write needs to
     * record the same reaction twice. Written directly here, because the
     * repository's toggle deliberately cannot produce this state — the point is
     * that the DATABASE refuses it.
     */
    public function testTheDatabaseRefusesTheSameReactionTwice(): void
    {
        $video = $this->makeVideo();

        $this->reactions->toggle($video, null, 'someone@example.com', 'amen');

        $this->db()->execute(
            'INSERT IGNORE INTO {reactions} (video_id, user_id, reactor_email, kind, created_at)
             VALUES (?, NULL, ?, ?, NOW())',
            [$video, 'someone@example.com', 'amen']
        );

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {reactions} WHERE video_id = ?', [$video]),
            'The unique key did not hold.'
        );
    }

    public function testDifferentPeopleEachCount(): void
    {
        $video = $this->makeVideo();

        $this->reactions->toggle($video, null, 'one@example.com', 'amen');
        $this->reactions->toggle($video, null, 'two@example.com', 'amen');

        self::assertSame(2, $this->reactions->forVideo($video)['amen']);
    }

    public function testAnUnknownKindIsNeverStored(): void
    {
        $video = $this->makeVideo();

        self::assertFalse($this->reactions->toggle($video, null, 'someone@example.com', 'shrug'));
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {reactions}'),
            'An unrecognised kind reached the table.'
        );
    }

    /** One query for a page, not one per card. */
    public function testCountsForManyVideosComeBackKeyedById(): void
    {
        $one = $this->makeVideo();
        $two = $this->makeVideo();
        $three = $this->makeVideo();

        $this->reactions->toggle($one, null, 'a@example.com', 'amen');
        $this->reactions->toggle($one, null, 'b@example.com', 'amen');
        $this->reactions->toggle($two, null, 'a@example.com', 'helpful');

        $batch = $this->reactions->forVideos([$one, $two, $three]);

        self::assertSame(2, $batch[$one]['amen']);
        self::assertSame(1, $batch[$two]['helpful']);
        self::assertArrayNotHasKey($three, $batch, 'A video with none should be omitted, not empty.');
    }

    /**
     * Deleting a video takes its reactions, by CONSTRAINT.
     *
     * Unlike tags, this table can carry a foreign key — it points at videos and
     * nothing else — so the cascade is the schema's job rather than code's, and
     * this proves the constraint actually shipped.
     */
    public function testDeletingAVideoRemovesItsReactions(): void
    {
        $video = $this->makeVideo();
        $this->reactions->toggle($video, null, 'someone@example.com', 'amen');

        $this->db()->execute('DELETE FROM {videos} WHERE id = ?', [$video]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {reactions}'));
    }

    /**
     * The lifecycle promise: deactivating keeps the data, uninstalling drops it.
     *
     * An admin who wants the buttons gone but the responses kept reaches for
     * deactivate, and reactivating must bring the history back.
     */
    public function testDeactivatingKeepsTheRowsAndUninstallingDropsTheTable(): void
    {
        $video = $this->makeVideo();
        $this->reactions->toggle($video, null, 'someone@example.com', 'amen');

        $this->manager->deactivate('reactions');

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {reactions}'),
            'Deactivating destroyed data it should have kept.'
        );

        self::assertTrue($this->db()->tableExists('reactions'));

        $this->manager->uninstall('reactions');

        self::assertFalse(
            $this->db()->tableExists('reactions'),
            'Uninstalling left its table behind.'
        );
    }

    // ------------------------------------------------------------- fixtures

    private function makeVideo(): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('videos', [
            'provider'     => 'bunny',
            'provider_id'  => bin2hex(random_bytes(8)),
            'slug'         => 'video-' . bin2hex(random_bytes(4)),
            'title'        => 'A video',
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
