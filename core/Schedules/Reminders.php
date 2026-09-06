<?php

declare(strict_types=1);

namespace Portal\Schedules;

use Portal\Config;
use Portal\Content\NotificationLog;
use Portal\Db;
use Portal\Mail\MailProvider;
use Throwable;

/**
 * Telling people what they are on for.
 *
 * # ONLY PEOPLE WITH AN ACCOUNT
 *
 * A name on a spreadsheet has no address. That is the limitation this whole
 * section is built around, and it is stated on the admin screen rather than
 * papered over — linking a name to an account is what turns reminders on, and
 * somebody with no account gets none because there is nowhere to send one.
 *
 * # ONE REMINDER, EVER
 *
 * {schedule_reminders_sent} has a PRIMARY KEY on (entry_id, kind) and every
 * send starts with INSERT IGNORE. The row is claimed BEFORE the message goes
 * out: losing one reminder is something a person recovers from by looking at
 * the calendar, where four copies is what makes somebody turn the feature off.
 *
 * # LATE IS NORMAL
 *
 * Pseudo-cron only fires when somebody visits, so an evening reminder can
 * genuinely go out the following morning. The message therefore says when the
 * date ACTUALLY is rather than which slot produced it, and a date that has
 * already been and gone is never mentioned at all.
 */
final class Reminders
{
    /** A ceiling per run, so one job cannot hold a shared host all afternoon. */
    public const MAX_PER_RUN = 50;

