<?php

declare(strict_types=1);

namespace Portal\Events;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Events, and the list of who is coming.
 *
 * # THE RULE: "IS THERE ROOM" IS DECIDED UNDER A ROW LOCK
 *
 * Four people press the button in the same second. They must get one yes and
 * three waiting-list places — not four yeses and an overbooked hall.
 *
 * The version that overbooks is the obvious one: read the capacity, count what
 * is taken, decide, then write. Every one of the four reads the same count,
 * every one decides there is room, and all four write. Nothing in that sequence
 * is wrong on its own; the bug is entirely in the gap between the read and the
 * write.
 *
 * So the event row is locked with SELECT ... FOR UPDATE and everything after it
 * — the count, the decision, the insert — happens inside the same transaction.
 * The four requests serialise on that row: the first reads a count of nought,
 * the second reads a count of one, and so on. InnoDB gives us this; the spec's
 * platform notes say to port it unchanged from Postgres and they are right, it
 * is one of the places the two behave the same.
 *
 * The lock is on the EVENT, not on the sign-ups, and that is deliberate: it is
 * the thing every contender has in common, and locking rows in a table people
 * are inserting into would not stop a new insert from a different transaction.
 */
final class EventRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------- reading

    /**
     * Events somebody may see, soonest first.
     *
     * A members-only event is INVISIBLE rather than refused: leaving it in the
     * list and rejecting the click would tell a stranger there is an event and
     * roughly what it is called, which is the same leak this codebase refuses
     * everywhere else.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(bool $includeMemberOnly, bool $includeUnpublished = false, int $limit = 100): array
    {
        $where = ['starts_at >= NOW()'];

        if (!$includeUnpublished) {
            $where[] = 'is_published = 1';
        }

        if (!$includeMemberOnly) {
            $where[] = 'member_only = 0';
        }

        return $this->db->all(
            'SELECT * FROM {events} WHERE ' . implode(' AND ', $where)
            . ' ORDER BY starts_at ASC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {events} WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM {events} WHERE slug = ?', [$slug]);
    }

    /**
     * How many places are taken.
     *
     * Only 'going' counts. A waiting-list place is not a place in the hall and
     * a cancellation is not either — counting them would make an event look
     * full while seats stood empty, which is the failure people never report
     * because it looks like the event was popular.
     */
    public function taken(int $eventId): int
    {
        return (int) $this->db->value(
            'SELECT COALESCE(SUM(party_size), 0) FROM {event_signups}
              WHERE event_id = ? AND state = ?',
            [$eventId, Signup::GOING]
        );
    }

    /**
     * The list, for the organiser.
     *
     * Ordered by state then by when they joined, so the hall list reads in one
     * direction and the waiting list is in the order it will be called.
     *
     * @return list<array<string, mixed>>
     */
    public function signups(int $eventId): array
    {
        return $this->db->all(
            "SELECT s.*, u.id IS NOT NULL AS is_member
               FROM {event_signups} s
               LEFT JOIN {users} u ON u.id = s.user_id
              WHERE s.event_id = ?
              ORDER BY FIELD(s.state, 'going', 'waiting', 'cancelled'), s.id",
            [$eventId]
        );
    }

    // ------------------------------------------------------------- writing

    /**
     * Sign somebody up. THE LOCKED PATH.
     *
     * Everything that decides whether there is room happens between the
     * SELECT ... FOR UPDATE and the COMMIT. Nothing is read before the lock and
     * used after it.
     *
     * @return SignupResult what happened, and why
     */
    public function signUp(
        int $eventId,
        string $name,
        string $email,
        int $guests = 0,
        ?int $userId = null,
        string $phone = '',
        string $note = ''
    ): SignupResult {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        $guests = max(0, $guests);

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::badRequest('A name and an email address are both needed.');
        }

        return $this->db->transaction(function () use ($eventId, $name, $email, $guests, $userId, $phone, $note): SignupResult {
            /*
             * THE LOCK. Everything below reads a world that cannot change until
             * this transaction ends.
             */
            $event = $this->db->first('SELECT * FROM {events} WHERE id = ? FOR UPDATE', [$eventId]);

            if ($event === null) {
                throw HttpException::notFound('There is no event at that address.');
            }

            if ((int) $event['max_guests'] < $guests) {
                throw HttpException::badRequest(sprintf(
                    'You can bring up to %d with you for this one.',
                    (int) $event['max_guests']
                ));
            }

            $state = SignupWindow::state($event, date('Y-m-d H:i:s'));

            if ($state !== SignupWindow::OPEN) {
                throw HttpException::badRequest(SignupWindow::explain($state, (string) ($event['signup_opens_at'] ?? '')));
            }

            $party = $guests + 1;
            $free = WaitingList::freePlaces(
                $event['capacity'] === null ? null : (int) $event['capacity'],
                $this->taken($eventId)
            );

            /*
             * An existing row is reused rather than refused. Somebody who
             * cancelled and changed their mind is the same person; a second row
             * would put them on the list twice and count their party twice.
             *
             * Their OWN party is added back to the free places when they are
             * already going, so changing "plus one" to "plus two" is not
             * refused for want of a place they already hold.
             */
            $existing = $this->db->first(
                'SELECT * FROM {event_signups} WHERE event_id = ? AND email = ?',
                [$eventId, $email]
            );

            if ($existing !== null && $existing['state'] === Signup::GOING) {
                $free += (int) $existing['party_size'];
            }

            $going = $party <= $free;
            $newState = $going ? Signup::GOING : Signup::WAITING;
            $now = date('Y-m-d H:i:s');

            if ($existing !== null) {
                /*
                 * The token is kept, not reissued. A link somebody was given
                 * when they first signed up has to keep working — reissuing on
                 * every edit would silently break the one they saved.
                 */
                $token = (string) ($existing['token'] ?? '') ?: $this->newToken();

                $this->db->execute(
                    'UPDATE {event_signups}
                        SET name = ?, phone = ?, guests = ?, state = ?, note = ?, token = ?,
                            user_id = COALESCE(?, user_id), updated_at = ?
                      WHERE id = ?',
                    [$name, trim($phone) ?: null, $guests, $newState, trim($note) ?: null, $token,
                     $userId, $now, (int) $existing['id']]
                );

                $id = (int) $existing['id'];
            } else {
                $token = $this->newToken();

                $id = (int) $this->db->insert('event_signups', [
                    'event_id'   => $eventId,
                    'name'       => mb_substr($name, 0, 190),
                    'email'      => mb_substr($email, 0, 190),
                    'phone'      => mb_substr(trim($phone), 0, 60) ?: null,
                    'user_id'    => $userId,
                    'guests'     => $guests,
                    'state'      => $newState,
                    'token'      => $token,
                    'note'       => mb_substr(trim($note), 0, 500) ?: null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return new SignupResult($newState, $id, $party, $token);
        });
    }

    /**
     * Cancel, and let the queue move.
     *
     * Both halves in one transaction under the same lock. A cancellation that
     * committed before the promotion ran would leave a window in which the
     * places are free and nobody has been moved into them — which is the window
     * a fifth person walks into, taking a place the family at the front of the
     * queue had already been promised.
     *
     * @return list<int> the sign-up ids that moved up, so they can be told
     */
    public function cancel(int $eventId, string $email): array
    {
        $email = mb_strtolower(trim($email));

        return $this->db->transaction(function () use ($eventId, $email): array {
            $this->db->first('SELECT id FROM {events} WHERE id = ? FOR UPDATE', [$eventId]);

            $changed = $this->db->execute(
                'UPDATE {event_signups} SET state = ?, updated_at = NOW()
                  WHERE event_id = ? AND email = ? AND state <> ?',
                [Signup::CANCELLED, $eventId, $email, Signup::CANCELLED]
            );

            if ($changed < 1) {
                return [];
            }

            return $this->promoteInsideLock($eventId);
        });
    }

    /**
     * Cancel with the token from the link, no account and no session.
     *
     * The token IS the authority, exactly as it is for unsubscribing. It is
     * format-checked before any lookup — the same rule share ids follow — so a
     * malformed one never reaches the database.
     *
     * @return list<int> the ids that moved up
     */
    public function cancelByToken(string $token): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) !== 1) {
            return [];
        }

        $row = $this->db->first(
            'SELECT event_id, email FROM {event_signups} WHERE token = ?',
            [$token]
        );

        if ($row === null) {
            return [];
        }

        return $this->cancel((int) $row['event_id'], (string) $row['email']);
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) !== 1) {
            return null;
        }

        return $this->db->first('SELECT * FROM {event_signups} WHERE token = ?', [$token]);
    }

    /**
     * What one person is signed up for, by address.
     *
     * By EMAIL rather than by user id, because a sign-up made before somebody
     * had an account is still theirs — and matching on the address is what
     * makes their history already be there the moment they create one. Same
     * reasoning as the notification record.
     *
     * @return list<array<string, mixed>>
     */
    public function signupsFor(string $email, bool $upcomingOnly = true): array
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return [];
        }

        $where = $upcomingOnly ? ' AND e.starts_at >= NOW()' : '';

        return $this->db->all(
            "SELECT s.*, e.title, e.slug, e.starts_at, e.location
               FROM {event_signups} s
               INNER JOIN {events} e ON e.id = s.event_id
              WHERE s.email = ? AND s.state <> ?{$where}
              ORDER BY e.starts_at ASC",
            [$email, Signup::CANCELLED]
        );
    }

    /**
     * 22 characters of base64url randomness, the same shape share ids and
     * subscription tokens use.
     */
    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * Move the queue up after places come free.
     *
     * Public because a capacity RISE is the other thing that frees places, and
     * the admin screen that raises it has to be able to run this — the spec
     * names both: "moves on a cancellation and on a capacity rise".
     *
     * @return list<int> the ids that moved
     */
    public function promote(int $eventId): array
    {
        return $this->db->transaction(function () use ($eventId): array {
            $this->db->first('SELECT id FROM {events} WHERE id = ? FOR UPDATE', [$eventId]);

            return $this->promoteInsideLock($eventId);
        });
    }

    /**
     * The walk itself. ONLY EVER CALLED WITH THE EVENT ROW LOCKED.
     *
     * Private, and named so, because a promotion decided on an unlocked read is
     * the same defect as an unlocked sign-up wearing a different hat: it reads
     * how many places are free, and by the time it writes somebody else has
     * taken one.
     *
     * @return list<int>
     */
    private function promoteInsideLock(int $eventId): array
    {
        $event = $this->db->first('SELECT capacity FROM {events} WHERE id = ?', [$eventId]);

        if ($event === null || $event['capacity'] === null) {
            /*
             * No limit. Anybody on the waiting list of an unlimited event is
             * there because the limit was removed, so they all move.
             */
            $everyone = $this->db->column(
                'SELECT id FROM {event_signups} WHERE event_id = ? AND state = ? ORDER BY id',
                [$eventId, Signup::WAITING]
            );

            $ids = array_map('intval', $everyone);
        } else {
            $free = WaitingList::freePlaces((int) $event['capacity'], $this->taken($eventId));

            $queue = $this->db->all(
                'SELECT id, party_size FROM {event_signups}
                  WHERE event_id = ? AND state = ?
                  ORDER BY id',
                [$eventId, Signup::WAITING]
            );

            $ids = WaitingList::promote($free, array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'party_size' => (int) $row['party_size'],
                ],
                $queue
            ));
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $this->db->execute(
            "UPDATE {event_signups} SET state = ?, updated_at = NOW() WHERE id IN ({$placeholders})",
            array_merge([Signup::GOING], $ids)
        );

        return $ids;
    }

    // -------------------------------------------------------- editing them

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): int
    {
        $title = trim((string) ($attributes['title'] ?? ''));

        if ($title === '') {
            throw HttpException::badRequest('An event needs a name.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('events', [
            'slug'             => $this->uniqueSlug($title),
            'title'            => mb_substr($title, 0, 190),
            'description'      => trim((string) ($attributes['description'] ?? '')) ?: null,
            'location'         => mb_substr(trim((string) ($attributes['location'] ?? '')), 0, 190) ?: null,
            'starts_at'        => $this->wallClock((string) ($attributes['starts_at'] ?? '')),
            'ends_at'          => isset($attributes['ends_at']) && trim((string) $attributes['ends_at']) !== ''
                ? $this->wallClock((string) $attributes['ends_at'])
                : null,
            'timezone'         => mb_substr(trim((string) ($attributes['timezone'] ?? '')), 0, 64) ?: null,
            'is_published'     => !empty($attributes['is_published']) ? 1 : 0,
            'member_only'      => !empty($attributes['member_only']) ? 1 : 0,
            'signup_enabled'   => !empty($attributes['signup_enabled']) ? 1 : 0,
            'capacity'         => isset($attributes['capacity']) && $attributes['capacity'] !== ''
                ? max(0, (int) $attributes['capacity'])
                : null,
            'max_guests'       => max(0, (int) ($attributes['max_guests'] ?? 0)),
            'signup_opens_at'  => isset($attributes['signup_opens_at']) && trim((string) $attributes['signup_opens_at']) !== ''
                ? $this->wallClock((string) $attributes['signup_opens_at'])
                : null,
            'signup_closes_at' => isset($attributes['signup_closes_at']) && trim((string) $attributes['signup_closes_at']) !== ''
                ? $this->wallClock((string) $attributes['signup_closes_at'])
                : null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    public function setCapacity(int $eventId, ?int $capacity): void
    {
        $this->db->execute(
            'UPDATE {events} SET capacity = ?, updated_at = NOW() WHERE id = ?',
            [$capacity === null ? null : max(0, $capacity), $eventId]
        );
    }

    public function publish(int $eventId, bool $published): void
    {
        $this->db->execute(
            'UPDATE {events} SET is_published = ?, updated_at = NOW() WHERE id = ?',
            [$published ? 1 : 0, $eventId]
        );
    }

    // --------------------------------------------------------- internals

    private function wallClock(string $raw): string
    {
        $stamp = strtotime(str_replace('T', ' ', trim($raw)));

        if ($stamp === false) {
            throw HttpException::badRequest('That is not a date and time this can read.');
        }

        return date('Y-m-d H:i:s', $stamp);
    }

    private function uniqueSlug(string $desired): string
    {
        $base = Str::slug($desired) ?: 'event';
        $slug = $base;
        $suffix = 1;

        while ($this->db->value('SELECT id FROM {events} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
