<?php

declare(strict_types=1);

namespace Portal\Sharing;

use DateTimeImmutable;
use DateTimeZone;
use Portal\Config;
use Portal\Db;
use Portal\Support\Crypto;
use Portal\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Access to a share without an account.
 *
 * The recipient types the address the link was sent to; if it matches, they get
 * an emailed one-time link; clicking it sets a signed cookie scoped to that one
 * share's path. No account is ever created.
 *
 * ANTI-ENUMERATION IS THE POINT.
 *
 * A share link plus an email box is enough for anyone to ask "does this address
 * have access?" — so every outcome must look identical. Wrong address, unknown
 * link, revoked, expired, throttled: same words, same status, same shape. If a
 * wrong address answered differently from a right one, the page becomes an
 * oracle for testing whether a colleague was sent something, and a link
 * forwarded to the wrong person becomes a way to enumerate the recipient list.
 *
 * That is why request() returns nothing at all. There is no success value to
 * leak, and no caller can accidentally branch on one.
 */
final class Gate
{
    /** A magic link is good for one hour. Long enough to walk to a laptop. */
    private const LINK_TTL = 3600;

    /** How long access lasts once granted, capped by the share's own expiry. */
    private const COOKIE_TTL = 43200;

    /** One request per share per 30 seconds, regardless of the address given. */
    private const THROTTLE_SECONDS = 30;

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
    ) {
    }

    /**
     * The signing secret.
     *
     * Fails loudly when unset. An empty secret makes every grant forgeable and
     * the failure is otherwise completely silent — the HMAC still computes,
     * still verifies, and protects nothing.
     */
    private function secret(): string
    {
        $secret = $this->config->str('gate_secret');

        if ($secret === '') {
            throw new RuntimeException(
                'gate_secret is not set in config.php. Link-based access cannot be secured without it.'
            );
        }

        return $secret;
    }

    // --------------------------------------------------------------- request

    /**
     * Ask for a sign-in link.
     *
     * Returns nothing on purpose. Whether the address was right, whether the
     * link exists, whether it was revoked, and whether the caller is being
     * throttled all produce the same outcome — silence — so the response the
     * visitor sees cannot depend on any of them.
     *
     * @param callable(string, string, string): void $send  (email, url, videoTitle)
     */
    public function request(string $targetType, string $targetId, string $email, callable $send): void
    {
        $email = Str::normalizeEmail($email);

        // Everything below is best-effort and silent. Any failure looks
        // exactly like a wrong address, which is the whole design.
        try {
            // Refuse to issue anything the gate cannot secure. Without this,
            // a missing secret produced links that were emailed happily and
            // then failed on redemption — and worse, sending only for the
            // correct address made the misconfigured gate the very oracle the
            // rest of this class exists to prevent.
            $this->secret();

            if (!Str::isEmail($email)) {
                return;
            }

            if (!$this->allowRequest($targetType, $targetId)) {
                return;
            }

            $target = $this->resolveTarget($targetType, $targetId);

            if ($target === null) {
                return;
            }

            // The comparison that matters, in constant time so response timing
            // does not distinguish a near-miss from a wrong address.
            if (!Crypto::verify($target['email'], $email)) {
                return;
            }

            $token = Crypto::token(32);
            $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . self::LINK_TTL . ' seconds');

            // Only the hash is stored, so a database dump yields no working
            // links.
            $this->db->insert('gate_grants', [
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'email'       => $email,
                'token_hash'  => hash('sha256', $token),
                'expires_at'  => $expires->format('Y-m-d H:i:s'),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);

            $url = $this->config->url(
                ($targetType === 'bundle' ? '/b/' : '/s/') . $targetId . '?key=' . $token
            );

            $send($email, $url, (string) $target['title']);
        } catch (Throwable $e) {
            // Logged, never surfaced. A visitor learning that the database is
            // unhappy tells them something about the link they should not know.
            error_log('Portal: gate request failed: ' . $e->getMessage());
        }
    }

    /**
     * Throttle by target, not by address.
     *
     * Throttling per address would itself be an oracle: a throttled response
     * for the right address and an immediate one for a wrong address
     * distinguishes them. Per target, everyone asking about a link is limited
     * equally.
     */
    private function allowRequest(string $targetType, string $targetId): bool
    {
        $bucket = hash('sha256', "gate:{$targetType}:{$targetId}");

        try {
            $recent = $this->db->value(
                'SELECT 1 FROM {rate_limits}
                  WHERE bucket = ? AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)',
                [$bucket, self::THROTTLE_SECONDS]
            );

            if ($recent !== null) {
                return false;
            }

            $this->db->execute(
                'INSERT INTO {rate_limits} (bucket, window_start, hits) VALUES (?, NOW(), 1)
                 ON DUPLICATE KEY UPDATE window_start = NOW(), hits = hits + 1',
                [$bucket]
            );

            return true;
        } catch (Throwable) {
            // Fails open: a broken counter should not stop a legitimate
            // recipient reaching their video.
            return true;
        }
    }

    /** @return array{email: string, title: string}|null */
    private function resolveTarget(string $targetType, string $targetId): ?array
    {
        if ($targetType === 'bundle') {
            if (!Bundle::isValidId($targetId)) {
                return null;
            }

            $row = $this->db->first(
                'SELECT recipient_email, expires_at FROM {bundles} WHERE id = ?',
                [$targetId]
            );

            if ($row === null || strtotime((string) $row['expires_at']) <= time()) {
                return null;
            }

            return ['email' => (string) $row['recipient_email'], 'title' => 'your videos'];
        }

        if (!Share::isValidId($targetId)) {
            return null;
        }

        $row = $this->db->first('SELECT * FROM {shares} WHERE id = ?', [$targetId]);

        if ($row === null) {
            return null;
        }

        $share = Share::fromRow($row);

        // A revoked or expired link behaves exactly like one that never
        // existed. The recipient is not told which.
        if (!$share->isLive()) {
            return null;
        }

        return ['email' => $share->recipientEmail, 'title' => $share->videoTitle];
    }

    // ---------------------------------------------------------------- redeem

    /**
     * Exchange a magic-link token for a grant.
     *
     * Single use: the row is marked consumed, so a link forwarded onwards or
     * recovered from a mailbox later opens nothing.
     *
     * @return string|null the signed cookie value, or null if the token is no good
     */
    public function redeem(string $targetType, string $targetId, string $token): ?string
    {
        try {
            $row = $this->db->first(
                'SELECT * FROM {gate_grants}
                  WHERE token_hash = ?
                    AND target_type = ?
                    AND target_id = ?
                    AND consumed_at IS NULL
                    AND expires_at > NOW()',
                [hash('sha256', $token), $targetType, $targetId]
            );

            if ($row === null) {
                return null;
            }

            $this->db->execute(
                'UPDATE {gate_grants} SET consumed_at = NOW() WHERE id = ?',
                [(int) $row['id']]
            );

            return $this->sign($targetType, $targetId, (string) $row['email']);
        } catch (Throwable $e) {
            error_log('Portal: gate redemption failed: ' . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------- the grant

    /**
     * A signed, self-describing grant.
     *
     * Carries who it is for and when it stops working, so verification needs
     * no database round trip. Bound to one target, so a grant for one share
     * cannot be presented for another.
     */
    private function sign(string $targetType, string $targetId, string $email): string
    {
        $expires = time() + self::COOKIE_TTL;
        $payload = "{$targetType}|{$targetId}|" . Str::normalizeEmail($email) . "|{$expires}";

        return $payload . '|' . Crypto::hmac($payload, $this->secret());
    }

    /**
     * Check a grant presented in a cookie.
     *
     * @return string|null the email it was issued to, or null if it is no good
     */
    public function verify(string $targetType, string $targetId, string $grant): ?string
    {
        $parts = explode('|', $grant);

        if (count($parts) !== 5) {
            return null;
        }

        [$type, $id, $email, $expires, $signature] = $parts;

        // Recompute over the claimed values and compare in constant time. Note
        // the signature covers the target, so a valid grant for a different
        // share fails here rather than being accepted for this one.
        $payload = "{$type}|{$id}|{$email}|{$expires}";

        try {
            $expected = Crypto::hmac($payload, $this->secret());
        } catch (Throwable) {
            return null;
        }

        if (!Crypto::verify($expected, $signature)) {
            return null;
        }

        if ($type !== $targetType || $id !== $targetId) {
            return null;
        }

        if (!ctype_digit($expires) || (int) $expires <= time()) {
            return null;
        }

        return $email;
    }

    /**
     * The cookie name for one target.
     *
     * Per-target, and the cookie is set with a path scoped to that target's
     * URL, so a browser only ever sends the grant it needs. Someone with
     * access to several shares does not broadcast all of them on every request.
     */
    public function cookieName(string $targetType, string $targetId): string
    {
        return 'gate_' . substr(hash('sha256', $targetType . ':' . $targetId), 0, 24);
    }

    public function cookiePath(string $targetType, string $targetId): string
    {
        return ($targetType === 'bundle' ? '/b/' : '/s/') . $targetId;
    }

    public function cookieLifetime(): int
    {
        return self::COOKIE_TTL;
    }

    // --------------------------------------------------------------- cleanup

    /** Consumed and expired grants, removed on a schedule. */
    public function purge(): int
    {
        try {
            return $this->db->execute(
                'DELETE FROM {gate_grants}
                  WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
                     OR (consumed_at IS NOT NULL AND consumed_at < DATE_SUB(NOW(), INTERVAL 7 DAY))'
            );
        } catch (Throwable) {
            return 0;
        }
    }
}
