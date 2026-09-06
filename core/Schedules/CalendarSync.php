<?php

declare(strict_types=1);

namespace Portal\Schedules;

use Portal\Db;

/**
 * Keeping the calendar on a device, without sending it all every time.
 *
 * # TWO THINGS THIS PAYLOAD CANNOT STATE
 *
 * Both produce the same failure — a stale date sitting on somebody's phone
 * until they turn up for a rota that is not running — and neither can be
 * reported as a change, because NOTHING ABOUT THE ENTRY ROWS CHANGED.
 *
 *   1. A SCHEDULE THAT WAS TURNED OFF TAKES ITS DATES WITH IT. Disabling writes
 *      one row in {schedules} and touches no entry at all, so those dates are
 *      never modified and never deleted and will never appear in `entries` or
 *      in `removed`. The device has to notice the schedule is no longer live
 *      and drop its days itself — which is why `schedules` carries EVERY
 *      schedule with an `enabled` flag rather than only the live ones. Sending
 *      only the live ones would leave the device unable to tell "withdrawn"
 *      apart from "unchanged". The rule the client applies is ABSENT OR NOT
 *      ENABLED, because a schedule that was deleted outright drops out of the
 *      list altogether and its entries went with it by cascade.
 *
 *   2. DAYS FALLING OUT BEHIND THE SLIDING WINDOW ARE DROPPED, for the same
 *      reason. Yesterday's entry is not deleted; it simply stops being inside
 *      the window this answers for. So `window` is in every payload and the
 *      device discards anything outside it.
 *
 * The client carries both rules. They are stated here as well because a payload
 * cannot say them and a reader of either half needs to know the other exists.
 *
 * # WHY A TOMBSTONE, AND WHY IT EXPIRES
 *
 * A genuine cancellation IS reportable, but only if something remembers it —
 * a deleted row leaves no trace. Tombstones are kept ninety days; a device that
 * has been away longer is answered `full`, because past that point this cannot
 * promise it knows every deletion, and answering incrementally anyway would be
 * claiming to have looked when it had not.
 */
final class CalendarSync
{
    /**
     * How long a cancellation is remembered.
     *
     * Long enough for a phone that was off for a season; short enough that this
     * does not become a permanent register of every date ever cancelled.
     */
    public const TOMBSTONE_DAYS = 90;

    /**
     * How far the cursor this hands back is wound BEHIND the moment it answers.
     *
     * Without it a change is lost for ever. Both comparisons here are strictly
     * "later than `since`" against DATETIME columns, which hold whole seconds —
     * so an edit or a deletion landing in the same second as the answer is not
     * later than it, is left out of this payload, and is never later than the
     * cursor the device stores either. It would never be mentioned again.
     *
     * Two seconds rather than one because the timestamps are not all written by
     * the same clock: some rows are stamped by MySQL's NOW() and some by PHP's
     * date(), and on a host where those differ by a fraction one second of slack
     * is not slack at all.
     *
     * The cost of the overlap is re-sending at most two seconds of changes,
     * which costs nothing: entries are keyed by id and applied as an upsert, and
     * a removal that has already been applied removes nothing.
     */
    private const OVERLAP_SECONDS = 2;

    public function __construct(
        private readonly Db $db,
        private readonly ScheduleRepository $schedules
    ) {
    }

