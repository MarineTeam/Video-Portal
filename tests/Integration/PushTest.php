<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Plugins\Push\PushCrypto;
use Portal\Plugins\Push\PushRepository;

require_once dirname(__DIR__, 2) . '/plugins/push/src/PushCrypto.php';
require_once dirname(__DIR__, 2) . '/plugins/push/src/PushRepository.php';

/**
 * Subscriptions on a real database.
 *
 * The crypto is tested against the RFC next door and the sending needs a push
 * service, so what only this can answer is the bookkeeping: that a browser
 * re-subscribing does not become two subscribers, and that an endpoint the
 * service has declared dead actually goes away.
 */
final class PushTest extends DatabaseTestCase
{
    private PushRepository $push;

    protected function setUp(): void
    {
        $this->applyPluginSchema();
        $this->truncate(['push_subscriptions', 'pushed_videos', 'videos']);

        $this->push = new PushRepository($this->db());
    }

    // ------------------------------------------------------------ subscribing

    public function testASubscriptionIsStored(): void
    {
        self::assertTrue($this->push->store(...[...$this->subscription(), null]));
        self::assertSame(1, $this->push->count());
    }

    /**
     * A browser re-subscribes on its own schedule — after a permission change,
     * a service worker update, or because the push service rotated the
     * endpoint. Reading first and then inserting is exactly what turns that
     * into two rows and sends every notification twice.
     */
    public function testSubscribingTwiceWithTheSameEndpointIsOneSubscriber(): void
    {
        [$endpoint, $key, $auth] = $this->subscription();

        $this->push->store($endpoint, $key, $auth, null);
        $this->push->store($endpoint, $key, $auth, null);

        self::assertSame(1, $this->push->count());
    }

    /**
     * A re-subscription can carry new keys for the same endpoint. Keeping the
     * old pair would leave a row that looks healthy and encrypts to something
     * the browser cannot read.
     */
    public function testResubscribingRefreshesTheKeys(): void
    {
        [$endpoint, $key, $auth] = $this->subscription();
        $this->push->store($endpoint, $key, $auth, null);

        [, $newKey, $newAuth] = $this->subscription();
        $this->push->store($endpoint, $newKey, $newAuth, null);

        $row = $this->push->all()[0];
        self::assertSame($newKey, $row['p256dh']);
        self::assertSame($newAuth, $row['auth_secret']);
    }

    public function testUnsubscribingRemovesIt(): void
    {
        [$endpoint, $key, $auth] = $this->subscription();
        $this->push->store($endpoint, $key, $auth, null);

        $this->push->forget($endpoint);

        self::assertSame(0, $this->push->count());
    }

    // ------------------------------------------------------------- refusals

    /**
     * Checked when it arrives rather than when it is sent. A subscription with
     * a malformed key is one every future run picks up, fails on, and counts
     * as a failure — so it is refused at the door, where there is still
     * somebody to tell.
     */
    public function testASubscriptionThatCouldNeverBeDeliveredToIsRefused(): void
    {
        $valid = $this->subscription();

        // Key of the wrong length.
        self::assertFalse($this->push->store($valid[0], PushCrypto::base64url(random_bytes(32)), $valid[2], null));

        // Auth secret of the wrong length.
        self::assertFalse($this->push->store($valid[0], $valid[1], PushCrypto::base64url(random_bytes(8)), null));

        // No endpoint at all.
        self::assertFalse($this->push->store('', $valid[1], $valid[2], null));

        self::assertSame(0, $this->push->count());
    }

    /** A push endpoint is always https, and anything else is a way to make this server call somewhere. */
    public function testAnEndpointThatIsNotHttpsIsRefused(): void
    {
        [, $key, $auth] = $this->subscription();

        self::assertFalse($this->push->store('http://push.example.com/x', $key, $auth, null));
        self::assertFalse($this->push->store('file:///etc/passwd', $key, $auth, null));
        self::assertFalse($this->push->store('https://' . str_repeat('a', 600), $key, $auth, null));
    }

    // ------------------------------------------------------------- failures

    /**
     * A 404 or 410 from a push service is definitive: that endpoint will never
     * work again. Counting it would spend five more runs on a browser that has
     * been uninstalled.
     */
    public function testASubscriptionTheServiceDeclaredDeadIsRemovedAtOnce(): void
    {
        [$endpoint, $key, $auth] = $this->subscription();
        $this->push->store($endpoint, $key, $auth, null);

        $this->push->drop((int) $this->push->all()[0]['id']);

        self::assertSame(0, $this->push->count());
    }

