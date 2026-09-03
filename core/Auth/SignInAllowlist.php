<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;
use Portal\Support\Str;

/**
 * Who may sign in at all.
 *
 * A second gate, in front of the approval flag, answering a different question.
 *
 *   this list          may this ADDRESS be here?      site policy, set ahead of
 *                                                     time, in bulk, before
 *                                                     anybody has an account
 *
 *   users.authorized   has an admin approved THIS      per account, already
 *                      account?                        enforced on every request
 *
 * NEITHER IS DERIVED FROM THE OTHER, and that is the whole design. The
 * application this is ported from kept one fact in both places, recomputing the
 * account flag from the list on every request — so granting access on the
 * accounts screen appeared to work and was silently undone on the person's next
 * page load. It took a fourth commit and a unified write path to repair. Two
 * independent questions need no write path and cannot disagree.
 *
 * What it is FOR: a site with two hundred known members. Today the only route
 * in is sign in, land on the pending page, ask, and wait for an administrator
 * to approve — two hundred times. This is the list that says who is expected.
 *
 * WHAT IT DOES NOT DO: replace approval. An address on the list still gets an
 * unapproved account, because "we expected you" and "you may watch" are
 * different statements and the site owner may want both. There is a switch for
 * sites that want the list to imply approval, and it says plainly what it does.
 */
final class SignInAllowlist
{
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';

    /** Why somebody was refused. Each one implies a different fix. */
    public const NOT_LISTED = 'not_listed';
    public const SUSPENDED_ENTRY = 'suspended';
    public const NOT_APPROVED = 'not_approved';

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------- the gate

    /**
     * May this address sign in?
     *
     * The pure decision, with the exemptions in it, so the rule is in one place
     * and can be tested without a database or a request.
     *
     * EXEMPTIONS ARE NOT OPTIONAL, and they are the reason this can ship at
     * all. Deployment here is `git pull` on a host with no shell. An allowlist
     * that can refuse the last administrator has locked somebody out of the
     * only screen that could undo it, permanently. A local password is the
     * documented way back into this application when an identity provider is
     * misconfigured; a gate that can close that door removes the recovery path
     * for the failure it is most likely to cause.
     *
     * Same rule require_verified_email ships with, for the same reason.
     */
    public static function decide(
        bool $enabled,
        bool $isAdmin,
        bool $isLocalAccount,
        ?string $entryStatus
    ): ?string {
        if (!$enabled || $isAdmin || $isLocalAccount) {
            return null;
        }

        if ($entryStatus === null) {
            return self::NOT_LISTED;
        }

        return $entryStatus === self::SUSPENDED ? self::SUSPENDED_ENTRY : null;
    }

    /**
     * Something an administrator can act on.
     *
     * Three reasons, three different screens to go to. "Access denied" is true
     * of all of them and useful for none — and this is the message read by
     * somebody who cannot see a log, quite possibly over the phone.
     */
    public static function explain(string $reason): string
    {
        return match ($reason) {
            self::NOT_LISTED => 'This address is not on the list of people who may sign in.',
            self::SUSPENDED_ENTRY => 'This address was on the list and has been suspended.',
            self::NOT_APPROVED => 'This account exists but has not been approved yet.',
            'registration_refused' => 'The sign-in provider was told not to create an account for this '
                . 'address, because it is not on the list. No account was made.',
            default => 'This address cannot sign in here.',
        };
    }

    /** The status of one address, or null when it is not listed at all. */
    public function statusOf(string $email): ?string
    {
        $value = $this->db->value(
            'SELECT status FROM {signin_allowlist} WHERE email = ?',
            [Str::normalizeEmail($email)]
        );

        return $value === null ? null : (string) $value;
    }

    // -------------------------------------------------------------- writing

