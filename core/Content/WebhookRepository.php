<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * Endpoints, and the queue of things waiting to be told to them.
 *
 * Enqueueing is deliberately the only thing that happens during a web request.
 * Delivering is a network call to a server nobody here controls, and doing it
 * inline would put its latency in front of the person who saved the video —
 * on a shared host, one unresponsive endpoint would turn every publish into a
 * timeout. So a request writes a row and returns, and the cron runner does the
 * waiting. On a host with no cron, pseudo-cron fires from ordinary traffic,
 * which makes delivery LATE on a quiet site rather than never.
 *
 * That is a real weakening compared to scheduling, which avoided depending on
 * a runner by evaluating a comparison at query time. An outbound POST cannot
 * be expressed as a comparison; something has to run. It is stated on the
 * settings screen rather than left to be discovered.
 */
final class WebhookRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------- endpoints

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM {webhooks} ORDER BY id');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {webhooks} WHERE id = ?', [$id]);
    }

    public function create(string $url, string $events, string $description): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert('webhooks', [
            'url'         => $url,
            'secret'      => WebhookPolicy::newSecret(),
            'events'      => $events,
            'description' => substr($description, 0, 200),
            'is_active'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    public function delete(int $id): void
    {
        // Deliveries go with it, by cascade. A pending delivery to an endpoint
        // that no longer exists has nowhere to go and no way to be inspected.
        $this->db->execute('DELETE FROM {webhooks} WHERE id = ?', [$id]);
    }

    /**
     * Turn one on or off by hand.
     *
     * Enabling clears the failure count and the reason. Somebody switching an
     * endpoint back on has usually just fixed it, and leaving the old count in
     * place would disable it again after a single further failure.
     */
    public function setActive(int $id, bool $active): void
    {
        $this->db->execute(
            'UPDATE {webhooks}
                SET is_active = ?, updated_at = NOW(),
                    failure_count = IF(?, 0, failure_count),
                    disabled_reason = IF(?, \'\', disabled_reason)
              WHERE id = ?',
            [$active ? 1 : 0, $active ? 1 : 0, $active ? 1 : 0, $id]
        );
    }

    public function rotateSecret(int $id): string
    {
        $secret = WebhookPolicy::newSecret();

        $this->db->execute(
            'UPDATE {webhooks} SET secret = ?, updated_at = NOW() WHERE id = ?',
            [$secret, $id]
        );

        return $secret;
    }

    // ----------------------------------------------------------------- queue

    /**
     * Queue one event for every endpoint that wants it.
     *
     * The payload is built HERE, once, and stored — not rebuilt at delivery
     * time. A retry an hour later would otherwise describe the video as it is
     * then, which may be edited or deleted; a webhook reports that something
     * happened, and what happened does not change while we are trying to send
     * it.
     *
     * @param  array<string, mixed> $data
     * @return int how many deliveries were queued
     */
    public function enqueue(string $event, array $data): int
    {
        $endpoints = $this->db->all(
            'SELECT id, events FROM {webhooks} WHERE is_active = 1'
        );

        if ($endpoints === []) {
            return 0;
        }

        $body = json_encode(
            [
                'event'      => $event,
                'occurredAt' => date('c'),
                'data'       => $data,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($body === false) {
            error_log("Portal: webhook payload for '{$event}' could not be encoded.");

            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $queued = 0;

        foreach ($endpoints as $endpoint) {
            if (!WebhookPolicy::wants((string) $endpoint['events'], $event)) {
                continue;
            }

            $this->db->insert('webhook_deliveries', [
                'webhook_id'      => (int) $endpoint['id'],
                'event'           => $event,
                'payload'         => $body,
                'status'          => 'pending',
                'next_attempt_at' => $now,
                'created_at'      => $now,
            ]);

            $queued++;
        }

        return $queued;
    }

    /**
     * Pending deliveries that are due.
     *
     * Bounded, because this runs inside a web request on a host that will kill
     * it. Ten endpoints at ten seconds each is already longer than most shared
     * hosts allow, so the batch is small and the next tick takes the rest.
     *
     * @return list<array<string, mixed>>
     */
    public function due(int $limit = 10): array
    {
        return $this->db->all(
            'SELECT d.*, w.url, w.secret, w.is_active
               FROM {webhook_deliveries} d
               JOIN {webhooks} w ON w.id = d.webhook_id
              WHERE d.status = \'pending\'
                AND d.next_attempt_at <= NOW()
                AND w.is_active = 1
              ORDER BY d.next_attempt_at, d.id
              LIMIT ' . max(1, min($limit, 50))
        );
    }

    public function markDelivered(int $deliveryId, int $webhookId, int $status): void
    {
        $this->db->transaction(function () use ($deliveryId, $webhookId, $status): void {
            $this->db->execute(
                'UPDATE {webhook_deliveries}
                    SET status = \'delivered\', attempts = attempts + 1,
                        response_status = ?, error = \'\', delivered_at = NOW()
                  WHERE id = ?',
                [$status, $deliveryId]
            );

            // The failure count is reset, not decremented. It answers "how
            // broken is this right now", so one success means not broken.
            $this->db->execute(
                'UPDATE {webhooks}
                    SET failure_count = 0, last_status = ?, last_error = \'\',
                        last_delivered_at = NOW(), updated_at = NOW()
                  WHERE id = ?',
                [$status, $webhookId]
            );
        });
    }

    /**
     * Record a failed attempt and decide what happens next.
     *
     * @return bool whether the endpoint was switched off as a result
     */
    public function markFailed(
        int $deliveryId,
        int $webhookId,
        int $attempts,
        int $status,
        string $error,
        bool $retryable
    ): bool {
        $giveUp = !$retryable || $attempts >= WebhookPolicy::MAX_ATTEMPTS;

        $this->db->execute(
            'UPDATE {webhook_deliveries}
                SET status = ?, attempts = ?, response_status = ?, error = ?,
                    next_attempt_at = ?
              WHERE id = ?',
            [
                $giveUp ? 'failed' : 'pending',
                $attempts,
                $status > 0 ? $status : null,
                substr($error, 0, 500),
                date('Y-m-d H:i:s', time() + WebhookPolicy::backoffSeconds($attempts + 1)),
                $deliveryId,
            ]
        );

        $this->db->execute(
            'UPDATE {webhooks}
                SET failure_count = failure_count + 1, last_status = ?,
                    last_error = ?, updated_at = NOW()
              WHERE id = ?',
            [$status > 0 ? $status : null, substr($error, 0, 500), $webhookId]
        );

        $failures = (int) $this->db->value(
            'SELECT failure_count FROM {webhooks} WHERE id = ?',
            [$webhookId]
        );

        if ($failures < WebhookPolicy::FAILURES_BEFORE_DISABLING) {
            return false;
        }

        /*
         * Switched off with a reason recorded. An endpoint that quietly stopped
         * being tried looks exactly like one that never worked, and the admin
         * screen would show a row that does nothing and explains nothing.
         */
        $this->db->execute(
            'UPDATE {webhooks}
                SET is_active = 0, disabled_reason = ?, updated_at = NOW()
              WHERE id = ?',
            [
                sprintf(
                    'Switched off automatically after %d failures in a row. Last error: %s',
                    $failures,
                    substr($error, 0, 200)
                ),
                $webhookId,
            ]
        );

        return true;
    }

    // ------------------------------------------------------------- reporting

    /**
     * Recent attempts for one endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function recentDeliveries(int $webhookId, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT id, event, status, attempts, response_status, error, created_at, delivered_at
               FROM {webhook_deliveries}
              WHERE webhook_id = ?
              ORDER BY id DESC
              LIMIT ' . max(1, min($limit, 100)),
            [$webhookId]
        );
    }

    public function pendingCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {webhook_deliveries} WHERE status = \'pending\''
        );
    }

    /**
     * Forget old attempts.
     *
     * Delivered rows are history nobody reads after a week; failed ones are
     * kept longer because they are the ones somebody comes looking for, and
     * they come looking days later.
     *
     * @return int rows removed
     */
    public function prune(): int
    {
        return $this->db->execute(
            'DELETE FROM {webhook_deliveries}
              WHERE (status = \'delivered\' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
                 OR (status = \'failed\'    AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY))'
        );
    }

    // ------------------------------------------------------- publish ledger

    /**
     * Videos that are visible now and have never been reported.
     *
     * The same question the announcement job asks, and for the same reason:
     * there is no publish event to hook, because a scheduled video becomes
     * visible when a comparison starts returning true and no code runs at that
     * moment.
     *
     * @return list<array<string, mixed>>
     */
    public function unreportedPublishedVideos(int $limit = 20): array
    {
        return $this->db->all(
            'SELECT v.id, v.slug, v.title, v.published_at
               FROM {videos} v
          LEFT JOIN {webhook_seen_videos} s ON s.video_id = v.id
              WHERE s.video_id IS NULL
                AND v.deleted_at IS NULL
                AND v.is_published = 1
                AND v.hidden = 0
                AND (v.published_at IS NULL OR v.published_at <= NOW())
                AND (v.unpublish_at IS NULL OR v.unpublish_at > NOW())
              ORDER BY v.id
              LIMIT ' . max(1, min($limit, 100))
        );
    }

    /**
     * Claim a video as reported.
     *
     * Public, and returns whether the claim was won, because that is the whole
     * race guard and it cannot be observed from the caller otherwise — the
     * query above has already excluded anything claimed, so a single-threaded
     * test never watches a claim fail. Pseudo-cron fires from ordinary web
     * requests and two can arrive at once.
     */
    public function claimVideo(int $videoId): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO {webhook_seen_videos} (video_id, seen_at) VALUES (?, NOW())',
            [$videoId]
        ) > 0;
    }
}
