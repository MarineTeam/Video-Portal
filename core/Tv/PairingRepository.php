<?php

declare(strict_types=1);

namespace Portal\Tv;

use Portal\Db;

/**
 * Making, finding, approving and claiming television pairings.
 *
 * Three things are true of every method here and they are why the class exists
 * rather than the controller doing it:
 *
 *   THE DEVICE CODE IS ONLY EVER HASHED. There is no method that returns one
 *   and no column that holds one. The plaintext exists for the single response
 *   that hands it to the television.
 *
 *   EVERY LOOKUP GOES THROUGH PairingCode::normalise(), so the lookalike
 *   mapping cannot be skipped by a second caller.
 *
 *   THE STATE IS Pairing::state(), never a condition in SQL. A WHERE clause
 *   that said `approved_at IS NOT NULL AND expires_at > NOW()` would be a
 *   second implementation of the ordering rule, and the one that drifts is the
 *   one that drops a term.
 */
final class PairingRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Start a pairing. The television asks for this.
     *
     * @return array{user_code: string, device_code: string, expires_in: int, interval: int}
     */
    public function start(string $label = ''): array
    {
        /*
         * A fresh user code, and it must not collide with a LIVE one — two
         * televisions showing the same code would have one person approve the
         * other's set. Retried rather than enforced by a unique index, because
         * the constraint is "unique among live rows" and MySQL cannot express
         * that; a plain unique index would let a code from last month block a
         * new one forever.
         *
         * Bounded, and the bound throws rather than looping: with 32^8 codes
         * and a ten-minute life, five collisions in a row means something is
         * wrong with the generator, and the honest answer is an error rather
         * than a hang.
         */
        $userCode = '';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = PairingCode::make();

            if ($this->liveRow($candidate) === null) {
                $userCode = $candidate;
                break;
            }
        }

        if ($userCode === '') {
            throw new \RuntimeException('Could not make a pairing code. Try again.');
        }

        $deviceCode = bin2hex(random_bytes(32));

        $this->db->insert('tv_pairings', [
            'user_code'    => $userCode,
            'device_hash'  => self::hash($deviceCode),
            'device_label' => mb_substr(trim($label), 0, 120) ?: null,
            'expires_at'   => date('Y-m-d H:i:s', time() + Pairing::LIFETIME_SECONDS),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return [
            'user_code'   => $userCode,
            'device_code' => $deviceCode,
            'expires_in'  => Pairing::LIFETIME_SECONDS,
            'interval'    => Pairing::POLL_SECONDS,
        ];
    }

    /**
     * The pairing a person's typed code refers to, or null.
     *
     * Normalised first, and shape-checked before the database is asked: a
     * television pairing screen is something people mash keys at, and none of
     * that should become a query.
     *
     * The NEWEST matching row, because the user code is not unique across
     * expired rows — see the migration. Ordering by id descending means a code
     * that happens to repeat one from last month resolves to the live pairing,
     * and Pairing::state() then says the old one is expired anyway.
     *
     * @return array<string, mixed>|null
     */
    public function byUserCode(string $typed): ?array
    {
        $code = PairingCode::normalise($typed);

        if (!PairingCode::looksReal($code)) {
            return null;
        }

        return $this->db->first(
            'SELECT * FROM {tv_pairings} WHERE user_code = ? ORDER BY id DESC LIMIT 1',
            [$code]
        );
    }

    /**
     * The pairing a television's device code refers to, or null.
     *
     * By HASH, so the presented value is never compared against anything
     * stored in the clear — there is nothing stored in the clear.
     *
     * @return array<string, mixed>|null
     */
    public function byDeviceCode(string $deviceCode): ?array
    {
        $deviceCode = trim($deviceCode);

        // 64 hex characters or it is not one of ours. Refused on shape for the
        // same reason as above.
        if (preg_match('/^[0-9a-f]{64}$/', $deviceCode) !== 1) {
            return null;
        }

        return $this->db->first(
            'SELECT * FROM {tv_pairings} WHERE device_hash = ? LIMIT 1',
            [self::hash($deviceCode)]
        );
    }

    /**
     * Say yes to a television.
     *
     * A CONDITIONAL UPDATE, not a read-then-write. Two people can be looking at
     * the same television and both press approve, and a plain UPDATE would let
     * the second overwrite who did it. The conditions also re-check the state
     * inside the statement, so a pairing that expired between the page render
     * and the button press cannot be approved by the press.
     *
     * @return bool whether this call was the one that approved it
     */
    public function approve(int $pairingId, int $userId): bool
    {
        return $this->db->execute(
            'UPDATE {tv_pairings}
                SET approved_by = ?, approved_at = NOW()
              WHERE id = ?
                AND approved_at IS NULL
                AND claimed_at IS NULL
                AND expires_at > NOW()
                AND attempts < ?',
            [$userId, $pairingId, Pairing::MAX_ATTEMPTS]
        ) > 0;
    }

    /**
     * Spend a pairing, returning who the television is now signed in as.
     *
     * CLAIMED IN THE SAME STATEMENT that reads the approver, which is what
     * makes it single-use under concurrency. A television that polls twice
     * quickly — or two of them sharing a config file — would otherwise both
     * pass a read-then-write and both get a session.
     *
     * MySQL has no `UPDATE ... RETURNING`, so this is the same-transaction
     * UPDATE-then-SELECT the platform requires: the UPDATE's row count is what
     * decides, and the SELECT afterwards is inside the transaction so nothing
     * can have changed between them.
     *
     * @return int|null the user id, or null if it was not claimable
     */
    public function claim(int $pairingId): ?int
    {
        /** @var int|null $userId */
        $userId = $this->db->transaction(function () use ($pairingId): ?int {
            $claimed = $this->db->execute(
                'UPDATE {tv_pairings}
                    SET claimed_at = NOW()
                  WHERE id = ?
                    AND claimed_at IS NULL
                    AND approved_at IS NOT NULL
                    AND approved_by IS NOT NULL
                    AND expires_at > NOW()',
                [$pairingId]
            );

            if ($claimed === 0) {
                return null;
            }

            $who = (int) $this->db->value(
                'SELECT approved_by FROM {tv_pairings} WHERE id = ?',
                [$pairingId]
            );

            return $who > 0 ? $who : null;
        });

        return $userId;
    }

    /**
     * Note that somebody typed a code that did not match this pairing.
     *
     * Counted on the pairing rather than only per person, because the thing
     * being protected against is somebody typing codes to find ANY pending
     * pairing — which on a Sunday morning might be several. The per-person
     * throttle is the controller's; this is the per-pairing ceiling.
     */
    public function noteAttempt(int $pairingId): void
    {
        $this->db->execute(
            'UPDATE {tv_pairings} SET attempts = attempts + 1 WHERE id = ?',
            [$pairingId]
        );
    }

    /**
     * Televisions signed in from this account, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->db->all(
            'SELECT id, device_label, approved_at, claimed_at, created_at
               FROM {tv_pairings}
              WHERE approved_by = ? AND claimed_at IS NOT NULL
              ORDER BY id DESC
              LIMIT 50',
            [$userId]
        );
    }

    /**
     * Throw away pairings nobody completed.
     *
     * A pairing is a credential with a ten-minute life, so a table of dead ones
     * is a table of things that must never be honoured again. Kept for a day
     * past expiry rather than deleted on the minute, so "that code has already
     * been used" is still answerable to somebody who typed it twice — the
     * message that tells them to ask the television for a new one.
     */
    public function purge(): int
    {
        return $this->db->execute(
            'DELETE FROM {tv_pairings} WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
    }

    /**
     * A live pairing with this exact code, for the collision check.
     *
     * @return array<string, mixed>|null
     */
    private function liveRow(string $userCode): ?array
    {
        return $this->db->first(
            'SELECT id FROM {tv_pairings} WHERE user_code = ? AND expires_at > NOW() LIMIT 1',
            [$userCode]
        );
    }

    private static function hash(string $deviceCode): string
    {
        return hash('sha256', $deviceCode);
    }
}