    /**
     * Add or reinstate one address.
     *
     * Reinstating rather than inserting a second row, because the address is
     * unique — and because the history of who added it and when is the question
     * asked afterwards, when somebody has got in who should not have.
     *
     * @return bool whether this created a new entry
     */
    public function add(string $email, ?string $note = null, ?string $addedBy = null): bool
    {
        $email = Str::normalizeEmail($email);
        $now = date('Y-m-d H:i:s');

        $existing = $this->db->first('SELECT id FROM {signin_allowlist} WHERE email = ?', [$email]);

        if ($existing !== null) {
            $this->db->update('signin_allowlist', [
                'status'     => self::ACTIVE,
                'note'       => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
                'updated_at' => $now,
            ], ['id' => (int) $existing['id']]);

            return false;
        }

        $this->db->insert('signin_allowlist', [
            'email'      => $email,
            'status'     => self::ACTIVE,
            'note'       => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
            'added_by'   => $addedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    /**
     * Add several addresses at once, which is the point of the feature.
     *
     * Pasted text rather than a parsed file: commas, semicolons, newlines and
     * "Name <address>" all arrive from a spreadsheet or a mail client, and an
     * administrator with a list of two hundred should not have to reformat it.
     *
     * @return array{added: int, updated: int, rejected: list<string>}
     */
    public function addMany(string $text, ?string $note = null, ?string $addedBy = null): array
    {
        $added = 0;
        $updated = 0;
        $rejected = [];

        foreach (self::parse($text) as $email) {
            if (!Str::isEmail($email)) {
                // Named rather than counted. "3 were rejected" sends somebody
                // hunting through two hundred lines for which three.
                $rejected[] = $email;
                continue;
            }

            if ($this->add($email, $note, $addedBy)) {
                $added++;
            } else {
                $updated++;
            }
        }

        return ['added' => $added, 'updated' => $updated, 'rejected' => $rejected];
    }

    /**
     * Split pasted text into candidate addresses.
     *
     * Deliberately permissive about separators and deliberately strict about
     * what counts as an address — the caller validates, so anything unusable
     * comes back named rather than silently dropped.
     *
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        /*
         * Split on the SEPARATORS first, and only then on whitespace, and only
         * where there was no "Name <address>" to unwrap.
         *
         * The order matters and the first version got it wrong: splitting on
         * whitespace up front turns "Sam Smith <sam@example.com>" into three
         * tokens, two of which are somebody's name — so the one address in it
         * is found and then reported back as two things that are not addresses.
         * A paste out of a mail client is the commonest way this list gets
         * filled, so that is the case it has to handle rather than the case it
         * blames the administrator for.
         */
        $out = [];

        foreach (preg_split('/[,;\r\n]+/', trim($text)) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            // A display name and an address: the brackets settle it, and
            // whatever is outside them is a person's name, not an entry.
            if (preg_match('/<([^>]*)>/', $part, $m) === 1) {
                $out[] = Str::normalizeEmail($m[1]);
                continue;
            }

            // No brackets, so a run of addresses separated by spaces.
            foreach (preg_split('/\s+/', $part) ?: [] as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $out[] = Str::normalizeEmail($token);
                }
            }
        }

        return array_values(array_unique($out));
    }

    public function suspend(int $id): void
    {
        $this->db->update(
            'signin_allowlist',
            ['status' => self::SUSPENDED, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
    }

    public function reinstate(int $id): void
    {
        $this->db->update(
            'signin_allowlist',
            ['status' => self::ACTIVE, 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
    }

    public function remove(int $id): void
    {
        $this->db->execute('DELETE FROM {signin_allowlist} WHERE id = ?', [$id]);
    }

    // -------------------------------------------------------------- reading

    /**
     * @return array{items: list<array<string, mixed>>, total: int, pages: int}
     */
    public function page(string $search = '', int $page = 1, int $perPage = 50): array
    {
        $where = '1=1';
        $args = [];

        if (trim($search) !== '') {
            $where .= ' AND email LIKE ?';
            $args[] = '%' . $this->db->escapeLike(trim($search)) . '%';
        }

        $total = (int) $this->db->value("SELECT COUNT(*) FROM {signin_allowlist} WHERE {$where}", $args);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->all(
            "SELECT * FROM {signin_allowlist} WHERE {$where}
              ORDER BY status ASC, email ASC
              LIMIT {$perPage} OFFSET {$offset}",
            $args
        );

        return [
            'items' => $rows,
            'total' => $total,
            'pages' => (int) max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function activeCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {signin_allowlist} WHERE status = ?',
            [self::ACTIVE]
        );
    }
}
