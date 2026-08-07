<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Content\WebhookPolicy;
use Portal\Content\WebhookRepository;

/**
 * The queue, on a real database.
 *
 * The policy is tested next door and the delivery itself needs a network, so
 * what only this can answer is whether the bookkeeping between them holds: who
 * gets queued, what happens to a failed attempt, and whether an endpoint that
 * has genuinely gone away eventually stops being tried.
 */
final class WebhookTest extends DatabaseTestCase
{
    private WebhookRepository $webhooks;

    protected function setUp(): void
    {
        $this->truncate(['webhook_deliveries', 'webhooks', 'webhook_seen_videos', 'videos']);

        $this->webhooks = new WebhookRepository($this->db());
    }

    // -------------------------------------------------------------- queueing

    public function testAnEventIsQueuedForAnEndpointThatWantsIt(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', 'Everything');

        self::assertSame(1, $this->webhooks->enqueue('video.published', ['id' => 7]));

        $rows = $this->webhooks->recentDeliveries($id);
        self::assertCount(1, $rows);
        self::assertSame('video.published', $rows[0]['event']);
        self::assertSame('pending', $rows[0]['status']);
    }

    public function testAnEndpointOnlyGetsTheEventsItSubscribedTo(): void
    {
        $wanted = $this->webhooks->create('https://example.com/a', 'share.created', '');
        $other = $this->webhooks->create('https://example.com/b', 'video.deleted', '');

        self::assertSame(1, $this->webhooks->enqueue('share.created', ['id' => 'abc']));

        self::assertCount(1, $this->webhooks->recentDeliveries($wanted));
        self::assertCount(0, $this->webhooks->recentDeliveries($other));
    }

    public function testASwitchedOffEndpointIsNotQueuedFor(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->setActive($id, false);

        self::assertSame(0, $this->webhooks->enqueue('video.published', ['id' => 7]));
        self::assertCount(0, $this->webhooks->recentDeliveries($id));
    }

    /**
     * The commonest state by far, and the one that has to be cheap: no
     * endpoints at all. Every event on the site calls this.
     */
    public function testWithNoEndpointsNothingIsQueuedAndNothingCosts(): void
    {
        $before = $this->db()->queryCount();

        self::assertSame(0, $this->webhooks->enqueue('video.published', ['id' => 7]));

        self::assertLessThanOrEqual(
            1,
            $this->db()->queryCount() - $before,
            'the no-endpoint path must be one indexed read, not a scan per event'
        );
    }

    /**
     * The payload is built at enqueue time and stored.
     *
     * A body assembled at DELIVERY time would describe the video as it is when
     * the request finally goes out — which on a retry an hour later may be
     * edited, or deleted. A webhook reports that something happened, and what
     * happened does not change while we are trying to send it.
     */
    public function testThePayloadIsFrozenWhenTheEventHappens(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.updated', ['id' => 4, 'title' => 'The original title']);

        $payload = (string) $this->db()->value(
            'SELECT payload FROM {webhook_deliveries} WHERE webhook_id = ?',
            [$id]
        );

        $decoded = json_decode($payload, true);

        self::assertIsArray($decoded);
        self::assertSame('video.updated', $decoded['event']);
        self::assertSame('The original title', $decoded['data']['title']);
        self::assertArrayHasKey('occurredAt', $decoded);
    }

    // ------------------------------------------------------------------ due

    public function testOnlyDueDeliveriesComeBack(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);

        self::assertCount(1, $this->webhooks->due());

        // Pushed into the future, as a failed attempt would do.
        $this->db()->execute(
            'UPDATE {webhook_deliveries} SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)'
        );

