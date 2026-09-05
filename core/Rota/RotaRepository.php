<?php

declare(strict_types=1);

namespace Portal\Rota;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Teams, services, and the asks between them.
 *
 * The two rules of this section live at `ask()`, and they are asked in one
 * place so that every way of creating an ask — one at a time, a whole team at
 * once, or whatever comes next — gets the same answers. `AskOutcome` decides;
 * this supplies the facts and does the writing.
 */
final class RotaRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // -------------------------------------------------------------- teams

    /** @return list<array<string, mixed>> */
    public function teams(): array
    {
        return $this->db->all('SELECT * FROM {rota_teams} ORDER BY position, name');
    }

    /** @return array<string, mixed>|null */
    public function team(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {rota_teams} WHERE id = ?', [$id]);
    }

    public function createTeam(string $name, string $description = ''): int
    {
        $name = trim($name);

        if ($name === '') {
            throw HttpException::badRequest('A team needs a name.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('rota_teams', [
            'slug'        => $this->uniqueTeamSlug($name),
            'name'        => mb_substr($name, 0, 120),
            'description' => mb_substr(trim($description), 0, 500) ?: null,
            'position'    => $this->nextTeamPosition(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    /** The jobs within a team, in the order somebody arranged. */
    public function positions(int $teamId): array
    {
        return $this->db->all(
            'SELECT * FROM {rota_positions} WHERE team_id = ? ORDER BY position, name',
            [$teamId]
        );
    }

    public function addPosition(int $teamId, string $name): int
    {
        $name = trim($name);

        if ($name === '') {
            throw HttpException::badRequest('A position needs a name.');
        }

        $next = (int) $this->db->value(
            'SELECT COALESCE(MAX(position), 0) + 10 FROM {rota_positions} WHERE team_id = ?',
            [$teamId]
        );

        return (int) $this->db->insert('rota_positions', [
            'team_id'  => $teamId,
            'name'     => mb_substr($name, 0, 120),
            'position' => $next,
        ]);
    }

    /**
     * Who is on a team, with the job they usually do.
     *
     * @return list<array<string, mixed>>
     */
    public function members(int $teamId): array
    {
        return $this->db->all(
            'SELECT m.user_id, m.position_id, u.name AS person_name, u.email AS person_email,
                    p.name AS position_name
               FROM {rota_team_members} m
               INNER JOIN {users} u ON u.id = m.user_id
               LEFT JOIN {rota_positions} p ON p.id = m.position_id
              WHERE m.team_id = ?
              ORDER BY u.name, u.email',
            [$teamId]
        );
    }

    /**
     * Put somebody on a team, or change the job they usually do.
     *
     * Idempotent: adding a person who is already there updates their usual
     * position rather than failing. The screen offers one button for both
     * because from the organiser's side it is one intention — "this is what
     * they do" — and a form that refuses because somebody is already listed is
     * a form that has to be read before it can be used.
     */
    public function addMember(int $teamId, int $userId, ?int $positionId = null): void
    {
        $this->db->execute(
            'INSERT INTO {rota_team_members} (team_id, user_id, position_id, created_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE position_id = VALUES(position_id)',
            [$teamId, $userId, $positionId]
        );
    }

    public function removeMember(int $teamId, int $userId): void
    {
        /*
         * Their asks are NOT removed with them.
         *
         * Somebody leaving a team has not un-served the services they already
         * accepted, and a rota that rewrote its own past would be lying about
         * who was there. Taking somebody off a team stops them being SUGGESTED;
         * an ask that should not stand is withdrawn on its own.
         */
        $this->db->execute(
            'DELETE FROM {rota_team_members} WHERE team_id = ? AND user_id = ?',
            [$teamId, $userId]
        );
    }

    // ----------------------------------------------------------- services

    /** @return list<array<string, mixed>> */
    public function services(bool $includeUnpublished = false, int $limit = 100): array
    {
        $where = $includeUnpublished ? '' : ' WHERE is_published = 1';

        return $this->db->all(
            "SELECT * FROM {rota_services}{$where}
              ORDER BY starts_at DESC
              LIMIT " . max(1, min(500, $limit))
        );
    }

    /** @return array<string, mixed>|null */
    public function service(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {rota_services} WHERE id = ?', [$id]);
    }

    public function createService(string $title, string $startsAt, string $notes = ''): int
    {
        $title = trim($title);

        if ($title === '') {
            throw HttpException::badRequest('A service needs a name.');
        }

        $when = $this->wallClock($startsAt);

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('rota_services', [
            'title'        => mb_substr($title, 0, 190),
            'starts_at'    => $when,
            'notes'        => trim($notes) ?: null,
            'is_published' => 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    public function publishService(int $id, bool $published): void
    {
        $this->db->execute(
            'UPDATE {rota_services} SET is_published = ?, updated_at = NOW() WHERE id = ?',
            [$published ? 1 : 0, $id]
        );
    }

    // -------------------------------------------------------------- asks

    /**
     * THE DECISION. What would happen if this person were asked?
     *
     * Public and separate from ask() on purpose, so a screen can show the
     * warning BEFORE the button is pressed rather than after — which is the
     * only arrangement in which a warning is any use. The builder is told
     * "they are away that weekend" while choosing, not told it once the ask has
     * already gone out.
     *
     * ask() asks again for itself. Two calls, because the answer can change
     * between the page rendering and the button being pressed, and the one that
     * governs is the one at the moment of writing.
     */
    public function wouldAsk(int $serviceId, int $userId): AskOutcome
    {
        $service = $this->service($serviceId);

        if ($service === null) {
            return AskOutcome::decide(false, [], 'They');
        }

        $name = (string) $this->db->value(
            'SELECT COALESCE(NULLIF(name, ""), email) FROM {users} WHERE id = ?',
            [$userId]
        );

        return AskOutcome::decide(
            $this->alreadyOnService($serviceId, $userId),
            $this->blockoutReasons($userId, substr((string) $service['starts_at'], 0, 10)),
            $name !== '' ? $name : 'They'
        );
    }

    /**
     * Ask somebody to serve.
     *
     * The outcome is decided first and returned, so the caller can report a
     * warning that DID NOT STOP anything — which is the whole point of the
     * distinction. A refusal throws, because there is nothing to report about a
     * write that must not happen.
     *
     * @return AskOutcome allowed or warned; refusals throw
     */
    public function ask(int $serviceId, int $teamId, int $userId, ?int $positionId = null): AskOutcome
    {
        $outcome = $this->wouldAsk($serviceId, $userId);

        if (!$outcome->permitted()) {
            throw HttpException::badRequest($outcome->message);
        }

        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO {rota_assignments}
                (service_id, team_id, position_id, user_id, state, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$serviceId, $teamId, $positionId, $userId, Assignment::INVITED, $now, $now]
        );

        return $outcome;
    }

    /**
     * Answer an ask. Only the person asked may.
     *
     * The person's id is part of the WHERE rather than checked first, so there
     * is no window between the check and the write and no way to answer for
     * somebody else by knowing an id. Ids are sequential; this is the same rule
     * the notification record follows.
     *
     * Returns false when nothing matched, which the caller reports rather than
     * swallowing — a button that silently does nothing is one somebody presses
     * again.
     */
    public function answer(int $assignmentId, int $userId, string $state, string $reason = ''): bool
    {
        $state = Assignment::normalizeState($state);

        if ($state === Assignment::INVITED) {
            // Un-answering is not an answer. Somebody who has changed their
            // mind says the other thing; there is no way back to "not asked".
            throw HttpException::badRequest('An answer is yes or no.');
        }

        return $this->db->execute(
            'UPDATE {rota_assignments}
                SET state = ?, reason = ?, answered_at = NOW(), updated_at = NOW()
              WHERE id = ? AND user_id = ?',
            [$state, mb_substr(trim($reason), 0, 300) ?: null, $assignmentId, $userId]
        ) > 0;
    }

    /** Withdraw an ask entirely. The organiser's action, not the person's. */
    public function withdraw(int $assignmentId): void
    {
        $this->db->execute('DELETE FROM {rota_assignments} WHERE id = ?', [$assignmentId]);
    }

    /**
     * Everything asked for one service, ready to render.
     *
     * @return list<Assignment>
     */
    public function forService(int $serviceId): array
    {
        $rows = $this->db->all(
            'SELECT a.*, COALESCE(NULLIF(u.name, ""), u.email) AS person_name, u.email AS person_email,
                    t.name AS team_name, p.name AS position_name
               FROM {rota_assignments} a
               INNER JOIN {users} u ON u.id = a.user_id
               INNER JOIN {rota_teams} t ON t.id = a.team_id
               LEFT JOIN {rota_positions} p ON p.id = a.position_id
              WHERE a.service_id = ?
              ORDER BY t.position, t.name, p.position, person_name',
            [$serviceId]
        );

        return array_map(static fn (array $row): Assignment => Assignment::fromRow($row), $rows);
    }

    /**
     * What one person has been asked, soonest first.
     *
     * Unanswered asks are what this page is FOR, so they are not filtered out
     * and the ordering puts the next occasion at the top whatever its state.
     *
     * @return list<array<string, mixed>>
     */
    public function forPerson(int $userId, bool $upcomingOnly = true, int $limit = 100): array
    {
        $where = $upcomingOnly ? ' AND s.starts_at >= NOW()' : '';

        return $this->db->all(
            "SELECT a.*, s.title AS service_title, s.starts_at, s.is_published,
                    t.name AS team_name, p.name AS position_name
               FROM {rota_assignments} a
               INNER JOIN {rota_services} s ON s.id = a.service_id
               INNER JOIN {rota_teams} t ON t.id = a.team_id
               LEFT JOIN {rota_positions} p ON p.id = a.position_id
              WHERE a.user_id = ?{$where}
              ORDER BY s.starts_at ASC
              LIMIT " . max(1, min(500, $limit)),
            [$userId]
        );
    }

    /**
     * Asks nobody has answered yet, for the builder to chase.
     *
     * Only for services that are PUBLISHED and still ahead. An unanswered ask
     * on a draft service is not somebody being slow — it is a rota that has not
     * been sent out, and putting it on a chasing list would have the organiser
     * chasing themselves.
     *
     * @return list<array<string, mixed>>
     */
    public function unanswered(int $limit = 100): array
    {
        return $this->db->all(
            'SELECT a.*, s.title AS service_title, s.starts_at,
                    COALESCE(NULLIF(u.name, ""), u.email) AS person_name, u.email AS person_email,
                    t.name AS team_name
               FROM {rota_assignments} a
               INNER JOIN {rota_services} s ON s.id = a.service_id
               INNER JOIN {users} u ON u.id = a.user_id
               INNER JOIN {rota_teams} t ON t.id = a.team_id
              WHERE a.state = ? AND s.is_published = 1 AND s.starts_at >= NOW()
              ORDER BY s.starts_at ASC
              LIMIT ' . max(1, min(500, $limit)),
            [Assignment::INVITED]
        );
    }

    // --------------------------------------------------------- blockouts

    /** @return list<array<string, mixed>> */
    public function blockouts(int $userId): array
    {
        return $this->db->all(
            'SELECT * FROM {rota_blockouts} WHERE user_id = ? ORDER BY starts_on DESC',
            [$userId]
        );
    }

    public function addBlockout(int $userId, string $from, string $to, string $reason = ''): int
    {
        $from = $this->day($from);
        $to = $this->day($to);

        /*
         * Backwards dates are swapped rather than refused. A date picker makes
         * this easy to do and the intention is never ambiguous — nobody means
         * "from the 10th to the 3rd".
         */
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return (int) $this->db->insert('rota_blockouts', [
            'user_id'    => $userId,
            'starts_on'  => $from,
            'ends_on'    => $to,
            'reason'     => mb_substr(trim($reason), 0, 200) ?: null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function removeBlockout(int $id, int $userId): bool
    {
        // Keyed to the person, like answering. Their own dates, nobody else's.
        return $this->db->execute(
            'DELETE FROM {rota_blockouts} WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    /**
     * Why this person said they cannot serve on this day.
     *
     * Inclusive at both ends, because that is how people say it: "away the 3rd
     * to the 10th" includes the 10th.
     *
     * @return list<string>
     */
    public function blockoutReasons(int $userId, string $day): array
    {
        $day = $this->day($day);

        return array_map(
            static fn ($reason): string => (string) $reason,
            $this->db->column(
                'SELECT COALESCE(reason, "") FROM {rota_blockouts}
                  WHERE user_id = ? AND starts_on <= ? AND ends_on >= ?',
                [$userId, $day, $day]
            )
        );
    }

    // --------------------------------------------------------- internals

    public function alreadyOnService(int $serviceId, int $userId): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {rota_assignments} WHERE service_id = ? AND user_id = ?',
            [$serviceId, $userId]
        ) > 0;
    }

    /**
     * A wall-clock datetime, not an instant.
     *
     * A service at ten o'clock is at ten o'clock. Storing an instant would move
     * it by an hour twice a year, and the week the clocks change is the one
     * week a rota has to be right.
     */
    private function wallClock(string $raw): string
    {
        $raw = trim($raw);

        // What a datetime-local input sends, as well as an ordinary SQL value.
        $stamp = strtotime(str_replace('T', ' ', $raw));

        if ($stamp === false) {
            throw HttpException::badRequest('That is not a date and time this can read.');
        }

        return date('Y-m-d H:i:s', $stamp);
    }

    private function day(string $raw): string
    {
        $stamp = strtotime(trim($raw));

        if ($stamp === false) {
            throw HttpException::badRequest('That is not a date this can read.');
        }

        return date('Y-m-d', $stamp);
    }

    private function uniqueTeamSlug(string $desired): string
    {
        $base = Str::slug($desired) ?: 'team';
        $slug = $base;
        $suffix = 1;

        while ($this->db->value('SELECT id FROM {rota_teams} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    private function nextTeamPosition(): int
    {
        return (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 10 FROM {rota_teams}');
    }
}
