<?php

declare(strict_types=1);

namespace Portal\Feeds;

use Portal\Db;

/**
 * A member's personal calendar feed, and what goes in it.
 *
 * # THE TOKEN IS THE WHOLE OF THE AUTHENTICATION
 *
 * A calendar application cannot log in: it fetches a URL on a timer with no
 * session and nobody watching. So the string in that URL is the entire access
 * decision for somebody's rota, their sign-ups, and the dates a schedule names
 * them on.
 *
 * Nobody has one until they ask — no row until the button is pressed, so a URL
 * cannot be guessed for an account that never wanted a feed. Replacing it stops
 * every subscriber at once, which is the point rather than a side effect: a feed
 * has no idea who is reading it, so there is no list to revoke from.
 */
final class CalendarFeedRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function forUser(int $userId): ?array
    {
        return $this->db->first('SELECT * FROM {calendar_feeds} WHERE user_id = ?', [$userId]);
    }

    /**
     * Make one, or replace the one there is.
     *
     * The same method for both, because they are the same act: a new token, and
     * the old one stops working. Separating them would invite a "rotate" that
     * kept the old one alive for a grace period, which is precisely what somebody
     * dealing with a leak does not want.
     *
     * @return string the new token
     */
    public function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO {calendar_feeds} (user_id, feed_token, created_at, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE feed_token = VALUES(feed_token),
                /*
                 * The counters reset with the token. They describe a
                 * subscription, and replacing the token ended every one — a
                 * "last fetched" carried over from the old URL would have a
                 * member believing the new one was working before anything had
                 * ever asked for it.
                 */
                last_used_at = NULL, fetches = 0, updated_at = VALUES(updated_at)',
            [$userId, $token, $now, $now]
        );

        return $token;
    }

    /** Stop the feed entirely. */
    public function revoke(int $userId): void
    {
        $this->db->execute('DELETE FROM {calendar_feeds} WHERE user_id = ?', [$userId]);
    }

    /**
     * Whose feed this is, by token.
     *
     * Compared in the database by a UNIQUE index rather than by fetching rows
     * and comparing in PHP. A token long enough to be unguessable is also long
     * enough that timing differences in a string compare are not the attack
     * anybody would choose, and an indexed lookup is what keeps a feed fetched
     * every fifteen minutes by a dozen phones cheap.
     */
    public function userFor(string $token): ?int
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            // Refused on shape before it reaches the database. A feed URL is
            // crawled and guessed at constantly; none of that should become a
            // query.
            return null;
        }

        $id = $this->db->value('SELECT user_id FROM {calendar_feeds} WHERE feed_token = ?', [$token]);

        return $id === null ? null : (int) $id;
    }

    /**
     * Note that a calendar fetched it.
     *
     * The only evidence a member has that the feed works at all: a subscription
     * that silently stopped looks exactly like a rota with nothing on it.
     */
    public function touch(int $userId): void
    {
        $this->db->execute(
            'UPDATE {calendar_feeds} SET last_used_at = NOW(), fetches = fetches + 1 WHERE user_id = ?',
            [$userId]
        );
    }

    // --------------------------------------------------------- what goes in

    /**
     * Everything one person is down for.
     *
     * Three sources, because a member's diary is not one table: the rota asks
     * them, events they signed up to, and the schedules calendar that names
     * them without their ever having an account in it.
     *
     * A DECLINED ROTA DATE IS INCLUDED, marked cancelled. Omitting it leaves it
     * on the phone of the one person who already synced — the person who said
     * no, who then turns up. That is why `state` comes back rather than being
     * filtered in SQL.
     *
     * @return list<array<string, mixed>>
     */
    public function rotaFor(int $userId, int $days = 180): array
    {
        return $this->db->all(
            'SELECT a.id, a.state, s.title AS service_title, s.starts_at,
                    t.name AS team_name, p.name AS position_name
               FROM {rota_assignments} a
               INNER JOIN {rota_services} s ON s.id = a.service_id
               INNER JOIN {rota_teams} t ON t.id = a.team_id
               LEFT JOIN {rota_positions} p ON p.id = a.position_id
              WHERE a.user_id = ?
                AND s.starts_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND s.starts_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
              ORDER BY s.starts_at',
            [$userId, $days]
        );
    }

    /** @return list<array<string, mixed>> */
    public function signUpsFor(int $userId, int $days = 180): array
    {
        return $this->db->all(
            'SELECT g.id, g.state, e.title, e.slug, e.starts_at, e.ends_at, e.location, e.timezone
               FROM {event_signups} g
               INNER JOIN {events} e ON e.id = g.event_id
              WHERE g.user_id = ? AND g.state = "going"
                AND e.starts_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND e.starts_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
              ORDER BY e.starts_at',
            [$userId, $days]
        );
    }

    /**
     * The schedules calendar, for a name linked to this account.
     *
     * Linked is the only connection there is — those rotas are kept as names
     * rather than accounts, which is the whole shape of that section.
     *
     * @return list<array<string, mixed>>
     */
    public function scheduleDatesFor(int $userId, int $days = 180): array
    {
        return $this->db->all(
            'SELECT e.id, e.on_date, e.role, s.name AS schedule_name
               FROM {schedule_entries} e
               INNER JOIN {schedules} s ON s.id = e.schedule_id AND s.is_enabled = 1
               INNER JOIN {schedule_people} p ON p.id = e.person_id
              WHERE p.user_id = ?
                AND e.on_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                AND e.on_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              ORDER BY e.on_date',
            [$userId, $days]
        );
    }
}
