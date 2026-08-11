<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;

/**
 * Requests for access to the library.
 *
 * The state this models is small — somebody who is signed in, not approved,
 * and has said so — but the two rules around it are what make it safe to put
 * a button in front of an anonymous stranger who has just authenticated.
 *
 * A person may ask once. Asking again edits what they said and notifies
 * nobody, enforced by the PRIMARY KEY rather than by a check-then-write, so
 * two clicks arriving together cannot both decide they are the first.
 */
final class AccessRequests
{
    /** Long enough for a sentence about who you are, short enough not to be an essay. */
    public const MAX_NOTE = 500;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Record a request.
     *
     * @return bool true when this is the first time this person has asked,
     *              which is the only time anybody should be emailed about it
     */
    public function submit(int $userId, string $note): bool
    {
        $note = self::sanitize($note);

        /*
         * INSERT IGNORE rather than a SELECT followed by an INSERT. The
         * question "has this person already asked?" and the act of recording
         * that they have must be the same operation — two requests arriving
         * together would otherwise both read "no" and both send mail.
         *
         * This is the same guard the announcement fire-once uses, for the same
         * reason: MySQL replacing what Redis atomicity used to do.
         */
        $created = $this->db->execute(
            'INSERT IGNORE INTO {access_requests} (user_id, note, created_at) VALUES (?, ?, NOW())',
            [$userId, $note]
        );

        if ($created > 0) {
            return true;
        }

        // They have asked before. Let them correct what they said, but do not
        // let a second click reach anybody's inbox.
        $this->db->execute(
            'UPDATE {access_requests} SET note = ? WHERE user_id = ?',
            [$note, $userId]
        );

        return false;
    }

    /** Has this person already asked? */
    public function has(int $userId): bool
    {
        return $this->db->value('SELECT 1 FROM {access_requests} WHERE user_id = ?', [$userId]) !== null;
    }

    /** What they said, or null if they have not asked. */
    public function noteFor(int $userId): ?string
    {
        $note = $this->db->value('SELECT note FROM {access_requests} WHERE user_id = ?', [$userId]);

        return $note === null ? null : (string) $note;
    }

    public function markNotified(int $userId): void
    {
        $this->db->execute('UPDATE {access_requests} SET notified_at = NOW() WHERE user_id = ?', [$userId]);
    }

    /**
     * The question is answered, so it stops being a question.
     *
     * Called when an account is authorized. Nothing depends on the history of
     * who once asked, and keeping it would mean the table grew forever with
     * rows about people who already have access.
     */
    public function clear(int $userId): void
    {
        $this->db->execute('DELETE FROM {access_requests} WHERE user_id = ?', [$userId]);
    }

    /**
     * Outstanding requests, oldest first, with the account they belong to.
     *
     * Joined to {users} and filtered to unapproved accounts. A row whose
     * account has since been approved by some other route is not a pending
     * request, and showing it would send an administrator to approve somebody
     * who already has access.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 200): array
    {
        return $this->db->all(
            'SELECT r.user_id, r.note, r.created_at, r.notified_at, u.email, u.name
               FROM {access_requests} r
               JOIN {users} u ON u.id = r.user_id
              WHERE u.authorized = 0
              ORDER BY r.created_at ASC
              LIMIT ' . max(1, $limit)
        );
    }

    /**
     * Notes by user id, for a page that already has the accounts.
     *
     * One query for a whole listing rather than one per row — the users screen
     * shows up to two hundred accounts, and a note lookup per line is exactly
     * the shape the query monitor exists to catch.
     *
     * @param list<int> $userIds
     * @return array<int, string>
     */
    public function notesFor(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT user_id, note FROM {access_requests}
              WHERE user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );

        $notes = [];
        foreach ($rows as $row) {
            $notes[(int) $row['user_id']] = (string) $row['note'];
        }

        return $notes;
    }

    /**
     * Trim a note down to something safe to store and show.
     *
     * Control characters are stripped rather than escaped: they cannot help a
     * reader and they can wreck an email, a CSV, and a terminal reading the
     * audit log. Newlines survive, because somebody writing two sentences
     * about themselves is the point.
     */
    public static function sanitize(string $note): string
    {
        $note = str_replace(["\r\n", "\r"], "\n", $note);
        $note = (string) preg_replace('/[^\P{C}\n]+/u', '', $note);
        $note = trim($note);

        return mb_substr($note, 0, self::MAX_NOTE);
    }
}
