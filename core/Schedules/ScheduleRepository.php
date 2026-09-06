<?php

declare(strict_types=1);

namespace Portal\Schedules;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Schedules, the people on them, and the days they are on.
 *
 * A second, separate rota for people who never log in: names rather than
 * accounts, and a public page anybody can read. See the migration for why it is
 * not the same table as the rota.
 */
final class ScheduleRepository
{
    /** How far the public calendar looks ahead by default. */
    public const WINDOW_DAYS = 120;

    public function __construct(private readonly Db $db)
    {
    }

    // --------------------------------------------------------- schedules

    /**
     * @return list<array<string, mixed>>
     */
    public function schedules(bool $includeDisabled = false): array
    {
        $where = $includeDisabled ? '' : ' WHERE is_enabled = 1';

        return $this->db->all("SELECT * FROM {schedules}{$where} ORDER BY position, name");
    }

    /** @return array<string, mixed>|null */
    public function schedule(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {schedules} WHERE id = ?', [$id]);
    }

    public function createSchedule(string $name, string $icon = '', string $colour = ''): int
    {
        $name = trim($name);

        if ($name === '') {
            throw HttpException::badRequest('A schedule needs a name.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('schedules', [
            'slug'       => $this->uniqueSlug($name),
            'name'       => mb_substr($name, 0, 120),
            'icon'       => self::icon($icon),
            'colour'     => self::colour($colour),
            'position'   => (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 10 FROM {schedules}'),
            'is_enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function enableSchedule(int $id, bool $enabled): void
    {
        /*
         * The dates are NOT touched. That is deliberate and it is the thing the
         * device sync has to work around: disabling writes nothing to
         * {schedule_entries}, so those days are never reported as changed or
         * deleted, and a phone holding them would keep them for ever. The
         * device has to notice the schedule has gone and drop its days itself.
         *
         * The alternative — deleting the entries — would mean re-enabling a
         * schedule lost a year of rota that somebody typed in.
         */
        $this->db->execute(
            'UPDATE {schedules} SET is_enabled = ?, updated_at = NOW() WHERE id = ?',
            [$enabled ? 1 : 0, $id]
        );
    }

    /**
     * A colour that is safe to put in a style attribute.
     *
     * Validated rather than escaped, because it goes into CSS rather than into
     * text, and escaping is not a defence there. Anything that is not plainly a
     * hex colour becomes nothing at all, and the theme falls back.
     */
    public static function colour(string $raw): ?string
    {
        $raw = trim($raw);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $raw) === 1 ? strtolower($raw) : null;
    }

    /** One or two characters, so an icon cannot become a sentence. */
    public static function icon(string $raw): ?string
    {
        $raw = trim($raw);

        return $raw === '' ? null : mb_substr($raw, 0, 2);
    }

    // ------------------------------------------------------------ people

    /**
     * Find somebody by any name they are written as, or make them.
     *
     * The KEY is what is matched on, not the name: "José", "Jose" and " jose "
     * are one person, and a sync that made three would put somebody on the rota
     * three times.
     *
     * Aliases are checked too, so a person known as both "Bob" and "Robert" is
     * found by either — which is what stops a spreadsheet that switches between
     * them creating a second person halfway through the year.
     */
    public function personFor(string $name): int
    {
        $key = PersonKey::for($name);

        if ($key === '') {
            throw HttpException::badRequest('A person needs a name.');
        }

        $existing = $this->db->value('SELECT id FROM {schedule_people} WHERE match_key = ?', [$key]);

        if ($existing !== null) {
            return (int) $existing;
        }

        $alias = $this->db->value(
            'SELECT person_id FROM {schedule_person_aliases} WHERE match_key = ?',
            [$key]
        );

        if ($alias !== null) {
            return (int) $alias;
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('schedule_people', [
            'name'       => mb_substr(trim($name), 0, 190),
            'match_key'  => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function people(): array
    {
        return $this->db->all(
            'SELECT p.*, COALESCE(NULLIF(u.name, ""), u.email) AS account_name
               FROM {schedule_people} p
               LEFT JOIN {users} u ON u.id = p.user_id
              ORDER BY p.name'
        );
    }

    /** @return array<string, mixed>|null */
    public function person(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {schedule_people} WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function aliases(int $personId): array
    {
        return $this->db->all(
            'SELECT * FROM {schedule_person_aliases} WHERE person_id = ? ORDER BY name',
            [$personId]
        );
    }

    /**
     * Link a name to an account.
     *
     * THE ONLY THING THAT TURNS REMINDERS ON. Somebody on a rota with no
     * account gets none, which is a real limitation rather than a bug — there
     * is nowhere to send one, and inventing a notification for a name on a
     * spreadsheet would be a promise the site cannot keep.
     */
    public function linkToAccount(int $personId, ?int $userId): void
    {
        $this->db->execute(
            'UPDATE {schedule_people} SET user_id = ?, updated_at = NOW() WHERE id = ?',
            [$userId, $personId]
        );
    }

    /**
     * Names that might be the same person, for somebody to decide about.
     *
     * SUGGESTED, NEVER MERGED. "Dave Smith" and "David Smith" are usually one
     * person and occasionally a father and son, and the site is not the thing
     * that knows which. An automatic merge is unpickable afterwards: the two
     * histories are one, and no record survives of which entries came from
     * which name.
     *
     * @return list<array{a: array<string, mixed>, b: array<string, mixed>, score: int}>
     */
    public function duplicateSuggestions(int $limit = 25): array
    {
        $people = $this->people();
        $out = [];

        foreach ($people as $i => $a) {
            foreach (array_slice($people, $i + 1) as $b) {
                if (!PersonKey::looksLikeDuplicate((string) $a['name'], (string) $b['name'])) {
                    continue;
                }

                $out[] = [
                    'a'     => $a,
                    'b'     => $b,
                    'score' => PersonKey::similarity((string) $a['name'], (string) $b['name']),
                ];

                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * Fold one person into another, as a person's decision.
     *
     * The loser's name becomes an ALIAS of the winner rather than being thrown
     * away, so a spreadsheet that still writes the old spelling keeps matching —
     * without that the next sync would recreate the person that was just merged
     * away, and it would happen every night.
     *
     * @return int how many entries moved
     */
    public function merge(int $keepId, int $mergeId): int
    {
        if ($keepId === $mergeId) {
            return 0;
        }

        return $this->db->transaction(function () use ($keepId, $mergeId): int {
            $loser = $this->person($mergeId);
            $winner = $this->person($keepId);

            if ($loser === null || $winner === null) {
                return 0;
            }

            /*
             * Entries move one at a time with IGNORE, because the winner may
             * already be on a day the loser was also on — the same person
             * entered twice under two spellings, which is exactly the case
             * being merged. The unique key catches those and the duplicates are
             * then removed rather than left orphaned.
             */
            $this->db->execute(
                'UPDATE IGNORE {schedule_entries} SET person_id = ? WHERE person_id = ?',
                [$keepId, $mergeId]
            );

            $moved = (int) $this->db->value(
                'SELECT COUNT(*) FROM {schedule_entries} WHERE person_id = ?',
                [$mergeId]
            );

            // Whatever could not move was a duplicate of a day the winner
            // already had.
            $this->db->execute('DELETE FROM {schedule_entries} WHERE person_id = ?', [$mergeId]);

            // The loser's aliases come too, or the spellings they covered stop
            // matching anybody.
            $this->db->execute(
                'UPDATE IGNORE {schedule_person_aliases} SET person_id = ? WHERE person_id = ?',
                [$keepId, $mergeId]
            );

            $this->db->execute(
                'INSERT IGNORE INTO {schedule_person_aliases} (person_id, name, match_key, created_at)
                 VALUES (?, ?, ?, NOW())',
                [$keepId, (string) $loser['name'], (string) $loser['match_key']]
            );

            $this->db->execute('DELETE FROM {schedule_people} WHERE id = ?', [$mergeId]);

            return $moved;
        });
    }

    // ----------------------------------------------------------- entries

    /**
     * Put somebody on a day.
     *
     * Idempotent on (schedule, day, person, role), so re-reading a spreadsheet
     * does not double every row.
     */
    public function put(
        int $scheduleId,
        int $personId,
        string $day,
        string $role = '',
        string $note = '',
        string $source = 'manual'
    ): void {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO {schedule_entries}
                (schedule_id, person_id, on_date, role, note, source, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE note = VALUES(note), source = VALUES(source), updated_at = VALUES(updated_at)',
            [
                $scheduleId,
                $personId,
                $this->day($day),
                mb_substr(trim($role), 0, 120) ?: null,
                mb_substr(trim($note), 0, 300) ?: null,
                $source === 'sheet' ? 'sheet' : 'manual',
                $now,
                $now,
            ]
        );
    }

    public function removeEntry(int $id): void
    {
        $this->db->execute('DELETE FROM {schedule_entries} WHERE id = ?', [$id]);
    }

    /**
     * The calendar, as a reader sees it.
     *
     * Only ENABLED schedules, which is what makes disabling take its dates off
     * the page — and the reason the device sync needs a rule of its own, since
     * nothing about those entries changed.
     *
     * @return list<array<string, mixed>>
     */
    public function calendar(?string $from = null, ?string $to = null): array
    {
        $from ??= date('Y-m-d');
        $to ??= date('Y-m-d', strtotime('+' . self::WINDOW_DAYS . ' days'));

        return $this->db->all(
            'SELECT e.*, s.name AS schedule_name, s.slug AS schedule_slug,
                    s.icon, s.colour, s.position,
                    p.name AS person_name, p.match_key
               FROM {schedule_entries} e
               INNER JOIN {schedules} s ON s.id = e.schedule_id AND s.is_enabled = 1
               INNER JOIN {schedule_people} p ON p.id = e.person_id
              WHERE e.on_date BETWEEN ? AND ?
              ORDER BY e.on_date, s.position, s.name, p.name',
            [$this->day($from), $this->day($to)]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forSchedule(int $scheduleId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT e.*, p.name AS person_name
               FROM {schedule_entries} e
               INNER JOIN {schedule_people} p ON p.id = e.person_id
              WHERE e.schedule_id = ?
              ORDER BY e.on_date DESC, p.name
              LIMIT ' . max(1, min(500, $limit)),
            [$scheduleId]
        );
    }

    // --------------------------------------------------------- internals

    private function day(string $raw): string
    {
        $stamp = strtotime(trim($raw));

        if ($stamp === false) {
            throw HttpException::badRequest('That is not a date this can read.');
        }

        return date('Y-m-d', $stamp);
    }

    private function uniqueSlug(string $desired): string
    {
        $base = Str::slug($desired) ?: 'schedule';
        $slug = $base;
        $suffix = 1;

        while ($this->db->value('SELECT id FROM {schedules} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
