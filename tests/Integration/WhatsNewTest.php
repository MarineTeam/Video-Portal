<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Config;
use Portal\Container;
use Portal\Http\Router;
use Portal\Plugins\Hooks;
use Portal\Plugins\PluginManager;
use Portal\Plugins\WhatsNew\VisitTracker;
use Portal\Plugins\WhatsNew\WhatsNewPolicy;

/**
 * The visit marker against a real database.
 *
 * The policy tests beside this one pin what a visit means. What needs a real
 * database is the rolling itself — that the marker moves exactly once when
 * somebody comes back, that it does not move while they are still here, and
 * that two requests arriving together cannot roll it twice and wipe the badges
 * off the page being rendered.
 */
final class WhatsNewTest extends DatabaseTestCase
{
    private PluginManager $manager;
    private VisitTracker $tracker;

    protected function setUp(): void
    {
        $this->truncate(['plugin_migrations', 'plugins', 'videos', 'users']);
        $this->db()->execute('DROP TABLE IF EXISTS {whats_new_visits}');

        Hooks::reset();
        Container::reset();

        $this->manager = new PluginManager(
            $this->db(),
            new Config('/nonexistent-config.php'),
            Hooks::instance(),
            new Router(),
        );

        $result = $this->manager->activate('whats-new');
        self::assertTrue($result['ok'], "Could not activate whats-new: {$result['message']}");

        $this->tracker = new VisitTracker($this->db());
    }

    protected function tearDown(): void
    {
        $this->db()->execute('DROP TABLE IF EXISTS {whats_new_visits}');
        Hooks::reset();
        Container::reset();
    }

    // ---------------------------------------------------------------- rolling

    /**
     * A first visit badges nothing, and records that it happened.
     *
     * Both halves matter: returning null is what stops a new account's whole
     * library being covered in badges, and writing the row is what makes the
     * SECOND visit work.
     */
    public function testAFirstVisitBadgesNothingAndIsRemembered(): void
    {
        $user = $this->user('first@example.com');
        $now = time();

        self::assertNull($this->tracker->markerFor($user, 30, $now));

        $row = $this->db()->first('SELECT marker_at, seen_at FROM {whats_new_visits} WHERE user_id = ?', [$user]);

        self::assertNotNull($row, 'The first visit should have been recorded.');
        self::assertNull($row['marker_at']);
        self::assertSame(date('Y-m-d H:i:s', $now), $row['seen_at']);
    }

    /**
     * The property that keeps badges on the page somebody is reading.
     *
     * Every request during one visit must answer with the same marker. An
     * implementation that stamped the marker on each request would clear the
     * badges on the first reload, which reads as the feature flickering.
     */
    public function testTheMarkerDoesNotMoveWhileSomebodyIsStillHere(): void
    {
        $user = $this->user('staying@example.com');
        $start = time() - 7200;

        $this->tracker->markerFor($user, 30, $start);
        $first = $this->tracker->markerFor($user, 30, $start + WhatsNewPolicy::SESSION_GAP + 60);

        self::assertNotNull($first, 'A second visit should have something to compare against.');

        // Four more requests spread through the same visit.
        foreach ([1, 90, 600, 1500] as $offset) {
            self::assertSame(
                $first,
                $this->tracker->markerFor($user, 30, $start + WhatsNewPolicy::SESSION_GAP + 60 + $offset),
                'The marker moved during a single visit.'
            );
        }
    }

    /** Coming back after a gap moves the marker to the end of the last visit. */
    public function testComingBackRollsTheMarkerToWhenTheyLeft(): void
    {
        $user = $this->user('returning@example.com');

        $monday = time() - (7 * 86400);
        $this->tracker->markerFor($user, 30, $monday);

        // Still on the site a few minutes later; this is where they left.
        $left = $monday + 900;
        $this->tracker->markerFor($user, 30, $left);

        $friday = $monday + (4 * 86400);
        $marker = $this->tracker->markerFor($user, 30, $friday);

        self::assertSame(date('Y-m-d H:i:s', $left), $marker);
    }

    /**
     * Two requests arriving together — a page and its stylesheet — must not
     * both roll.
     *
     * Tested against roll() directly, and that is the point rather than a
     * shortcut. Going through markerFor() twice proves nothing: the second call
     * re-reads the stamp the first one wrote, decides the visit is not new, and
     * never reaches the guard at all — this test passed with the WHERE clause
     * deleted until it was written this way.
     *
     * Two real requests both read the OLD stamp and both decide to roll. The
     * second roll would set the marker to the first one's timestamp, which is
     * NOW, and every badge on the page being rendered would disappear.
     */
    public function testOnlyOneOfTwoSimultaneousRequestsRollsTheMarker(): void
    {
        $user = $this->user('racing@example.com');

        $earlier = time() - 86400;
        $this->tracker->markerFor($user, 30, $earlier);

        $now = time();

        self::assertTrue($this->tracker->roll($user, $now), 'The first request should have rolled.');
        self::assertFalse($this->tracker->roll($user, $now), 'The second request rolled it again.');

        self::assertSame(
            date('Y-m-d H:i:s', $earlier),
            $this->db()->value('SELECT marker_at FROM {whats_new_visits} WHERE user_id = ?', [$user]),
            'The marker should still be the end of the previous visit, not this one.'
        );
    }