    /** How far ahead to look. Two days covers both slots with room for lateness. */
    private const HORIZON_DAYS = 2;

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly MailProvider $mail,
    ) {
    }

    /** A line for the cron log. */
    public function run(?int $now = null): string
    {
        $now ??= time();

        $candidates = $this->candidates();

        if ($candidates === []) {
            return 'Nobody is on anything in the next couple of days.';
        }

        $sent = 0;
        $failed = 0;

        foreach ($candidates as $row) {
            foreach ([ReminderSchedule::BEFORE, ReminderSchedule::DAY_OF] as $kind) {
                if ($sent + $failed >= self::MAX_PER_RUN) {
                    break 2;
                }

                if (!$this->wants($row, $kind)) {
                    continue;
                }

                if (!ReminderSchedule::isDue(
                    (string) $row['on_date'],
                    $kind,
                    (int) $row['send_hour'],
                    $this->zoneFor($row),
                    $now
                )) {
                    continue;
                }

                // Claimed before the send. See the class note.
                if (!$this->claim((int) $row['id'], $kind)) {
                    continue;
                }

                $this->tell($row, $now) ? $sent++ : $failed++;
            }
        }

        if ($sent === 0 && $failed === 0) {
            return 'Nothing was due.';
        }

        return $failed === 0
            ? sprintf('%d reminder(s) sent.', $sent)
            : sprintf('%d reminder(s) sent, %d could not be delivered.', $sent, $failed);
    }

    /**
     * Everybody on a day soon whose name is linked to an account.
     *
     * The INNER JOIN on {users} is where the limitation lives: a person with no
     * account produces no row here, so there is nothing to skip later and no
     * path that can accidentally try to email a name.
     *
     * A disabled schedule is excluded for the same reason its dates are off the
     * calendar — telling somebody they are on for a rota that is not running is
     * worse than telling them nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(): array
    {
        return $this->db->all(
            'SELECT e.id, e.on_date, e.role, e.note,
                    s.name AS schedule_name,
                    p.name AS person_name,
                    u.email, COALESCE(NULLIF(u.name, ""), u.email) AS account_name,
                    COALESCE(r.day_before, 1) AS day_before,
                    COALESCE(r.day_of, 0)     AS day_of,
                    COALESCE(r.send_hour, 18) AS send_hour,
                    r.timezone
               FROM {schedule_entries} e
               INNER JOIN {schedules} s ON s.id = e.schedule_id AND s.is_enabled = 1
               INNER JOIN {schedule_people} p ON p.id = e.person_id
               INNER JOIN {users} u ON u.id = p.user_id AND u.authorized = 1
               LEFT JOIN {schedule_reminder_prefs} r ON r.user_id = u.id
              WHERE e.on_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                                  AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
              ORDER BY e.on_date, e.id
              LIMIT 500',
            [self::HORIZON_DAYS]
        );
    }

    /**
     * The fire-once guard, public so it can be tested directly.
     *
     * A single-threaded pass never watches a claim fail — the second call in
     * one process is the only way to stage the condition this exists for, and
     * this project has twice been caught by a concurrency guard that no test
     * could reach.
     */
    public function claim(int $entryId, string $kind): bool
    {
        $affected = $this->db->execute(
            'INSERT IGNORE INTO {schedule_reminders_sent} (entry_id, kind, sent_at)
             VALUES (?, ?, NOW())',
            [$entryId, $kind]
        );

        return $affected > 0;
    }

    // --------------------------------------------------------- internals

    /** @param array<string, mixed> $row */
    private function wants(array $row, string $kind): bool
    {
        return $kind === ReminderSchedule::BEFORE
            ? (bool) $row['day_before']
            : (bool) $row['day_of'];
    }

    /**
     * Which zone the hour is in.
     *
     * The site's, when the person has not said. Not UTC: on a church website
     * everybody is in one place, so the site's own zone is right for almost
     * every subscriber and asking would be a form nobody needs to fill in.
     *
     * @param array<string, mixed> $row
     */
    private function zoneFor(array $row): string
    {
        $chosen = trim((string) ($row['timezone'] ?? ''));

        if ($chosen !== '') {
            return $chosen;
        }

        return (string) ($this->config->setting('timezone') ?: date_default_timezone_get());
    }

    /** @param array<string, mixed> $row */
    private function tell(array $row, int $now): bool
    {
        $when = ReminderSchedule::describe((string) $row['on_date'], $this->zoneFor($row), $now);

        /*
         * The wording comes from the DATE, not from the slot that fired. A
         * late evening reminder going out the next morning must not tell
         * somebody they are on tomorrow on the day they are on.
         */
        $whenWords = match ($when) {
            ReminderSchedule::TODAY    => 'today',
            ReminderSchedule::TOMORROW => 'tomorrow',
            default                    => 'on ' . date('l j F', (int) strtotime((string) $row['on_date'])),
        };

        $job = trim((string) ($row['role'] ?? ''));
        $subject = sprintf(
            '%s: you are on %s',
            (string) $row['schedule_name'],
            $whenWords
        );

        $body = sprintf(
            '<p>Hello %s,</p><p>You are on <strong>%s</strong> %s%s.</p>%s'
            . '<p><a href="%s/calendar">See the whole calendar</a></p>'
            . '<p style="color:#666;font-size:13px">You can change or stop these at '
            . '<a href="%s/account/reminders">your reminder settings</a>.</p>',
            e((string) $row['account_name']),
            e((string) $row['schedule_name']),
            e($whenWords),
            $job === '' ? '' : ' — ' . e($job),
            trim((string) ($row['note'] ?? '')) === ''
                ? ''
                : '<p>' . e((string) $row['note']) . '</p>',
            e($this->baseUrl()),
            e($this->baseUrl())
        );

        $text = sprintf(
            "You are on %s %s%s.\n\n%s/calendar",
            (string) $row['schedule_name'],
            $whenWords,
            $job === '' ? '' : ' — ' . $job,
            $this->baseUrl()
        );

        try {
            $result = $this->mail->send((string) $row['email'], $subject, $body, $text);
        } catch (Throwable $e) {
            error_log('Could not send a rota reminder: ' . $e->getMessage());

            return false;
        }

        if ($result->sent) {
            /*
             * Recorded only on a send the provider accepted, which is the same
             * rule the announcement emails follow: a rejection means nothing
             * was sent and nothing will arrive, so a row would invent
             * something that never happened.
             */
            (new NotificationLog($this->db))->record(
                (string) $row['email'],
                NotificationLog::EMAIL,
                $subject,
                '/calendar'
            );
        }

        return $result->sent;
    }

    private function baseUrl(): string
    {
        // BASE_URL, never the request host — this runs from cron, where there
        // may be no request at all, and a poisoned host header in an emailed
        // link is a bug this project has already fixed once.
        return rtrim((string) $this->config->get('base_url', ''), '/');
    }
}
