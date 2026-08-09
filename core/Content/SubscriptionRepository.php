<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Support\Str;

/**
 * Who has asked to hear about new content, and which new content.
 *
 * The two things worth stating up front, because both are easy to get wrong in
 * ways nobody notices until people complain:
 *
 * Subscribing is idempotent, enforced by a unique key rather than a
 * read-then-write. A double-submitted form is the ordinary way somebody
 * subscribes twice, and the consequence is two copies of every email.
 *
 * Unsubscribing needs no account and no session. The token in the link is the
 * whole credential, which is why it is a stored random value rather than an
 * HMAC: deleting the row genuinely invalidates the link, where an HMAC would
 * keep working forever.
 */
final class SubscriptionRepository
{
    public const SITE     = 'site';
    public const CATEGORY = 'category';
    public const SERIES   = 'series';
    public const SPEAKER  = 'speaker';

    public function __construct(
        private readonly Db $db,
        private readonly CategoryRepository $categories,
    ) {
    }

    /** @return array<string, string> the scopes, labelled */
    public static function scopes(): array
    {
        return [
            self::SITE     => 'Everything new',
            self::CATEGORY => 'A category',
            self::SERIES   => 'A series',
            self::SPEAKER  => 'A speaker',
        ];
    }

    /**
     * A submitted scope, or null.
     *
     * Refused rather than defaulted to "site". Quietly widening somebody's
     * request from one series to the whole library is the difference between a
     * useful email and an unwanted one.
     */
    public static function sanitizeScope(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return array_key_exists($value, self::scopes()) ? $value : null;
    }

    // ------------------------------------------------------------------ reads

    /** @return list<array<string, mixed>> */
    public function forEmail(string $email): array
    {
        return $this->db->all(
            'SELECT * FROM {subscriptions} WHERE email = ? ORDER BY scope_type, id',
            [Str::normalizeEmail($email)]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        // Format-checked before it reaches the database, the same discipline
        // share ids get: a lookup is not the place to discover the input was
        // never a token.
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->first('SELECT * FROM {subscriptions} WHERE token = ?', [$token]);
    }

    /** Is this address already subscribed to this exact thing? */
    public function has(string $email, string $scopeType, ?int $scopeId): bool
    {
        return $this->db->value(
            'SELECT id FROM {subscriptions}
              WHERE email = ? AND scope_type = ? AND COALESCE(scope_id, 0) = ?',
            [Str::normalizeEmail($email), $scopeType, $scopeId ?? 0]
        ) !== null;
    }

    /** @return list<array<string, mixed>> everyone, for the admin screen */
    public function all(int $limit = 500): array
    {
        return $this->db->all(
            'SELECT * FROM {subscriptions} ORDER BY created_at DESC LIMIT ' . max(1, min(2000, $limit))
        );
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {subscriptions}');
    }

    /**
     * Everybody who should hear about this video.
     *
     * One query per scope kind rather than one per subscriber, and the category
     * arm walks the ancestor chain so subscribing to "Sermons" also covers
     * "Sermons / 2026". Addresses are deduplicated: somebody subscribed to both
     * the site and a series gets one email, not two.
     *
     * @return list<array{email: string, token: string}>
     */
    public function recipientsFor(Video $video): array
    {
        $found = [];

        $collect = function (string $sql, array $params) use (&$found): void {
            foreach ($this->db->all($sql, $params) as $row) {
                // Keyed by address, so the first token wins and one person
                // receives one email however many ways they subscribed.
                $found[(string) $row['email']] ??= (string) $row['token'];
            }
        };

        $collect('SELECT email, token FROM {subscriptions} WHERE scope_type = ?', [self::SITE]);

        // Every category the video is in, plus all of their ancestors.
        $categoryIds = $this->ancestorIds($video->id);
        if ($categoryIds !== []) {
            $collect(
                'SELECT email, token FROM {subscriptions}
                  WHERE scope_type = ? AND scope_id IN ('
                    . implode(',', array_fill(0, count($categoryIds), '?')) . ')',
                [self::CATEGORY, ...$categoryIds]
            );
        }

        if ($video->seriesId !== null) {
            $collect(
                'SELECT email, token FROM {subscriptions} WHERE scope_type = ? AND scope_id = ?',
                [self::SERIES, $video->seriesId]
            );
        }

        if ($video->speakerId !== null) {
            $collect(
                'SELECT email, token FROM {subscriptions} WHERE scope_type = ? AND scope_id = ?',
                [self::SPEAKER, $video->speakerId]
            );
        }

        $out = [];
        foreach ($found as $email => $token) {
            $out[] = ['email' => $email, 'token' => $token];
        }

        return $out;
    }

    // ----------------------------------------------------------------- writes

    /**
     * Subscribe, or return the existing subscription unchanged.
     *
     * INSERT IGNORE against the unique key rather than checking first. The
     * return value says whether it was new, which is only used to word the
     * confirmation — the outcome is the same either way, which is the point.
     *
     * @return array{token: string, new: bool}
     */
    public function subscribe(string $email, string $scopeType, ?int $scopeId, ?int $userId = null): array
    {
        $email = Str::normalizeEmail($email);
        $token = $this->newToken();

        $affected = $this->db->execute(
            'INSERT IGNORE INTO {subscriptions}
                 (token, email, user_id, scope_type, scope_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$token, $email, $userId, $scopeType, $scopeId]
        );

        if ($affected > 0) {
            return ['token' => $token, 'new' => true];
        }

        // Already there. Hand back the token that already exists, so the
        // confirmation can still carry a working unsubscribe link.
        $existing = (string) $this->db->value(
            'SELECT token FROM {subscriptions}
              WHERE email = ? AND scope_type = ? AND COALESCE(scope_id, 0) = ?',
            [$email, $scopeType, $scopeId ?? 0]
        );

        return ['token' => $existing, 'new' => false];
    }

    /**
     * Unsubscribe by token.
     *
     * @return bool whether anything was removed
     */
    public function unsubscribe(string $token): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) !== 1) {
            return false;
        }