    /** And the ordinary path still hands both requests the same answer. */
    public function testBothOfTwoSimultaneousRequestsSeeTheSameMarker(): void
    {
        $user = $this->user('agreeing@example.com');

        $earlier = time() - 86400;
        $this->tracker->markerFor($user, 30, $earlier);

        $now = time();
        $first = $this->tracker->markerFor($user, 30, $now);
        $second = $this->tracker->markerFor($user, 30, $now);

        self::assertSame(date('Y-m-d H:i:s', $earlier), $first);
        self::assertSame($first, $second);
    }

    /** The "still here" stamp is written at most once a minute, not per request. */
    public function testTheStillHereStampIsThrottled(): void
    {
        $user = $this->user('quiet@example.com');

        $start = time() - 86400;
        $this->tracker->markerFor($user, 30, $start);

        $this->tracker->markerFor($user, 30, $start + 5);

        self::assertSame(
            date('Y-m-d H:i:s', $start),
            $this->db()->value('SELECT seen_at FROM {whats_new_visits} WHERE user_id = ?', [$user]),
            'A request five seconds later should not have cost a write.'
        );

        $this->tracker->markerFor($user, 30, $start + WhatsNewPolicy::TOUCH_INTERVAL + 1);

        self::assertSame(
            date('Y-m-d H:i:s', $start + WhatsNewPolicy::TOUCH_INTERVAL + 1),
            $this->db()->value('SELECT seen_at FROM {whats_new_visits} WHERE user_id = ?', [$user])
        );
    }

    // ------------------------------------------------------------- what is new

    /**
     * published_at is null for anything published the moment it was created,
     * which is most of a library. Comparing null to a date drops the row, so a
     * plain comparison would badge nothing on almost every site.
     */
    public function testAVideoWithNoPublishDateIsJudgedByWhenItWasCreated(): void
    {
        $old = $this->video('Last year', createdAt: date('Y-m-d H:i:s', time() - (300 * 86400)));
        $fresh = $this->video('This morning', createdAt: date('Y-m-d H:i:s', time() - 3600));

        $new = $this->tracker->newAmong([$old, $fresh], date('Y-m-d H:i:s', time() - 86400));

        self::assertArrayHasKey($fresh, $new);
        self::assertArrayNotHasKey($old, $new);
    }

    /** An explicit publication date wins over the row's creation date. */
    public function testAnExplicitPublishDateIsWhatCounts(): void
    {
        // Catalogued months ago, published this week: new to a viewer.
        $backdated = $this->video(
            'Catalogued in advance',
            createdAt: date('Y-m-d H:i:s', time() - (200 * 86400)),
            publishedAt: date('Y-m-d H:i:s', time() - 3600),
        );

        $new = $this->tracker->newAmong([$backdated], date('Y-m-d H:i:s', time() - 86400));

        self::assertArrayHasKey($backdated, $new);
    }

    public function testNothingIsNewWhenNothingWasPublished(): void
    {
        $old = $this->video('Last year', createdAt: date('Y-m-d H:i:s', time() - (300 * 86400)));

        self::assertSame([], $this->tracker->newAmong([$old], date('Y-m-d H:i:s', time() - 86400)));
        self::assertSame([], $this->tracker->newAmong([], date('Y-m-d H:i:s', time() - 86400)));
    }

    // ------------------------------------------------------------- lifecycle

    /**
     * Deactivating keeps the markers, so turning the plugin back on carries on
     * rather than treating everybody as a first-time visitor. Uninstalling
     * drops them.
     */
    public function testDeactivatingKeepsTheMarkersAndUninstallingDropsThem(): void
    {
        $user = $this->user('lifecycle@example.com');
        $this->tracker->markerFor($user, 30, time());

        $this->manager->deactivate('whats-new');

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {whats_new_visits}'),
            'Deactivating should not have destroyed anything.'
        );

        $this->manager->uninstall('whats-new');

        self::assertNull(
            $this->db()->value(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'whats_new_visits'"
            ),
            'Uninstalling should have dropped the table.'
        );
    }

    /** Removing an account takes its marker with it — the foreign key, tested. */
    public function testDeletingAnAccountTakesItsMarkerWithIt(): void
    {
        $user = $this->user('leaving@example.com');
        $this->tracker->markerFor($user, 30, time());

        $this->db()->execute('DELETE FROM {users} WHERE id = ?', [$user]);

        self::assertSame(0, (int) $this->db()->value('SELECT COUNT(*) FROM {whats_new_visits}'));
    }

    // --------------------------------------------------------------- fixtures

    private function user(string $email): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('users', [
            'email'      => $email,
            'name'       => 'Test',
            'authorized' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function video(string $title, string $createdAt, ?string $publishedAt = null): int
    {
        $suffix = bin2hex(random_bytes(4));

        return $this->db()->insert('videos', [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'published_at' => $publishedAt,
            'created_at'   => $createdAt,
            'updated_at'   => $createdAt,
        ]);
    }
}
