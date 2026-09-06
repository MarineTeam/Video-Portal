<?php

declare(strict_types=1);

namespace Portal\Rota;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;
use Throwable;

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

    // ------------------------------------------------------- service plan

    /**
     * The running order, in the order somebody arranged it.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(int $serviceId): array
    {
        return $this->db->all(
            'SELECT * FROM {service_plan_items} WHERE service_id = ? ORDER BY position, id',
            [$serviceId]
        );
    }

    /**
     * Add a line to the order.
     *
     * The title is the HYMN'S OWN NAME, and the reference is whatever goes on
     * the board. See the migration for why that way round: a row that said
     * "Ancient & Modern 245" would be meaningless to anybody holding a
     * different book, and to this site the day the church buys new hymnals.
     */
    public function addPlanItem(
        int $serviceId,
        string $kind,
        string $title,
        string $reference = '',
        string $note = ''
    ): int {
        $title = trim($title);

        if ($title === '') {
            throw HttpException::badRequest('A line in the order needs a name.');
        }

        $next = (int) $this->db->value(
            'SELECT COALESCE(MAX(position), 0) + 10 FROM {service_plan_items} WHERE service_id = ?',
            [$serviceId]
        );

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('service_plan_items', [
            'service_id' => $serviceId,
            'kind'       => self::planKind($kind),
            'title'      => mb_substr($title, 0, 190),
            'reference'  => mb_substr(trim($reference), 0, 120) ?: null,
            'note'       => mb_substr(trim($note), 0, 300) ?: null,
            'position'   => $next,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function removePlanItem(int $id): void
    {
        $this->db->execute('DELETE FROM {service_plan_items} WHERE id = ?', [$id]);
    }

    /**
     * Move one line up or down.
     *
     * Swaps positions with its neighbour rather than renumbering everything,
     * which is the same shape as the category ordering and for the same reason:
     * one write, and nothing else in the list moves.
     *
     * @param int $direction -1 for earlier, 1 for later
     */
    public function movePlanItem(int $id, int $direction): bool
    {
        if ($direction !== -1 && $direction !== 1) {
            return false;
        }

        $item = $this->db->first('SELECT * FROM {service_plan_items} WHERE id = ?', [$id]);

        if ($item === null) {
            return false;
        }

        $order = $this->plan((int) $item['service_id']);
        $index = null;

        foreach ($order as $i => $row) {
            if ((int) $row['id'] === $id) {
                $index = $i;
                break;
            }
        }

        $swapWith = $index === null ? null : ($order[$index + $direction] ?? null);

        if ($swapWith === null) {
            // Already at one end. Reported rather than silently doing nothing:
            // a button that appears not to work is one somebody presses again.
            return false;
        }

        return $this->db->transaction(function () use ($item, $swapWith): bool {
            $this->db->execute(
                'UPDATE {service_plan_items} SET position = ?, updated_at = NOW() WHERE id = ?',
                [(int) $swapWith['position'], (int) $item['id']]
            );
            $this->db->execute(
                'UPDATE {service_plan_items} SET position = ?, updated_at = NOW() WHERE id = ?',
                [(int) $item['position'], (int) $swapWith['id']]
            );

            return true;
        });
    }

    /** The kinds a line can be. An unknown one renders as a plain item. */
    public static function planKind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return in_array($kind, ['hymn', 'reading', 'item'], true) ? $kind : 'item';
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

    // ------------------------------------------------------------- cover

    /**
     * Ask the team to take this slot.
     *
     * Only the person holding it, and only once they have accepted it: an ask
     * nobody has answered is declined rather than handed on, because "I cannot
     * do it" and "somebody else should do it" are different messages and the
     * builder needs the first one.
     *
     * Keyed to the person in the WHERE clause, like answering.
     */
    public function requestCover(int $assignmentId, int $userId, string $note = ''): bool
    {
        return $this->db->execute(
            'UPDATE {rota_assignments}
                SET cover_requested_at = NOW(), cover_note = ?, updated_at = NOW()
              WHERE id = ? AND user_id = ? AND state = ?',
            [mb_substr(trim($note), 0, 300) ?: null, $assignmentId, $userId, Assignment::ACCEPTED]
        ) > 0;
    }

    /** Change their mind: take the request back while nobody has taken it. */
    public function cancelCoverRequest(int $assignmentId, int $userId): bool
    {
        return $this->db->execute(
            'UPDATE {rota_assignments}
                SET cover_requested_at = NULL, cover_note = NULL, updated_at = NOW()
              WHERE id = ? AND user_id = ? AND cover_requested_at IS NOT NULL',
            [$assignmentId, $userId]
        ) > 0;
    }

    /**
     * THE CONDITIONAL WRITE. Take a slot somebody has asked to be covered.
     *
     * ONE statement, and the WHERE clause is the whole safety of it:
     *
     *   cover_requested_at IS NOT NULL  — the slot is still open
     *   user_id = :asker                — still held by the person who asked
     *
     * Two people pressing "I'll take it" in the same second both run this. The
     * first matches one row and wins. The second matches NOTHING, because by
     * then the slot is neither open nor held by the asker, and gets an honest
     * refusal — where a read-then-write would have the second silently
     * overwrite the first and two people would each believe they were serving.
     *
     * It is not wrapped in a transaction and does not need to be. A single
     * UPDATE takes its own row lock; adding a transaction around one statement
     * buys nothing and invites somebody to add a read to it later, which is
     * exactly the shape this avoids.
     *
     * THE OLD NOTE DOES NOT FOLLOW THE SLOT. cover_note is cleared here: it was
     * the previous person's aside to the organiser, and carrying it onto the
     * new holder's row would attribute one person's words to another.
     *
     * @return string one of TAKEN, GONE, ALREADY_ON, NOT_OPEN
     */
    public const TAKEN = 'taken';
    public const GONE = 'gone';
    public const ALREADY_ON = 'already_on';
    public const NOT_OPEN = 'not_open';

    public function takeCover(int $assignmentId, int $takerId): string
    {
        $row = $this->db->first(
            'SELECT id, service_id, user_id, cover_requested_at
               FROM {rota_assignments} WHERE id = ?',
            [$assignmentId]
        );

        if ($row === null) {
            return self::NOT_OPEN;
        }

        $asker = (int) $row['user_id'];

        /*
         * NOTE WHAT IS NOT HERE: a check that the slot is open.
         *
         * The first version had one, and the concurrency test found it. Six
         * processes raced, exactly one won — and four of the five losers came
         * back "not open" rather than "gone", because they read the row AFTER
         * the winner had cleared the flag and returned before ever running the
         * conditional statement.
         *
         * The answers were all honest, so the feature was correct. The TEST was
         * not testing anything: the conditional WHERE — the only thing standing
         * between this and a lost update — was reached by one process out of
         * six, and which one depended entirely on timing. A mutation removing
         * it would have been caught or missed at random.
         *
         * So the write goes first and every caller runs it. The reads below
         * exist only to explain a refusal, which costs nothing on the path that
         * succeeds.
         */

        if ($asker === $takerId) {
            // Taking your own slot back is cancelling the request, and saying
            // so is kinder than a refusal that reads as a bug.
            return $this->cancelCoverRequest($assignmentId, $takerId) ? self::TAKEN : self::NOT_OPEN;
        }

        /*
         * The same rule as asking: somebody is on a service once. Checked here
         * so the answer is in words — but the UNIQUE key is what actually makes
         * it safe, because between this check and the UPDATE the taker could be
         * asked onto the service by somebody else. That case is caught below
         * and reported as the same thing rather than as a database error.
         */
        if ($this->alreadyOnService((int) $row['service_id'], $takerId)) {
            return self::ALREADY_ON;
        }

        try {
            $changed = $this->db->execute(
                'UPDATE {rota_assignments}
                    SET user_id = ?,
                        covering_for_user_id = ?,
                        state = ?,
                        reason = NULL,
                        answered_at = NOW(),
                        cover_requested_at = NULL,
                        cover_note = NULL,
                        updated_at = NOW()
                  WHERE id = ? AND cover_requested_at IS NOT NULL AND user_id = ?',
                [$takerId, $asker, Assignment::ACCEPTED, $assignmentId, $asker]
            );
        } catch (Throwable $e) {
            /*
             * The unique key fired: the taker was put on this service between
             * the check above and this write. Reported as the same refusal a
             * person would have got a moment earlier, rather than as
             * "something went wrong" — the rule for this whole section.
             */
            if (str_contains($e->getMessage(), 'uniq_service_person')) {
                return self::ALREADY_ON;
            }

            throw $e;
        }

        if ($changed > 0) {
            return self::TAKEN;
        }

        /*
         * Zero rows is not an error — it is somebody else having been quicker,
         * which is a normal outcome of two people being willing to help. Which
         * of the two honest refusals it is takes one more read, and only ever
         * on the path that already failed.
         *
         * Still held by the person who asked means nothing was open: either no
         * cover was ever requested, or they withdrew it. Held by somebody else
         * means it went.
         */
        $now = $this->db->first(
            'SELECT user_id FROM {rota_assignments} WHERE id = ?',
            [$assignmentId]
        );

        return $now !== null && (int) $now['user_id'] === $asker ? self::NOT_OPEN : self::GONE;
    }

    /**
     * Slots going spare, for the people who could take them.
     *
     * Only on published services still ahead, and only where cover was asked
     * for. A team seeing a slot on a draft service would be offering to cover
     * something nobody has been asked to do yet.
     *
     * @return list<array<string, mixed>>
     */
    public function coverWanted(int $limit = 100): array
    {
        return $this->db->all(
            'SELECT a.*, s.title AS service_title, s.starts_at,
                    COALESCE(NULLIF(u.name, ""), u.email) AS person_name,
                    t.name AS team_name, p.name AS position_name
               FROM {rota_assignments} a
               INNER JOIN {rota_services} s ON s.id = a.service_id
               INNER JOIN {users} u ON u.id = a.user_id
               INNER JOIN {rota_teams} t ON t.id = a.team_id
               LEFT JOIN {rota_positions} p ON p.id = a.position_id
              WHERE a.cover_requested_at IS NOT NULL
                AND s.is_published = 1
                AND s.starts_at >= NOW()
              ORDER BY s.starts_at ASC
              LIMIT ' . max(1, min(500, $limit))
        );
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