        self::assertCount(0, $this->webhooks->due());
    }

    /**
     * Switching an endpoint off has to stop deliveries already in the queue,
     * not merely stop new ones being added. The opposite would mean an admin
     * turning off a misbehaving endpoint and watching it keep firing.
     */
    public function testSwitchingAnEndpointOffStopsWorkAlreadyQueued(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);

        self::assertCount(1, $this->webhooks->due());

        $this->webhooks->setActive($id, false);

        self::assertCount(0, $this->webhooks->due());
    }

    // ------------------------------------------------------------ outcomes

    public function testASuccessfulDeliveryClearsTheFailureCount(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);
        $delivery = (int) $this->webhooks->due()[0]['id'];

        $this->webhooks->markFailed($delivery, $id, 1, 500, 'boom', retryable: true);
        self::assertSame(1, (int) $this->webhooks->find($id)['failure_count']);

        $this->webhooks->markDelivered($delivery, $id, 200);

        $endpoint = $this->webhooks->find($id);
        self::assertSame(0, (int) $endpoint['failure_count'], 'one success means not broken');
        self::assertSame(200, (int) $endpoint['last_status']);
        self::assertNotNull($endpoint['last_delivered_at']);

        self::assertSame('delivered', $this->webhooks->recentDeliveries($id)[0]['status']);
    }

    public function testAFailedAttemptIsScheduledForLater(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);
        $delivery = (int) $this->webhooks->due()[0]['id'];

        $this->webhooks->markFailed($delivery, $id, 1, 503, 'unavailable', retryable: true);

        $row = $this->webhooks->recentDeliveries($id)[0];
        self::assertSame('pending', $row['status'], 'a retryable failure stays in the queue');
        self::assertSame(1, (int) $row['attempts']);

        // And it is not due again immediately, or the retry would be a spin.
        self::assertCount(0, $this->webhooks->due());
    }

    public function testItGivesUpAfterTheLastAttempt(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);
        $delivery = (int) $this->webhooks->recentDeliveries($id)[0]['id'];

        $this->webhooks->markFailed(
            $delivery,
            $id,
            WebhookPolicy::MAX_ATTEMPTS,
            503,
            'still unavailable',
            retryable: true
        );

        self::assertSame('failed', $this->webhooks->recentDeliveries($id)[0]['status']);
    }

    /**
     * A 404 means the receiver understood and refused. Trying five more times
     * changes nothing at either end.
     */
    public function testARefusalIsNotRetriedAtAll(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);
        $delivery = (int) $this->webhooks->recentDeliveries($id)[0]['id'];

        $this->webhooks->markFailed($delivery, $id, 1, 404, 'no such hook', retryable: false);

        self::assertSame('failed', $this->webhooks->recentDeliveries($id)[0]['status']);
    }

    /**
     * An endpoint that has gone away permanently would otherwise be retried
     * forever, and every attempt is a request this site waits on.
     */
    public function testAnEndpointThatKeepsFailingIsSwitchedOff(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');

        $disabled = false;
        for ($i = 1; $i <= WebhookPolicy::FAILURES_BEFORE_DISABLING; $i++) {
            $this->webhooks->enqueue('video.published', ['id' => $i]);
            $delivery = (int) $this->webhooks->recentDeliveries($id, 1)[0]['id'];

            $disabled = $this->webhooks->markFailed($delivery, $id, 1, 500, 'boom', retryable: true);
        }

        self::assertTrue($disabled, 'the last failure should report that it switched the endpoint off');

        $endpoint = $this->webhooks->find($id);
        self::assertSame(0, (int) $endpoint['is_active']);
        self::assertStringContainsString(
            'Switched off automatically',
            (string) $endpoint['disabled_reason'],
            'an endpoint that quietly stopped being tried looks exactly like one that never worked'
        );
    }

    /** Switching it back on is usually somebody who has just fixed it. */
    public function testTurningAnEndpointBackOnForgivesItsHistory(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);
        $delivery = (int) $this->webhooks->recentDeliveries($id)[0]['id'];
        $this->webhooks->markFailed($delivery, $id, 1, 500, 'boom', retryable: true);

        $this->webhooks->setActive($id, false);
        $this->webhooks->setActive($id, true);

        $endpoint = $this->webhooks->find($id);
        self::assertSame(0, (int) $endpoint['failure_count']);
        self::assertSame('', (string) $endpoint['disabled_reason']);
    }

    // ---------------------------------------------------------- the ledger

    /**
     * The race guard, tested directly.
     *
     * unreportedPublishedVideos() already excludes anything claimed, so a
     * single-threaded pass never watches a claim fail — a version of this that
     * always returned true would pass every other test in this file. Pseudo-cron
     * fires from ordinary web requests and two can arrive together.
     */
    public function testAVideoCanOnlyBeClaimedOnce(): void
    {
        $video = $this->publishedVideo();

        self::assertTrue($this->webhooks->claimVideo($video));
        self::assertFalse($this->webhooks->claimVideo($video), 'the second caller must lose');
    }

    public function testOnlyVisibleVideosAreReported(): void
    {
        $visible = $this->publishedVideo();
        $draft = $this->publishedVideo(['is_published' => 0]);
        $hidden = $this->publishedVideo(['hidden' => 1]);
        $scheduled = $this->publishedVideo(['published_at' => date('Y-m-d H:i:s', time() + 86400)]);
        $ended = $this->publishedVideo(['unpublish_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $reported = array_column($this->webhooks->unreportedPublishedVideos(), 'id');
        $reported = array_map('intval', $reported);

        self::assertContains($visible, $reported);
        self::assertNotContains($draft, $reported);
        self::assertNotContains($hidden, $reported);
        self::assertNotContains($scheduled, $reported, 'a video whose date has not arrived is not published');
        self::assertNotContains($ended, $reported);
    }

    public function testAClaimedVideoStopsBeingReported(): void
    {
        $video = $this->publishedVideo();

        self::assertContains(
            $video,
            array_map('intval', array_column($this->webhooks->unreportedPublishedVideos(), 'id'))
        );

        $this->webhooks->claimVideo($video);

        self::assertNotContains(
            $video,
            array_map('intval', array_column($this->webhooks->unreportedPublishedVideos(), 'id'))
        );
    }

    // ---------------------------------------------------------------- pruning

    public function testOldRecordsAreForgottenAndRecentOnesAreNot(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');

        $this->webhooks->enqueue('video.published', ['id' => 1]);
        $this->webhooks->enqueue('video.published', ['id' => 2]);
        $rows = $this->webhooks->recentDeliveries($id);

        // One delivered a fortnight ago, one delivered just now.
        $this->db()->execute(
            'UPDATE {webhook_deliveries}
                SET status = \'delivered\', created_at = DATE_SUB(NOW(), INTERVAL 14 DAY)
              WHERE id = ?',
            [(int) $rows[0]['id']]
        );
        $this->db()->execute(
            'UPDATE {webhook_deliveries} SET status = \'delivered\' WHERE id = ?',
            [(int) $rows[1]['id']]
        );

        self::assertSame(1, $this->webhooks->prune());
        self::assertCount(1, $this->webhooks->recentDeliveries($id));
    }

    /** Failures are kept longer, because they are what somebody comes looking for. */
    public function testAFortnightOldFailureIsKept(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);

        $this->db()->execute(
            'UPDATE {webhook_deliveries}
                SET status = \'failed\', created_at = DATE_SUB(NOW(), INTERVAL 14 DAY)'
        );

        self::assertSame(0, $this->webhooks->prune());
        self::assertCount(1, $this->webhooks->recentDeliveries($id));
    }

    // -------------------------------------------------------------- endpoints

    public function testDeletingAnEndpointTakesItsHistoryWithIt(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $this->webhooks->enqueue('video.published', ['id' => 1]);

        $this->webhooks->delete($id);

        self::assertNull($this->webhooks->find($id));
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {webhook_deliveries}'),
            'a pending delivery to an endpoint that no longer exists has nowhere to go'
        );
    }

    public function testEachEndpointGetsItsOwnSecret(): void
    {
        $first = $this->webhooks->create('https://example.com/a', '*', '');
        $second = $this->webhooks->create('https://example.com/b', '*', '');

        self::assertNotSame(
            $this->webhooks->find($first)['secret'],
            $this->webhooks->find($second)['secret'],
            'one secret across endpoints means one leak compromises all of them'
        );
    }

    public function testRotatingReplacesTheSecret(): void
    {
        $id = $this->webhooks->create('https://example.com/hook', '*', '');
        $before = (string) $this->webhooks->find($id)['secret'];

        $returned = $this->webhooks->rotateSecret($id);

        self::assertNotSame($before, $returned);
        self::assertSame($returned, (string) $this->webhooks->find($id)['secret']);
    }

    // --------------------------------------------------------------- fixtures

    /** @param array<string, mixed> $overrides */
    private function publishedVideo(array $overrides = []): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', $overrides + [
            'provider_id'  => 'bunny-' . $suffix,
            'slug'         => 'video-' . $suffix,
            'title'        => 'A video',
            'status'       => 'ready',
            'is_published' => 1,
            'hidden'       => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
}
