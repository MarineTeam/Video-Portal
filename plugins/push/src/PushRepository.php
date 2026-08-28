<?php

declare(strict_types=1);

namespace Portal\Plugins\Push;

use Portal\Db;

/**
 * Subscriptions, and which videos have been pushed.
 *
 * Everything a browser hands over is opaque here — the endpoint, the key and
 * the auth secret all come from the push service — so this stores what it was
 * given and validates only the shapes it must be able to rely on later.
 */
final class PushRepository
{
    /** Consecutive failures before a subscription is dropped. */
    public const FAILURES_BEFORE_DROPPING = 5;

    public function __construct(private readonly Db $db)
    {
    }

    // ---------------------------------------------------------------- writes

    /**
     * Record a subscription, or refresh one already known.
     *
     * An upsert rather than a check-then-insert. A browser re-subscribes on its
     * own schedule — after a permission change, a service worker update, or
     * because the push service rotated the endpoint — and posts the same
     * endpoint again; reading first is what duplicates it and sends every
     * notification twice.
     *
     * The keys are refreshed too. A re-subscription can carry new ones for the
     * same endpoint, and keeping the old pair would leave a row that looks
     * healthy and encrypts to nothing readable.
     */
    public function store(string $endpoint, string $p256dh, string $auth, ?int $userId): bool
    {
        if (!self::isUsable($endpoint, $p256dh, $auth)) {
            return false;
        }

        $this->db->execute(
            'INSERT INTO {push_subscriptions}
                (endpoint, p256dh, auth_secret, user_id, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                p256dh = VALUES(p256dh),
                auth_secret = VALUES(auth_secret),
                user_id = VALUES(user_id),
                failure_count = 0',
            [$endpoint, $p256dh, $auth, $userId]
        );

        return true;
    }

    public function forget(string $endpoint): void
    {
        $this->db->execute('DELETE FROM {push_subscriptions} WHERE endpoint = ?', [$endpoint]);
    }

    /**
     * A subscription the push service says is gone.
     *
     * Deleted outright rather than counted. A 404 or 410 from a push service is
     * definitive — that endpoint will never work again — and retrying it is
     * traffic spent on a browser that has been uninstalled.
     */
    public function drop(int $id): void
    {
        $this->db->execute('DELETE FROM {push_subscriptions} WHERE id = ?', [$id]);
    }

    public function recordSuccess(int $id): void
    {
        $this->db->execute(
            'UPDATE {push_subscriptions} SET failure_count = 0, last_sent_at = NOW() WHERE id = ?',
            [$id]
        );
    }

    /**
     * Record a failure that might be temporary.
     *
     * @return bool whether the subscription was given up on
     */
    public function recordFailure(int $id): bool
    {
        $this->db->execute(
            'UPDATE {push_subscriptions} SET failure_count = failure_count + 1 WHERE id = ?',
            [$id]
        );

        $failures = (int) $this->db->value(
            'SELECT failure_count FROM {push_subscriptions} WHERE id = ?',
            [$id]
        );

        if ($failures < self::FAILURES_BEFORE_DROPPING) {
            return false;
        }

        $this->drop($id);

        return true;
    }

    // ----------------------------------------------------------------- reads

    /** @return list<array<string, mixed>> */
    public function all(int $limit = 500): array
    {
        return $this->db->all(
            'SELECT * FROM {push_subscriptions} ORDER BY id LIMIT ' . max(1, min($limit, 2000))
        );
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {push_subscriptions}');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {push_subscriptions} WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->all('SELECT * FROM {push_subscriptions} WHERE user_id = ?', [$userId]);
    }

    /**
     * The addresses of signed-in people who have a push subscription.
     *
     * For the notification record, which is keyed by email. Subscriptions with
     * no user_id are skipped and that is the right answer rather than a
     * shortcoming: an anonymous browser has no account, so there is no inbox
     * for a row to appear in and nobody who could ever read it.
     *
     * DISTINCT because one person subscribes from every browser they use, and
     * the record is of what they were told, not of how many devices it went to.
     *
     * @return list<string>
     */
    public function subscribedEmails(): array
    {
        $rows = $this->db->all(
            'SELECT DISTINCT u.email
               FROM {push_subscriptions} p
               JOIN {users} u ON u.id = p.user_id
              WHERE p.user_id IS NOT NULL'
        );

        return array_map(static fn (array $r): string => (string) $r['email'], $rows);
    }

    // ------------------------------------------------------- the push ledger

    /**
     * Videos visible now that have never been pushed.
     *
     * @return list<array<string, mixed>>
     */
    public function unpushedVideos(int $limit = 5): array
    {
        return $this->db->all(
            'SELECT v.id, v.slug, v.title
               FROM {videos} v
          LEFT JOIN {pushed_videos} p ON p.video_id = v.id
              WHERE p.video_id IS NULL
                AND v.deleted_at IS NULL
                AND v.is_published = 1
                AND v.hidden = 0
                AND v.member_only = 0
                AND (v.published_at IS NULL OR v.published_at <= NOW())
                AND (v.unpublish_at IS NULL OR v.unpublish_at > NOW())
              ORDER BY v.id
              LIMIT ' . max(1, min($limit, 50))
        );
    }

    /**
     * Claim a video as pushed.
     *
     * Public, and returns whether the claim was won, because that is the whole
     * race guard and it cannot be observed from the caller otherwise — the
     * query above has already excluded anything claimed, so a single-threaded
     * pass never watches a claim fail. Pseudo-cron fires from ordinary web
     * requests and two can arrive at once.
     */
    public function claimVideo(int $videoId): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO {pushed_videos} (video_id, pushed_at) VALUES (?, NOW())',
            [$videoId]
        ) > 0;
    }

    // ------------------------------------------------------------ validation

    /**
     * Is this something that could ever be delivered to?
     *
     * Checked when it arrives rather than when it is sent. A subscription with
     * a key of the wrong length is one every future run will pick up, fail on,
     * and count as a failure — so it is refused at the door, where there is
     * still somebody to tell.
     */
    public static function isUsable(string $endpoint, string $p256dh, string $auth): bool
    {
        if ($endpoint === '' || strlen($endpoint) > 500) {
            return false;
        }

        // https only. A push endpoint is always https, and anything else is
        // either a mistake or an attempt to make this server call somewhere.
        if (!str_starts_with($endpoint, 'https://')) {
            return false;
        }

        $key = PushCrypto::base64urlDecode($p256dh);
        $secret = PushCrypto::base64urlDecode($auth);

        return $key !== null && strlen($key) === 65 && $key[0] === "\x04"
            && $secret !== null && strlen($secret) === 16;
    }
}