        return $this->db->execute('DELETE FROM {subscriptions} WHERE token = ?', [$token]) > 0;
    }

    /** Everything this address asked for, gone in one action. */
    public function unsubscribeAll(string $email): int
    {
        return $this->db->execute(
            'DELETE FROM {subscriptions} WHERE email = ?',
            [Str::normalizeEmail($email)]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM {subscriptions} WHERE id = ?', [$id]);
    }

    public function markSent(string $email): void
    {
        $this->db->execute(
            'UPDATE {subscriptions} SET last_sent_at = NOW() WHERE email = ?',
            [Str::normalizeEmail($email)]
        );
    }

    /**
     * Drop subscriptions whose target no longer exists.
     *
     * A series can be deleted; the subscription to it then matches nothing and
     * would sit in the table forever, appearing on the admin screen as a
     * subscriber who never hears anything.
     *
     * @return int how many were removed
     */
    public function pruneOrphans(): int
    {
        $removed = 0;

        foreach ([
            self::CATEGORY => 'categories',
            self::SERIES   => 'series',
            self::SPEAKER  => 'speakers',
        ] as $scope => $table) {
            $removed += $this->db->execute(
                "DELETE s FROM {subscriptions} s
                  LEFT JOIN {{$table}} t ON t.id = s.scope_id
                  WHERE s.scope_type = ? AND t.id IS NULL",
                [$scope]
            );
        }

        return $removed;
    }

    // ------------------------------------------------------------- internals

    /**
     * Every category this video sits in, plus their ancestors.
     *
     * @return list<int>
     */
    private function ancestorIds(int $videoId): array
    {
        $rows = $this->db->all(
            'SELECT category_id FROM {video_categories} WHERE video_id = ?',
            [$videoId]
        );

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['category_id'];
            $ids[$id] = true;

            foreach ($this->categories->ancestors($id) as $ancestor) {
                $ids[$ancestor->id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * 22 characters of base64url randomness, the same shape share ids use.
     *
     * Long enough that guessing one is not a strategy, short enough to survive
     * an email client wrapping the line.
     */
    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