    /**
     * What has changed, or everything, and enough context to apply the two
     * rules above.
     *
     * @return array<string, mixed>
     */
    public function payload(?string $since = null, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $from = $today;
        $to = date('Y-m-d', (int) strtotime($from . ' +' . ScheduleRepository::WINDOW_DAYS . ' days'));

        $full = $this->mustBeFull($since);
        $mark = $full ? null : date('Y-m-d H:i:s', (int) strtotime((string) $since));

        return [
            /*
             * The cursor the device sends back next time, wound behind the
             * moment this answers. See OVERLAP_SECONDS — without it a change
             * landing in this very second is never reported at all.
             */
            'now'    => date('c', time() - self::OVERLAP_SECONDS),
            'since'  => $mark === null ? null : date('c', (int) strtotime($mark)),
            'window' => ['from' => $from, 'to' => $to],

            /*
             * `full` is an instruction, not a description: replace everything
             * held for this window rather than merging. Said explicitly so a
             * device never has to infer it from an unusually large payload.
             */
            'full'   => $full,

            // EVERY schedule, enabled or not. See rule 1 on the class.
            'schedules' => array_map(
                static fn (array $row): array => [
                    'id'      => (int) $row['id'],
                    'slug'    => (string) $row['slug'],
                    'name'    => (string) $row['name'],
                    'icon'    => $row['icon'] === null ? null : (string) $row['icon'],
                    'colour'  => $row['colour'] === null ? null : (string) $row['colour'],
                    'enabled' => (bool) $row['is_enabled'],
                ],
                $this->schedules->schedules(true)
            ),

            'entries' => $this->entries($from, $to, $mark),
            'removed' => $mark === null ? [] : $this->removed($from, $to, $mark),
        ];
    }

    /**
     * Whether an incremental answer can be trusted.
     *
     * A missing or unreadable `since` is a first sync. One older than the
     * tombstone window is a device that has been away too long for this to
     * know what it missed — and guessing would be exactly the "not in the page
     * we fetched means gone" mistake, pointing the other way.
     */
    public function mustBeFull(?string $since): bool
    {
        if ($since === null || trim($since) === '') {
            return true;
        }

        $stamp = strtotime($since);

        if ($stamp === false || $stamp > time()) {
            return true;
        }

        return $stamp < time() - self::TOMBSTONE_DAYS * 86400;
    }

    /**
     * Record that a date is gone.
     *
     * Called by the repository on every path that removes an entry, so there is
     * no delete that a device cannot find out about — a second delete path
     * without a tombstone would be a stale date on a phone with nothing to
     * explain it.
     */
    public function prune(): int
    {
        return $this->db->execute(
            'DELETE FROM {schedule_entry_tombstones} WHERE deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [self::TOMBSTONE_DAYS]
        );
    }

    // --------------------------------------------------------- internals

    /** @return list<array<string, mixed>> */
    private function entries(string $from, string $to, ?string $since): array
    {
        /*
         * Entries on ENABLED schedules only. A withdrawn schedule's dates are
         * not sent as changes — they are handled by rule 1, which is the
         * device dropping them because the schedule is no longer enabled.
         * Sending them here as well would put them back on the phone.
         */
        $sql = 'SELECT e.id, e.schedule_id, e.on_date, e.role, e.note, e.updated_at,
                       p.name AS person_name
                  FROM {schedule_entries} e
                  INNER JOIN {schedules} s ON s.id = e.schedule_id AND s.is_enabled = 1
                  INNER JOIN {schedule_people} p ON p.id = e.person_id
                 WHERE e.on_date BETWEEN ? AND ?';

        $params = [$from, $to];

        if ($since !== null) {
            $sql .= ' AND e.updated_at > ?';
            $params[] = $since;
        }

        $rows = $this->db->all($sql . ' ORDER BY e.on_date, e.id LIMIT 5000', $params);

        return array_map(
            static fn (array $row): array => [
                'id'       => (int) $row['id'],
                'schedule' => (int) $row['schedule_id'],
                'date'     => (string) $row['on_date'],
                'person'   => (string) $row['person_name'],
                'role'     => (string) ($row['role'] ?? ''),
                'note'     => (string) ($row['note'] ?? ''),
                'updated'  => date('c', (int) strtotime((string) $row['updated_at'])),
            ],
            $rows
        );
    }

    /** @return list<int> */
    private function removed(string $from, string $to, string $since): array
    {
        return array_map(
            'intval',
            array_column(
                $this->db->all(
                    'SELECT entry_id FROM {schedule_entry_tombstones}
                      WHERE deleted_at > ? AND on_date BETWEEN ? AND ?
                      ORDER BY deleted_at LIMIT 5000',
                    [$since, $from, $to]
                ),
                'entry_id'
            )
        );
    }
}