    public function testRepeatedFailuresEventuallyRetireASubscription(): void
    {
        [$endpoint, $key, $auth] = $this->subscription();
        $this->push->store($endpoint, $key, $auth, null);
        $id = (int) $this->push->all()[0]['id'];

        $dropped = false;
        for ($i = 1; $i <= PushRepository::FAILURES_BEFORE_DROPPING; $i++) {
            $dropped = $this->push->recordFailure($id);
        }

        self::assertTrue($dropped);
        self::assertSame(0, $this->push->count());
    }

    /** One success means not broken, so the count resets rather than decrementing. */
    public function testASuccessForgivesTheFailureHistory(): void
    {
        [$endpoint, $key, $auth] = $this->subscription();
        $this->push->store($endpoint, $key, $auth, null);
        $id = (int) $this->push->all()[0]['id'];

        $this->push->recordFailure($id);
        $this->push->recordFailure($id);
        $this->push->recordSuccess($id);

        self::assertSame(0, (int) $this->push->find($id)['failure_count']);
        self::assertNotNull($this->push->find($id)['last_sent_at']);
    }

    // --------------------------------------------------------- what is pushed

    /**
     * A push service is somebody else's server and the payload passes through
     * it. The title of a members-only video is not theirs to hold, so those are
     * excluded in the query rather than filtered at the last moment.
     */
    public function testMembersOnlyVideosAreNeverPushed(): void
    {
        $public = $this->video();
        $members = $this->video(['member_only' => 1]);
        $hidden = $this->video(['hidden' => 1]);
        $draft = $this->video(['is_published' => 0]);
        $scheduled = $this->video(['published_at' => date('Y-m-d H:i:s', time() + 86400)]);

        $ids = array_map('intval', array_column($this->push->unpushedVideos(50), 'id'));

        self::assertContains($public, $ids);
        self::assertNotContains($members, $ids, 'a members-only title would be handed to a push service');
        self::assertNotContains($hidden, $ids);
        self::assertNotContains($draft, $ids);
        self::assertNotContains($scheduled, $ids);
    }

    /**
     * The race guard, tested directly. unpushedVideos() already excludes
     * anything claimed, so a single-threaded pass never watches a claim fail —
     * a version that always returned true would pass every other test here.
     */
    public function testAVideoCanOnlyBeClaimedOnce(): void
    {
        $video = $this->video();

        self::assertTrue($this->push->claimVideo($video));
        self::assertFalse($this->push->claimVideo($video));
    }

    public function testAClaimedVideoStopsBeingOffered(): void
    {
        $video = $this->video();
        $this->push->claimVideo($video);

        self::assertNotContains(
            $video,
            array_map('intval', array_column($this->push->unpushedVideos(50), 'id'))
        );
    }

    // ------------------------------------------------------------- cascades

    public function testDeletingAnAccountTakesItsSubscriptions(): void
    {
        $userId = $this->db()->insert('users', [
            'email' => 'push-' . bin2hex(random_bytes(4)) . '@example.com',
            'name' => 'Someone', 'authorized' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        [$endpoint, $key, $auth] = $this->subscription();
        $this->push->store($endpoint, $key, $auth, $userId);

        self::assertCount(1, $this->push->forUser($userId));

        $this->db()->execute('DELETE FROM {users} WHERE id = ?', [$userId]);

        self::assertSame(
            0,
            $this->push->count(),
            'a subscription outliving its account keeps notifying somebody who has left'
        );
    }

    // -------------------------------------------------------------- fixtures

    /**
     * A subscription in the shape a browser sends.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function subscription(): array
    {
        return [
            'https://push.example.com/wpush/' . bin2hex(random_bytes(8)),
            // 65 raw bytes starting 0x04, which is what a P-256 point looks
            // like. Not a real key — nothing here encrypts anything.
            PushCrypto::base64url("\x04" . random_bytes(64)),
            PushCrypto::base64url(random_bytes(16)),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function video(array $overrides = []): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', $overrides + [
            'provider_id' => 'bunny-' . $suffix,
            'slug' => 'video-' . $suffix,
            'title' => 'A video',
            'status' => 'ready',
            'is_published' => 1,
            'hidden' => 0,
            'member_only' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The plugin owns its tables, so they do not exist until it is activated.
     * Applied here directly rather than by driving the plugin manager: what is
     * under test is the repository, not the lifecycle, which the smoke run
     * exercises for real.
     */
    private function applyPluginSchema(): void
    {
        /*
         * Through the real Migrator, not by splitting the file on semicolons.
         *
         * The first version of this did exactly that and broke on the first
         * semicolon inside a SQL comment — which every migration in this
         * project has, because they are written to be read. Splitting SQL
         * correctly is the Migrator's job and it already does it.
         */
        (new \Portal\Migrator($this->db()))->migratePlugin(
            'push',
            dirname(__DIR__, 2) . '/plugins/push/migrations'
        );
    }
}
