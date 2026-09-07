<?php

declare(strict_types=1);

namespace Portal\Prayer;

use Portal\Db;
use Portal\Http\HttpException;

/**
 * The prayer wall.
 *
 * Everything that leaves here for a screen is a PrayerRequest, moderation
 * included — see that class for why the type matters more than the care taken
 * by whoever writes the next template.
 */
final class PrayerRepository
{
    public const PENDING  = 'pending';
    public const APPROVED = 'approved';
    public const REMOVED  = 'removed';

    /** Who may read a request. */
    public const EVERYONE = 'everyone';
    public const MEMBERS  = 'members';
    public const LEADERS  = 'leaders';

    /** How long a request may be. Long enough to say what happened. */
    private const MAX_BODY = 2000;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Which visibilities somebody may read.
     *
     * ONE function, so the wall, the counters and any future feed cannot
     * disagree — and it is written as a widening list rather than a comparison,
     * because "leaders can see members' requests" is a fact about this list and
     * not something a `>=` on an enum should be trusted to imply.
     *
     * @return list<string>
     */
    public static function readable(bool $isMember, bool $isLeader): array
    {
        if ($isLeader) {
            return [self::EVERYONE, self::MEMBERS, self::LEADERS];
        }

        if ($isMember) {
            return [self::EVERYONE, self::MEMBERS];
        }

        return [self::EVERYONE];
    }

    public static function visibility(string $raw): string
    {
        return in_array($raw, [self::EVERYONE, self::MEMBERS, self::LEADERS], true)
            ? $raw
            // The strictest of the three, not the loosest. An unrecognised
            // value is a mistake somewhere, and the safe way to be wrong about
            // a prayer request is to show it to fewer people.
            : self::LEADERS;
    }

    /**
     * The wall.
     *
     * Only approved requests, ever. There is no argument that includes pending
     * ones — the moderation queue has its own method, so no caller can widen
     * this one by passing a flag.
     *
     * @param list<string> $visibilities
     * @return list<PrayerRequest>
     */
    public function wall(array $visibilities, int $limit = 100): array
    {
        if ($visibilities === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($visibilities), '?'));

