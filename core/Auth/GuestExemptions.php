<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;
use Portal\Support\Str;
use Throwable;

/**
 * Addresses excused from the organisation check, one at a time.
 *
 * For the person who legitimately has no account in the organisation — a
 * visiting speaker, somebody's spouse, a contractor. The alternatives without
 * it are adding them to somebody else's identity system, or loosening the whole
 * site to EITHER to admit one person.
 *
 * IT WAIVES THE ORGANISATION CHECK AND NOTHING ELSE. Not the address list, not
 * the approval flag, not email verification. A waiver that skipped everything
 * would be an admin backdoor wearing the word "guest", and from the screen that
 * grants it the two look identical — so the narrowness is enforced here, in one
 * method, with a test that fails the moment it starts excusing more.
 *
 * OFF BY DEFAULT. An exemption list that went live the moment a row existed
 * would mean adding somebody "to see how it works" quietly opening a door, and
 * this is a door whose entire purpose is bypassing a check.
 */
final class GuestExemptions
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Is this address excused the organisation check?
     *
     * Answers false whenever the feature is off, whatever rows exist — so
     * switching it off is a complete answer rather than a partial one, and
     * nobody has to also empty the list to be sure.
     */
    public function excuses(bool $enabled, string $email): bool
    {
        if (!$enabled) {
            return false;
        }

        try {
            return $this->db->value(
                'SELECT 1 FROM {guest_exemptions} WHERE email = ?',
                [Str::normalizeEmail($email)]
            ) !== null;
        } catch (Throwable $e) {
            /*
             * Fails to NOT EXCUSED, which is the strict direction: somebody
             * whose exemption cannot be read is treated as an ordinary member
             * and refused by the check they were excused from. That is a person
             * asking why they cannot get in — recoverable — rather than a
             * database hiccup opening the gate this table exists to bypass.
             */
            error_log('Portal: could not read guest exemptions: ' . $e->getMessage());

            return false;
        }
    }

    /** @return bool whether this created a new entry */
    public function add(string $email, ?string $note = null, ?string $addedBy = null): bool
    {
        $email = Str::normalizeEmail($email);

        if ($email === '' || !Str::isEmail($email)) {
            return false;
        }

        $existing = $this->db->first('SELECT id FROM {guest_exemptions} WHERE email = ?', [$email]);

        if ($existing !== null) {
            $this->db->update('guest_exemptions', [
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
            ], ['id' => (int) $existing['id']]);

            return false;
        }

        $this->db->insert('guest_exemptions', [
            'email'      => $email,
            'note'       => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
            'added_by'   => $addedBy,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function remove(int $id): void
    {
        $this->db->execute('DELETE FROM {guest_exemptions} WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        try {
            return $this->db->all('SELECT * FROM {guest_exemptions} ORDER BY email');
        } catch (Throwable) {
            return [];
        }
    }

    public function count(): int
    {
        try {
            return (int) $this->db->value('SELECT COUNT(*) FROM {guest_exemptions}');
        } catch (Throwable) {
            return 0;
        }
    }
}
