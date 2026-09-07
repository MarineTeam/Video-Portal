<?php

declare(strict_types=1);

namespace Portal\Groups;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * The small-group directory.
 *
 * Two rules carry this one, and both are counter-intuitive enough to be worth
 * reading before changing anything:
 *
 *  - AN UNANSWERED REQUEST HOLDS A PLACE. See taken().
 *  - PROMOTION MOVES SOMEBODY TO A REQUEST, NEVER INTO THE GROUP. See promote().
 *
 * Everything that leaves here for a screen is a GroupCard, which has no address
 * property. GroupAddress is the only function that produces one.
 */
final class GroupRepository
{
    /** Asked, and waiting for the leader to answer. HOLDS A PLACE. */
    public const REQUESTED = 'requested';

    /** In the group. */
    public const MEMBER = 'member';

    /** In the group, and leading it. Not staff — see the migration. */
    public const LEADING = 'leader';

    /** The group was full when they asked. Holds no place. */
    public const WAITING = 'waiting';

    /** The leader said no. Holds no place. */
    public const DECLINED = 'declined';

    /** They were in and are not any more. Holds no place. */
    public const LEFT = 'left';

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------ reading

    /**
     * The directory.
     *
     * @return list<GroupCard>
     */
    public function directory(?int $viewerId = null, bool $includeUnpublished = false): array
    {
        $rows = $this->db->all(
            'SELECT * FROM {small_groups}'
            . ($includeUnpublished ? '' : ' WHERE is_published = 1')
            . ' ORDER BY name'
        );

        return array_map(
            fn (array $row): GroupCard => $this->card($row, $viewerId),
            $rows
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {small_groups} WHERE id = ?', [$id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM {small_groups} WHERE slug = ?', [$slug]);
    }

    /**
     * One group, as this person may see it.
     *
     * @param array<string, mixed> $row
     */
    public function card(array $row, ?int $viewerId = null): GroupCard
    {
        $id = (int) $row['id'];
        $mine = $viewerId === null ? null : $this->stateOf($id, $viewerId);

        return GroupCard::from(
            $row,
            $this->taken($id),
            $this->leaderNames($id),
            $mine,
            $mine === self::LEADING
        );
    }