        return array_map(
            static fn (array $row): PrayerRequest => PrayerRequest::from($row),
            $this->db->all(
                "SELECT * FROM {prayer_requests}
                  WHERE status = 'approved' AND visibility IN ({$marks})
                  ORDER BY answered_at IS NULL DESC, created_at DESC
                  LIMIT " . max(1, min(500, $limit)),
                $visibilities
            )
        );
    }

    /**
     * What is waiting to be read.
     *
     * Returns the same type the wall does, which is the anonymity rule: a
     * moderator sees "Anonymous" too.
     *
     * @return list<PrayerRequest>
     */
    public function queue(int $limit = 100): array
    {
        return array_map(
            static fn (array $row): PrayerRequest => PrayerRequest::from($row),
            $this->db->all(
                "SELECT * FROM {prayer_requests} WHERE status = 'pending'
                  ORDER BY created_at ASC LIMIT " . max(1, min(500, $limit))
            )
        );
    }

    public function waitingCount(): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM {prayer_requests} WHERE status = 'pending'"
        );
    }

    /** @return list<PrayerRequest> */
    public function removed(int $limit = 50): array
    {
        return array_map(
            static fn (array $row): PrayerRequest => PrayerRequest::from($row),
            $this->db->all(
                "SELECT * FROM {prayer_requests} WHERE status = 'removed'
                  ORDER BY updated_at DESC LIMIT " . max(1, min(200, $limit))
            )
        );
    }

    public function find(int $id): ?PrayerRequest
    {
        $row = $this->db->first('SELECT * FROM {prayer_requests} WHERE id = ?', [$id]);

        return $row === null ? null : PrayerRequest::from($row);
    }

    /**
     * Ask for prayer.
     *
     * ALWAYS pending. There is no argument for anything else, so no caller can
     * post straight to the wall — not a plugin, not an import, not a future
     * screen written in a hurry.
     */
    public function add(
        string $body,
        string $name,
        bool $anonymous,
        string $visibility,
        ?int $userId = null
    ): int {
        $body = trim($body);

        if ($body === '') {
            throw HttpException::badRequest('There is nothing to pray for here.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('prayer_requests', [
            'body'           => mb_substr($body, 0, self::MAX_BODY),
            /*
             * An anonymous request stores NO NAME AT ALL, rather than storing
             * one and hiding it. A name kept "just in case" is a name that
             * leaks the first time somebody writes a query by hand — and there
             * is no case: this wall does not follow anybody up.
             */
            'requester_name' => $anonymous ? null : (mb_substr(trim($name), 0, 120) ?: null),
            'is_anonymous'   => $anonymous ? 1 : 0,
            /*
             * The account IS kept for a non-anonymous request, so somebody can
             * withdraw their own. For an anonymous one it is not: linking the
             * two is exactly the thing being promised against.
             */
            'user_id'        => $anonymous ? null : $userId,
            'visibility'     => self::visibility($visibility),
            'status'         => self::PENDING,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }

    public function approve(int $id, string $by): void
    {
        $this->db->execute(
            "UPDATE {prayer_requests}
                SET status = 'approved', approved_at = NOW(), approved_by = ?, updated_at = NOW()
              WHERE id = ?",
            [mb_substr(trim($by), 0, 190) ?: null, $id]
        );
    }

    /**
     * Take one off the wall.
     *
     * Kept as a row rather than deleted, so a moderator can see what they
     * removed and put it back — and so a second moderator does not find it
     * waiting in the queue again with no sign it was ever dealt with.
     */
    public function remove(int $id): void
    {
        $this->db->execute(
            "UPDATE {prayer_requests} SET status = 'removed', updated_at = NOW() WHERE id = ?",
            [$id]
        );
    }

    public function restore(int $id): void
    {
        $this->db->execute(
            "UPDATE {prayer_requests} SET status = 'pending', updated_at = NOW() WHERE id = ?",
            [$id]
        );
    }

    public function setVisibility(int $id, string $visibility): void
    {
        $this->db->execute(
            'UPDATE {prayer_requests} SET visibility = ?, updated_at = NOW() WHERE id = ?',
            [self::visibility($visibility), $id]
        );
    }

    /**
     * Answered, and STILL ON THE WALL.
     *
     * Taking a request down when it is answered removes the half of the wall
     * worth reading — and it is the half that makes somebody put the next one
     * up.
     */
    public function markAnswered(int $id, string $note): void
    {
        $this->db->execute(
            'UPDATE {prayer_requests} SET answered_at = NOW(), answer_note = ?, updated_at = NOW()
              WHERE id = ?',
            [mb_substr(trim($note), 0, 1000) ?: null, $id]
        );
    }

    public function markUnanswered(int $id): void
    {
        $this->db->execute(
            'UPDATE {prayer_requests} SET answered_at = NULL, answer_note = NULL, updated_at = NOW()
              WHERE id = ?',
            [$id]
        );
    }

    /**
     * "I prayed for this."
     *
     * A COUNT, and there is no table of who. Not even hashed: the only way to
     * be certain a list cannot leak is not to keep one. The "you already did"
     * memory lives in a cookie on the device, so somebody who clears theirs can
     * press it twice — which is a prayer counter, and inflating it is not an
     * attack anybody needs defending from.
     *
     * Only counts for a request that is actually on the wall, so a pending or
     * removed one cannot be quietly incremented by a crafted request and give
     * away that it exists.
     */
    public function pray(int $id): bool
    {
        return $this->db->execute(
            "UPDATE {prayer_requests} SET prayed_count = prayed_count + 1
              WHERE id = ? AND status = 'approved'",
            [$id]
        ) > 0;
    }

    /**
     * What somebody put up themselves, so they can take it down.
     *
     * Anonymous requests are absent BY CONSTRUCTION rather than by a filter:
     * add() stores no user id for one, so there is nothing here to match. The
     * cost is stated on the form — an anonymous request cannot be withdrawn,
     * because withdrawing it would mean the site knew whose it was.
     *
     * @return list<PrayerRequest>
     */
    public function mine(int $userId): array
    {
        return array_map(
            static fn (array $row): PrayerRequest => PrayerRequest::from($row),
            $this->db->all(
                "SELECT * FROM {prayer_requests}
                  WHERE user_id = ? AND status <> 'removed'
                  ORDER BY created_at DESC LIMIT 50",
                [$userId]
            )
        );
    }

    /** Ownership in the WHERE clause, so an id alone is not enough. */
    public function withdraw(int $id, int $userId): bool
    {
        return $this->db->execute(
            "UPDATE {prayer_requests} SET status = 'removed', updated_at = NOW()
              WHERE id = ? AND user_id = ?",
            [$id, $userId]
        ) > 0;
    }
}
