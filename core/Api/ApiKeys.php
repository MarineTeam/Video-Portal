<?php

declare(strict_types=1);

namespace Portal\Api;

use Portal\Db;
use Portal\Http\HttpException;

/**
 * Making, checking and revoking keys for the read API.
 *
 * A key is a password a machine keeps in a config file. Only its hash is
 * stored, the plaintext exists for one page render, a prefix is kept so the
 * list means anything, and the row outlives the key so "who made this and when
 * was it last used" survives a revocation — see the migration for why each of
 * those follows from the first sentence.
 */
final class ApiKeys
{
    /** What a key looks like. The prefix makes one recognisable in a log. */
    private const PREFIX = 'vpk_';

    /** How much of it is kept in the clear, for the list. */
    private const SHOWN = 12;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Make a key.
     *
     * @param list<string> $scopes
     * @return array{id: int, key: string} the plaintext, for one render only
     */
    public function issue(string $name, array $scopes, string $createdBy = ''): array
    {
        $name = trim($name);

        if ($name === '') {
            throw HttpException::badRequest('A key needs a name, or nobody will know what it is for.');
        }

        $scopes = Scope::clean($scopes);

        if ($scopes === []) {
            /*
             * Refused rather than stored. A key with no scopes can read nothing
             * at all, so it would sit on the screen looking like access
             * somebody had granted while every request it made answered 403.
             */
            throw HttpException::badRequest('Choose at least one thing the key may read.');
        }

        $key = self::PREFIX . bin2hex(random_bytes(32));

        $id = (int) $this->db->insert('api_keys', [
            'name'       => mb_substr($name, 0, 190),
            'key_hash'   => self::hash($key),
            'key_prefix' => substr($key, 0, self::SHOWN),
            'scopes'     => (string) json_encode($scopes),
            'created_by' => mb_substr(trim($createdBy), 0, 190) ?: null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'key' => $key];
    }

    /**
     * Which key this is, if it is a live one.
     *
     * Looked up by HASH, so the presented string is never compared against
     * anything stored in the clear — there is nothing stored in the clear.
     *
     * A revoked key does not match. The row is still there, which is the point
     * of revoking rather than deleting, but it authenticates nothing.
     *
     * @return array<string, mixed>|null
     */
    public function authenticate(string $presented): ?array
    {
        $presented = trim($presented);

        if ($presented === '' || !str_starts_with($presented, self::PREFIX)) {
            // Refused on shape. The API is crawled and probed constantly and
            // none of that should become a query.
            return null;
        }

        $row = $this->db->first(
            'SELECT * FROM {api_keys} WHERE key_hash = ? AND revoked_at IS NULL',
            [self::hash($presented)]
        );

        return $row === null ? null : $row;
    }

    /**
     * Note that a key was used.
     *
     * Separate from authenticate() so a request that authenticated and was then
     * refused for SCOPE still counts as a use. "Last used" answers "is anybody
     * still relying on this", and an integration hammering an endpoint it may
     * not read is very much somebody relying on it.
     */
    public function touch(int $id): void
    {
        $this->db->execute(
            'UPDATE {api_keys} SET last_used_at = NOW(), uses = uses + 1 WHERE id = ?',
            [$id]
        );
    }

    /**
     * Stop a key, keeping its history.
     *
     * Soft, and idempotent: revoking twice is not an error, because the person
     * pressing the button twice is dealing with an incident and does not need
     * to be corrected.
     */
    public function revoke(int $id): void
    {
        $this->db->execute(
            'UPDATE {api_keys} SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id = ?',
            [$id]
        );
    }

    /**
     * Every key, live ones first.
     *
     * The hash is NOT selected. Nothing needs it outside authenticate(), and a
     * row carrying it would be one SecretGuard throws on the moment somebody
     * puts this list in a payload — which is the backstop, not the reason.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->all(
            'SELECT id, name, key_prefix, scopes, created_by, created_at,
                    last_used_at, uses, revoked_at
               FROM {api_keys}
              ORDER BY revoked_at IS NOT NULL, id DESC'
        );
    }

    /**
     * The scopes on a key row.
     *
     * @param array<string, mixed> $key
     * @return list<string>
     */
    public static function scopesOf(array $key): array
    {
        return Scope::clean(json_decode((string) ($key['scopes'] ?? '[]'), true));
    }

    /**
     * SHA-256, not password_hash().
     *
     * The value is 32 random bytes rather than something a person chose, so
     * there is nothing to brute-force and no reason to make every API request
     * pay for a slow KDF. A key with 256 bits of entropy is not found by
     * grinding hashes.
     */
    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