    /**
     * HOW MANY PLACES ARE TAKEN.
     *
     * Members AND unanswered requests. This is the rule that is wrong in every
     * obvious implementation, and it was found by a database test rather than
     * by reading:
     *
     * Counting only members reads as the honest number — "four people are in
     * this group" — but promotion moves somebody from the waiting list to
     * REQUESTED, which is not membership. So the place they were promoted into
     * would still look free on the next run, and the next person would be
     * promoted into the same place, and the next, until everybody on the list
     * had been told a place was theirs and all but one of them was wrong.
     *
     * So an unanswered request holds a place, and a decline gives it back.
     */
    public function taken(int $groupId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {small_group_members}
              WHERE group_id = ? AND state IN (?, ?, ?)',
            [$groupId, self::MEMBER, self::LEADING, self::REQUESTED]
        );
    }

    public function freePlaces(int $groupId, ?int $capacity): int
    {
        // No capacity means no limit, and PHP_INT_MAX rather than a special
        // case every caller has to remember — the same shape the events
        // waiting list uses.
        return $capacity === null ? PHP_INT_MAX : max(0, $capacity - $this->taken($groupId));
    }

    public function stateOf(int $groupId, int $userId): ?string
    {
        $row = $this->db->first(
            'SELECT role, state FROM {small_group_members} WHERE group_id = ? AND user_id = ?',
            [$groupId, $userId]
        );

        if ($row === null) {
            return null;
        }

        /*
         * A leader is reported as LEADING rather than as a member, so the one
         * function that decides about the address has a single value to look
         * at rather than two fields to combine — and so no caller can check the
         * state and forget the role.
         */
        if ((string) $row['state'] === self::MEMBER && (string) $row['role'] === self::LEADING) {
            return self::LEADING;
        }

        return (string) $row['state'];
    }

    /** @return list<string> */
    public function leaderNames(int $groupId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['person_name'],
            $this->db->all(
                'SELECT COALESCE(NULLIF(u.name, ""), u.email) AS person_name
                   FROM {small_group_members} m
                   INNER JOIN {users} u ON u.id = m.user_id
                  WHERE m.group_id = ? AND m.role = ? AND m.state = ?
                  ORDER BY person_name',
                [$groupId, self::LEADING, self::MEMBER]
            )
        );
    }

    /**
     * Groups with nobody leading them.
     *
     * Flagged to whoever keeps the list, because a group with no leader still
     * appears in the directory and still takes requests — and the requests go
     * to nobody.
     *
     * @return list<GroupCard>
     */
    public function leaderless(): array
    {
        return array_values(array_filter(
            $this->directory(null, true),
            static fn (GroupCard $card): bool => $card->needsALeader()
        ));
    }

    /**
     * Who is in, who has asked, and who is waiting.
     *
     * @return list<array<string, mixed>>
     */
    public function people(int $groupId): array
    {
        return $this->db->all(
            'SELECT m.*, COALESCE(NULLIF(u.name, ""), u.email) AS person_name, u.email
               FROM {small_group_members} m
               INNER JOIN {users} u ON u.id = m.user_id
              WHERE m.group_id = ?
              ORDER BY FIELD(m.state, ?, ?, ?, ?, ?), m.requested_at, m.id',
            [$groupId, self::MEMBER, self::REQUESTED, self::WAITING, self::DECLINED, self::LEFT]
        );
    }

    /** @return list<GroupCard> */
    public function mine(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT g.* FROM {small_groups} g
               INNER JOIN {small_group_members} m ON m.group_id = g.id
              WHERE m.user_id = ? AND m.state IN (?, ?, ?)
              ORDER BY g.name',
            [$userId, self::MEMBER, self::REQUESTED, self::WAITING]
        );

        return array_map(fn (array $row): GroupCard => $this->card($row, $userId), $rows);
    }

    // ------------------------------------------------------------ writing

    public function create(string $name): int
    {
        $name = trim($name);

        if ($name === '') {
            throw HttpException::badRequest('A group needs a name.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('small_groups', [
            'slug'         => $this->uniqueSlug($name),
            'name'         => mb_substr($name, 0, 190),
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): void
    {
        // Absent means leave alone, not clear it. See the forms repository for
        // what the other reading costs.
        $sets = [];
        $params = [];

        foreach (['name' => 190, 'description' => 5000, 'area' => 190, 'address' => 300, 'meets' => 190] as $field => $limit) {
            if (array_key_exists($field, $attributes)) {
                $sets[] = "{$field} = ?";
                $value = mb_substr(trim((string) $attributes[$field]), 0, $limit);
                $params[] = $field === 'name' ? $value : ($value === '' ? null : $value);
            }
        }

        if (array_key_exists('capacity', $attributes)) {
            $capacity = (int) $attributes['capacity'];
            $sets[] = 'capacity = ?';
            // Zero and below mean "no limit" rather than "nobody", which is the
            // only reading of an empty box that is not a group nobody can join.
            $params[] = $capacity > 0 ? $capacity : null;
        }

        if (array_key_exists('is_published', $attributes)) {
            $sets[] = 'is_published = ?';
            $params[] = !empty($attributes['is_published']) ? 1 : 0;
        }

        if ($sets === []) {
            return;
        }

        $params[] = $id;

        $this->db->execute(
            'UPDATE {small_groups} SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?',
            $params
        );
    }

    /**
     * Ask to join.
     *
     * Full groups produce a WAITING row rather than a refusal, and the decision
     * is made here rather than by the caller so the two cannot disagree about
     * what "full" means.
     *
     * In a transaction with the capacity read, so two people asking for the
     * last place at the same moment cannot both be told they have it.
     */
    public function ask(int $groupId, int $userId, string $note = ''): string
    {
        return $this->db->transaction(function () use ($groupId, $userId, $note): string {
            $group = $this->db->first('SELECT * FROM {small_groups} WHERE id = ? FOR UPDATE', [$groupId]);

            if ($group === null) {
                throw HttpException::notFound('There is no group here.');
            }

            $existing = $this->stateOf($groupId, $userId);

            // Already in, already asked, already waiting: asking again edits
            // the note rather than making a second row, which is what stops a
            // button anybody can press from filling a leader's screen.
            if (in_array($existing, [self::MEMBER, self::LEADING, self::REQUESTED, self::WAITING], true)) {
                $this->db->execute(
                    'UPDATE {small_group_members} SET note = ?, updated_at = NOW()
                      WHERE group_id = ? AND user_id = ?',
                    [mb_substr(trim($note), 0, 500) ?: null, $groupId, $userId]
                );

                return $existing;
            }

            $capacity = $group['capacity'] === null ? null : (int) $group['capacity'];
            $state = $this->freePlaces($groupId, $capacity) > 0 ? self::REQUESTED : self::WAITING;
            $now = date('Y-m-d H:i:s');

            $this->db->execute(
                'INSERT INTO {small_group_members}
                    (group_id, user_id, role, state, note, requested_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    state = VALUES(state), note = VALUES(note),
                    requested_at = VALUES(requested_at), answered_at = NULL, reply = NULL,
                    updated_at = VALUES(updated_at)',
                [
                    $groupId,
                    $userId,
                    self::MEMBER,
                    $state,
                    mb_substr(trim($note), 0, 500) ?: null,
                    $now,
                    $now,
                    $now,
                ]
            );

            return $state;
        });
    }

    /**
     * The leader says yes.
     *
     * This is the moment the address becomes theirs, which is why it is the
     * leader's decision and not a side effect of a place opening.
     */
    public function accept(int $groupId, int $userId, string $reply = ''): void
    {
        $this->db->execute(
            'UPDATE {small_group_members}
                SET state = ?, role = ?, answered_at = NOW(), reply = ?, updated_at = NOW()
              WHERE group_id = ? AND user_id = ? AND state IN (?, ?)',
            [
                self::MEMBER,
                self::MEMBER,
                mb_substr(trim($reply), 0, 500) ?: null,
                $groupId,
                $userId,
                self::REQUESTED,
                self::WAITING,
            ]
        );
    }

    /**
     * The leader says no, and THE PLACE COMES BACK.
     *
     * Nothing special is needed for that — 'declined' is simply not one of the
     * states taken() counts — but it is the half of the rule people forget, so
     * it is said here as well as there.
     */
    public function decline(int $groupId, int $userId, string $reply = ''): void
    {
        $this->db->execute(
            'UPDATE {small_group_members}
                SET state = ?, answered_at = NOW(), reply = ?, updated_at = NOW()
              WHERE group_id = ? AND user_id = ?',
            [self::DECLINED, mb_substr(trim($reply), 0, 500) ?: null, $groupId, $userId]
        );
    }

    /** Somebody takes themselves out, or withdraws an ask. Also frees a place. */
    public function leave(int $groupId, int $userId): void
    {
        $this->db->execute(
            'UPDATE {small_group_members} SET state = ?, updated_at = NOW()
              WHERE group_id = ? AND user_id = ?',
            [self::LEFT, $groupId, $userId]
        );
    }

    public function setLeader(int $groupId, int $userId, bool $leads): void
    {
        $now = date('Y-m-d H:i:s');

        /*
         * Making somebody a leader puts them IN the group as well. A leader who
         * is not a member is a person who can answer requests to a group they
         * are not in, and — because LEADING is what the address function looks
         * for — a person holding an address for a house they never visit.
         */
        $this->db->execute(
            'INSERT INTO {small_group_members}
                (group_id, user_id, role, state, requested_at, answered_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), state = VALUES(state),
                answered_at = VALUES(answered_at), updated_at = VALUES(updated_at)',
            [
                $groupId,
                $userId,
                $leads ? self::LEADING : self::MEMBER,
                self::MEMBER,
                $now,
                $now,
                $now,
                $now,
            ]
        );
    }

    /**
     * Move people off the waiting list, in order.
     *
     * PROMOTION MOVES SOMEBODY TO A REQUEST, NEVER INTO THE GROUP. A place
     * opening is not the leader's yes, and the leader's yes is what the address
     * travels with — so promoting straight to membership would hand somebody's
     * home address out because a third person happened to leave.
     *
     * Because 'requested' holds a place, each promotion consumes the place it
     * filled, and the loop stops on its own rather than offering one place to
     * everybody on the list.
     *
     * @return list<int> the user ids that moved, in the order they moved
     */
    public function promote(int $groupId): array
    {
        return $this->db->transaction(function () use ($groupId): array {
            $group = $this->db->first('SELECT * FROM {small_groups} WHERE id = ? FOR UPDATE', [$groupId]);

            if ($group === null || $group['capacity'] === null) {
                // No capacity means nobody was ever put on a waiting list by
                // this application, so there is nothing to promote.
                return [];
            }

            $free = $this->freePlaces($groupId, (int) $group['capacity']);
            $moved = [];

            if ($free <= 0) {
                return [];
            }

            $waiting = $this->db->all(
                'SELECT user_id FROM {small_group_members}
                  WHERE group_id = ? AND state = ?
                  ORDER BY requested_at, id
                  LIMIT ' . $free,
                [$groupId, self::WAITING]
            );

            foreach ($waiting as $person) {
                $this->db->execute(
                    'UPDATE {small_group_members}
                        SET state = ?, requested_at = COALESCE(requested_at, NOW()), updated_at = NOW()
                      WHERE group_id = ? AND user_id = ? AND state = ?',
                    [self::REQUESTED, $groupId, (int) $person['user_id'], self::WAITING]
                );

                $moved[] = (int) $person['user_id'];
            }

            return $moved;
        });
    }

    // --------------------------------------------------------- internals

    private function uniqueSlug(string $desired): string
    {
        $base = Str::slug($desired) ?: 'group';
        $slug = $base;
        $suffix = 1;

        while ($this->db->value('SELECT id FROM {small_groups} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
