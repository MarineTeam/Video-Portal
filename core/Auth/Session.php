<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Crypto;
use Throwable;

/**
 * Database-backed sessions.
 *
 * PHP's default file handler writes to a shared temp directory. On shared
 * hosting that directory is frequently readable by every other account on the
 * machine, which means a neighbour can read — or write — your session files and
 * walk straight in as an administrator. Storing sessions in our own database
 * removes that entire class of problem, and gives us "sign this person out
 * everywhere" for free.
 *
 * The session id in the cookie is a random 32-byte token. The row is keyed by
 * its SHA-256 hash, so a leaked database backup does not yield usable cookies.
 */
final class Session
{
    private const COOKIE = 'portal_session';
    private const LIFETIME = 2592000;   // 30 days absolute
    private const IDLE_TIMEOUT = 1800;  // 30 minutes of inactivity

    private ?string $token = null;
    private ?string $id = null;

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $loaded = false;
    private bool $dirty = false;
    private bool $started = false;
    private bool $destroyed = false;

    public function __construct(private readonly Db $db)
    {
    }

    /** Read the session id from the request. Call once, early. */
    public function boot(Request $request): void
    {
        $token = $request->cookie(self::COOKIE);
        if ($token !== null && $token !== '' && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token)) {
            $this->token = $token;
            $this->id = self::hash($token);
        }
        $this->started = true;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    // ------------------------------------------------------------------ data

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->load();
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    /** Read a value and remove it — for one-shot values like the OIDC state. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function forget(string $key): void
    {
        $this->load();
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    public function has(string $key): bool
    {
        $this->load();
        return array_key_exists($key, $this->data);
    }

    // ---------------------------------------------------------------- identity

    public function userId(): ?int
    {
        $id = $this->get('user_id');
        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId() !== null;
    }

    /**
     * Bind this session to a user.
     *
     * The session id is regenerated on login. Without that, an attacker who can
     * set a victim's cookie before they sign in (session fixation) ends up
     * holding an authenticated session.
     */
    public function login(int $userId): void
    {
        $this->load();
        $previous = $this->data;

        $this->destroyRow();

        $this->token = Crypto::token(32);
        $this->id = self::hash($this->token);
        $this->data = $previous;
        $this->data['user_id'] = $userId;
        $this->data['authenticated_at'] = time();
        $this->destroyed = false;
        $this->dirty = true;
        $this->loaded = true;
    }

    public function logout(): void
    {
        $this->destroyRow();
        $this->data = [];
        $this->token = null;
        $this->id = null;
        $this->destroyed = true;
        $this->dirty = false;
        $this->loaded = true;
    }

    /** Sign a user out of every device — used when access is revoked. */
    public function logoutEverywhere(int $userId): void
    {
        try {
            $this->db->execute('DELETE FROM {sessions} WHERE user_id = ?', [$userId]);
        } catch (Throwable $e) {
            error_log('Portal: could not clear sessions for user ' . $userId . ': ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------ persistence

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        if ($this->id === null) {
            return;
        }

        try {
            $row = $this->db->first(
                'SELECT payload, last_active_at FROM {sessions} WHERE id = ?',
                [$this->id]
            );
        } catch (Throwable $e) {
            // A session read failure must not take the site down; the visitor
            // is simply treated as signed out.
            error_log('Portal: session read failed: ' . $e->getMessage());
            return;
        }

        if ($row === null) {
            $this->id = null;
            $this->token = null;
            return;
        }

        // Idle timeout, enforced server-side. The predecessor apps did this in
        // client JavaScript, which is a courtesy rather than a control —
        // anyone who disables JS keeps their session indefinitely.
        $lastActive = strtotime((string) $row['last_active_at']);
        if ($lastActive !== false && (time() - $lastActive) > self::IDLE_TIMEOUT) {
            $this->destroyRow();
            $this->id = null;
            $this->token = null;
            return;
        }

        $decoded = json_decode((string) $row['payload'], true);
        $this->data = is_array($decoded) ? $decoded : [];
    }

    private function destroyRow(): void
    {
        if ($this->id === null) {
            return;
        }
        try {
            $this->db->execute('DELETE FROM {sessions} WHERE id = ?', [$this->id]);
        } catch (Throwable $e) {
            error_log('Portal: session delete failed: ' . $e->getMessage());
        }
    }

    /**
     * Write the session and attach the cookie. Called once, at the end of the
     * request, by the kernel.
     */
    public function commit(Response $response, Request $request): void
    {
        if (!$this->started) {
            return;
        }

        if ($this->destroyed) {
            $response->clearCookie(self::COOKIE);
            return;
        }

        // Nothing was ever stored and no session existed: don't create a row
        // (or set a cookie) for an anonymous visitor who just read a page.
        if ($this->id === null && !$this->dirty) {
            return;
        }

        if ($this->id === null) {
            $this->token = Crypto::token(32);
            $this->id = self::hash($this->token);
        }

        try {
            $payload = json_encode($this->data, JSON_UNESCAPED_SLASHES) ?: '{}';

            $this->db->execute(
                'INSERT INTO {sessions} (id, user_id, payload, ip, user_agent, created_at, last_active_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                   user_id = VALUES(user_id),
                   payload = VALUES(payload),
                   last_active_at = NOW()',
                [
                    $this->id,
                    $this->userId(),
                    $payload,
                    $request->ip(),
                    mb_substr($request->userAgent(), 0, 255),
                ]
            );
        } catch (Throwable $e) {
            error_log('Portal: session write failed: ' . $e->getMessage());
            return;
        }

        if ($this->token !== null) {
            $response->cookie(self::COOKIE, $this->token, [
                'expires'  => time() + self::LIFETIME,
                'path'     => '/',
                'secure'   => $request->isSecure(),
                'httponly' => true,
                // Lax rather than Strict: the OIDC callback is a cross-site
                // top-level navigation back to us, and Strict would drop the
                // cookie and break sign-in entirely.
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Delete expired sessions. Run from cron, not on every request.
     *
     * @return int rows removed
     */
    public function purgeExpired(): int
    {
        try {
            return $this->db->execute(
                'DELETE FROM {sessions}
                 WHERE last_active_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                    OR created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)',
                [self::IDLE_TIMEOUT, self::LIFETIME]
            );
        } catch (Throwable $e) {
            error_log('Portal: session purge failed: ' . $e->getMessage());
            return 0;
        }
    }
}
