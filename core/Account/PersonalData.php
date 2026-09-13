<?php

declare(strict_types=1);

namespace Portal\Account;

use Portal\Auth\User;
use Portal\Db;
use Throwable;

/**
 * Everything this site holds about one person, to show them or hand over.
 *
 * Two questions with one answer behind them: "what have I watched" and "what do
 * you have on me". Both are read from the same rows, so they live together
 * rather than in two classes that would drift about which tables count.
 *
 * WHY THE EXPORT IS ASSEMBLED HERE AND NOT FROM A LIST OF TABLES
 *
 * A person's data is spread across core tables keyed by user id and by email —
 * the split is deliberate and predates this: group membership, subscriptions
 * and share authorship are keyed by ADDRESS so they can be set up before an
 * account exists and survive one being deleted and recreated. Anything walking
 * "tables with a user_id" would silently miss exactly those.
 *
 * PLUGINS CONTRIBUTE THROUGH A FILTER rather than being read directly. Comments,
 * ratings and reactions own their tables; core reaching into them would break
 * the moment a plugin is deactivated, and would quietly export nothing after it
 * is uninstalled while still claiming to be complete.
 */
final class PersonalData
{
    /** @var list<string> Queries that did not run. See rows() and failures(). */
    private array $failures = [];

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------- history

    /**
     * What this person has watched, most recent first.
     *
     * Joined to the video so a row can name what it is. A row whose video has
     * been deleted is dropped rather than shown as a blank line — the progress
     * row survives by design, but "you watched something that no longer exists"
     * is not a useful thing to tell anybody.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $userId, int $limit = 200): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            return $this->db->all(
                'SELECT p.video_id, p.position_seconds, p.duration_seconds, p.completed_at, p.updated_at,
                        v.title, v.slug
                   FROM {watch_progress} p
                   INNER JOIN {videos} v ON v.id = p.video_id AND v.deleted_at IS NULL
                  WHERE p.user_id = ?
                  ORDER BY p.updated_at DESC
                  LIMIT ' . max(1, min(500, $limit)),
                [$userId]
            );
        } catch (Throwable $e) {
            error_log('Could not read watch history: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Forget one video, or all of them.
     *
     * The row IS the history and it is also what "continue watching" reads, so
     * clearing is not a cosmetic act — the video stops being offered to resume.
     * That is what somebody clearing their history means, and the screen says
     * so rather than letting them discover it.
     */
    public function forget(int $userId, ?int $videoId = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        if ($videoId !== null) {
            return $this->db->execute(
                'DELETE FROM {watch_progress} WHERE user_id = ? AND video_id = ?',
                [$userId, $videoId]
            );
        }

        return $this->db->execute('DELETE FROM {watch_progress} WHERE user_id = ?', [$userId]);
    }

    // -------------------------------------------------------------- export

    /**
     * Everything, as a structure ready to be encoded.
     *
     * Keyed by user id AND by email, because this application deliberately uses
     * both: an address can be given permissions, subscriptions and share
     * authorship before it has an account behind it.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        /*
         * The dates come from the row rather than the model, which carries only
         * what a request needs to make decisions. Reaching for
         * `$user->createdAt` seemed obvious and does not exist — an ordinary
         * property access, invisible to php -l and to the class loader, and a
         * fatal on the one screen nobody exercises often.
         */
        $account = $this->db->first(
            'SELECT created_at, last_seen_at FROM {users} WHERE id = ?',
            [$user->id]
        ) ?? [];

        $out = [
            'exported_at' => date('c'),
            'account' => [
                'email'          => $user->email,
                'name'           => $user->name,
                'created_at'     => $account['created_at'] ?? null,
                'last_seen_at'   => $account['last_seen_at'] ?? null,
                'email_verified' => $user->emailVerified,
                'authorized'     => $user->authorized,
            ],
            'watch_history'  => $this->history($user->id, 500),
            'saved'          => $this->rows(
                'SELECT s.video_id, s.list, s.created_at, v.title
                   FROM {saved_videos} s
                   LEFT JOIN {videos} v ON v.id = s.video_id
                  WHERE s.user_id = ?',
                [$user->id]
            ),
            'notes'          => $this->rows(
                'SELECT n.video_id, n.body, n.updated_at, v.title
                   FROM {video_notes} n
                   LEFT JOIN {videos} v ON v.id = n.video_id
                  WHERE n.user_id = ?',
                [$user->id]
            ),
            'note_sheets'    => $this->rows(
                'SELECT a.video_id, a.answers, a.sheet_version, a.updated_at, v.title
                   FROM {note_sheet_answers} a
                   LEFT JOIN {videos} v ON v.id = a.video_id
                  WHERE a.user_id = ?',
                [$user->id]
            ),
            'subscriptions'  => $this->rows(
                'SELECT scope_type, scope_id, created_at FROM {subscriptions} WHERE email = ?',
                [$user->email]
            ),
            'notifications'  => $this->rows(
                'SELECT channel, title, url, created_at FROM {notifications}
                  WHERE recipient_email = ? ORDER BY created_at DESC',
                [$user->email]
            ),
            'shared_links'   => $this->rows(
                'SELECT id, recipient_email, video_id, created_at, expires_at, revoked_at
                   FROM {shares} WHERE created_by = ?',
                [$user->email]
            ),
            'groups'         => $this->rows(
                'SELECT g.name FROM {group_members} m
                   INNER JOIN {permission_groups} g ON g.id = m.group_id
                  WHERE m.email = ?',
                [$user->email]
            ),
        ] + $this->churchLife($user);

        /*
         * Plugins add their own. Comments, ratings and reactions own their
         * tables, and core reading them directly would break when a plugin is
         * deactivated and would quietly export nothing after one is uninstalled
         * while still claiming to be complete.
         */
        /** @var array<string, mixed> $out */
        $out = apply_filters('account_export', $out, $user);

        return $out;
    }

    /**
     * Everything the church-life sections know about one person.
     *
     * Added because the export had quietly stopped being complete: it was
     * written against eight tables and then seven sections of work added more,
     * none of which it knew about. A partial export looks complete, which makes
     * it worse than none — and PersonalDataCompletenessTest now asks the SCHEMA
     * which tables are keyed to a person, so the next section to add one fails a
     * test rather than silently degrading this file.
     *
     * # NOBODY ELSE'S DATA LEAVES WITH IT
     *
     * Three places where that takes real care, all visible below:
     *
     *   A rota slot somebody is COVERING names another person. The fact of the
     *   cover is theirs; who they are covering for is not, so it comes out as a
     *   yes or no and never as a name.
     *
     *   A small group's ADDRESS goes through GroupAddress::for(), the one
     *   function allowed to produce one. Reading the column here would be a
     *   second implementation of that rule, and two implementations of it
     *   eventually disagree — with the failure being somebody's living room in a
     *   file that gets emailed to a solicitor.
     *
     *   An ANONYMOUS prayer request stays anonymous in the export too. The
     *   display name comes from PrayerName::for(), for the same reason: it is
     *   the only function that may produce one, and it answers with the
     *   anonymous label even for staff.
     *
     * @return array<string, mixed>
     */
    private function churchLife(User $user): array
    {
        return [
            /*
             * The rota. `covering_for` is a boolean rather than a name — see
             * the note above.
             */
            'rota' => $this->rows(
                'SELECT a.state, a.reason, a.answered_at, a.created_at,
                        s.title AS service, s.starts_at,
                        t.name AS team, p.name AS position,
                        a.covering_for_user_id IS NOT NULL AS covering_for_somebody
                   FROM {rota_assignments} a
                   INNER JOIN {rota_services} s ON s.id = a.service_id
                   INNER JOIN {rota_teams} t ON t.id = a.team_id
                   LEFT JOIN {rota_positions} p ON p.id = a.position_id
                  WHERE a.user_id = ?
                  ORDER BY s.starts_at DESC',
                [$user->id]
            ),

            'rota_teams' => $this->rows(
                'SELECT t.name AS team, p.name AS usual_position, m.created_at
                   FROM {rota_team_members} m
                   INNER JOIN {rota_teams} t ON t.id = m.team_id
                   LEFT JOIN {rota_positions} p ON p.id = m.position_id
                  WHERE m.user_id = ?',
                [$user->id]
            ),

            'days_i_cannot_serve' => $this->rows(
                'SELECT starts_on, ends_on, reason, created_at
                   FROM {rota_blockouts} WHERE user_id = ? ORDER BY starts_on DESC',
                [$user->id]
            ),

            /*
             * Events signed up to. Nothing about the other people who signed up
             * — the only row here is theirs.
             */
            'event_signups' => $this->rows(
                'SELECT e.title, e.starts_at, g.state, g.guests, g.note, g.created_at
                   FROM {event_signups} g
                   INNER JOIN {events} e ON e.id = g.event_id
                  WHERE g.user_id = ?
                  ORDER BY e.starts_at DESC',
                [$user->id]
            ),

            /*
             * The schedules calendar names people rather than accounts, so this
             * is only here when somebody linked the two.
             */
            'schedule_dates' => $this->rows(
                'SELECT s.name AS schedule, e.on_date, e.role, e.note
                   FROM {schedule_entries} e
                   INNER JOIN {schedules} s ON s.id = e.schedule_id
                   INNER JOIN {schedule_people} p ON p.id = e.person_id
                  WHERE p.user_id = ?
                  ORDER BY e.on_date DESC',
                [$user->id]
            ),

            'reminder_settings' => $this->rows(
                'SELECT day_before, day_of, send_hour, timezone, updated_at
                   FROM {schedule_reminder_prefs} WHERE user_id = ?',
                [$user->id]
            ),

            'message_settings' => $this->rows(
                'SELECT email_opt_out, sms_opt_in, phone, updated_at
                   FROM {broadcast_prefs} WHERE user_id = ?',
                [$user->id]
            ),

            /* What they sent on a form, with the questions they were answering. */
            'form_responses' => $this->rows(
                'SELECT f.title AS form, r.created_at, q.label AS question, a.value AS answer
                   FROM {form_responses} r
                   INNER JOIN {forms} f ON f.id = r.form_id
                   LEFT JOIN {form_answers} a ON a.response_id = r.id
                   LEFT JOIN {form_questions} q ON q.id = a.question_id
                  WHERE r.user_id = ?
                  ORDER BY r.created_at DESC',
                [$user->id]
            ),

            'prayer_requests' => $this->prayerRequests($user),
            'small_groups'    => $this->smallGroups($user),

            'books' => $this->rows(
                'SELECT b.title AS book, m.kind, m.pdf_page, m.quote, m.body, m.created_at
                   FROM {book_marks} m
                   INNER JOIN {books} b ON b.id = m.book_id
                  WHERE m.user_id = ?
                  ORDER BY m.created_at DESC',
                [$user->id]
            ),

            'reading' => $this->rows(
                'SELECT b.title AS book, r.pdf_page, r.percent, r.updated_at
                   FROM {reading_positions} r
                   INNER JOIN {books} b ON b.id = r.book_id
                  WHERE r.user_id = ?',
                [$user->id]
            ),

            /*
             * What they said in a live chat, INCLUDING what a moderator hid.
             *
             * Hidden is a fact about a decision somebody made, not a reason to
             * pretend the words were never written — they are still this
             * person's own words in the database, and an export that dropped
             * them would be answering "everything you hold about me" with
             * "everything except the part you objected to".
             *
             * `hidden_by` is NOT selected. That is a staff name attached to a
             * decision about a member, which is the same line the access-request
             * section draws and which SecretGuard forbids by name anyway.
             */
            'live_chat_messages' => $this->rows(
                'SELECT s.title AS stream, m.body, m.created_at,
                        m.hidden_at IS NOT NULL AS hidden
                   FROM {live_chat_messages} m
                   INNER JOIN {live_streams} s ON s.id = m.stream_id
                  WHERE m.user_id = ?
                  ORDER BY m.created_at DESC',
                [$user->id]
            ),

            /*
             * And whether they are stopped from posting in a stream.
             *
             * Included because a standing restriction on somebody is
             * unambiguously data about them, and one they cannot otherwise
             * discover: the live page tells them a moderator has stopped them
             * and nothing says where else that is true.
             *
             * Neither who decided nor the note they left. The note is written
             * for the other moderators — "shouting over the reading" — and
             * handing it back verbatim turns an internal record into an
             * argument, which is the same call ChatGate makes when it refuses
             * a muted person without quoting the reason.
             */
            'live_chat_mutes' => $this->rows(
                'SELECT s.title AS stream, m.created_at
                   FROM {live_chat_mutes} m
                   INNER JOIN {live_streams} s ON s.id = m.stream_id
                  WHERE m.user_id = ?
                  ORDER BY m.created_at DESC',
                [$user->id]
            ),

            /*
             * Asking for access, and the note they wrote. NOT who reviewed it —
             * that is a staff name attached to a decision about a member, which
             * SecretGuard forbids by name anyway.
             */
            'access_requests' => $this->rows(
                'SELECT note, created_at FROM {access_requests} WHERE user_id = ?',
                [$user->id]
            ),

            /* How they sign in. The subject identifies them to the provider. */
            'sign_in_methods' => $this->rows(
                'SELECT provider, created_at, last_seen_at FROM {user_identities} WHERE user_id = ?',
                [$user->id]
            ),

            /* Tags somebody applied to them. Theirs to see. */
            'tags' => $this->rows(
                'SELECT tag FROM {user_tags} WHERE user_id = ?',
                [$user->id]
            ),

            /*
             * A calendar feed, WITHOUT its token. The token is the whole of the
             * authentication for that feed, and an export is a file that gets
             * emailed onwards — SecretGuard would throw on it, which is the
             * backstop rather than the reason.
             */
            'calendar_feed' => $this->rows(
                'SELECT created_at, last_used_at, fetches FROM {calendar_feeds} WHERE user_id = ?',
                [$user->id]
            ),
        ];
    }

    /**
     * Their own prayer requests.
     *
     * ANONYMOUS STAYS ANONYMOUS, even here. The display name comes from
     * PrayerName::for(), the only function in the application allowed to produce
     * one — reading the column directly would be a second implementation of a
     * rule whose whole point is that there is exactly one.
     *
     * It is their own request either way, so the content is theirs; what the
     * export must not do is tell them the wall showed their name when it showed
     * "Anonymous", or the reverse.
     *
     * @return list<array<string, mixed>>
     */
    private function prayerRequests(User $user): array
    {
        $out = [];

        foreach (
            $this->rows(
                'SELECT * FROM {prayer_requests} WHERE user_id = ? ORDER BY created_at DESC',
                [$user->id]
            ) as $row
        ) {
            $out[] = [
                'body'         => $row['body'] ?? null,
                'visibility'   => $row['visibility'] ?? null,
                'status'       => $row['status'] ?? null,
                'answer_note'  => $row['answer_note'] ?? null,
                'shown_as'     => \Portal\Prayer\PrayerName::for($row),
                'prayed_count' => $row['prayed_count'] ?? 0,
                'created_at'   => $row['created_at'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The small groups they are in.
     *
     * THE ADDRESS GOES THROUGH GroupAddress::for(). Reading the column here
     * would be a second implementation of the one rule that decides who learns
     * where a leader lives, and two implementations of it eventually disagree.
     * A member who is actually in the group gets it; one who only asked does
     * not, which is the same answer the group's own page gives them.
     *
     * @return list<array<string, mixed>>
     */
    private function smallGroups(User $user): array
    {
        $out = [];

        foreach (
            $this->rows(
                'SELECT g.*, m.state, m.created_at AS joined_at
                   FROM {small_group_members} m
                   INNER JOIN {small_groups} g ON g.id = m.group_id
                  WHERE m.user_id = ?',
                [$user->id]
            ) as $row
        ) {
            $out[] = [
                'name'       => $row['name'] ?? null,
                'area'       => $row['area'] ?? null,
                'meets'      => $row['meets'] ?? null,
                'state'      => $row['state'] ?? null,
                'joined_at'  => $row['joined_at'] ?? null,
                'address'    => \Portal\Groups\GroupAddress::for($row, (string) ($row['state'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * A query that must never take the page down.
     *
     * Every source here is optional in the sense that matters: a plugin table
     * dropped on uninstall, a core table not yet created by a half-applied
     * migration. An export missing one section is worth handing over; a 500 is
     * not, and it is the section somebody would most want that fails first when
     * their data is unusual.
     *
     * @param list<mixed> $args
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $args): array
    {
        try {
            return $this->db->all($sql, $args);
        } catch (Throwable $e) {
            error_log('Personal data export: skipped a section. ' . $e->getMessage());

            /*
             * REMEMBERED, not only logged.
             *
             * Swallowing the error keeps the export worth handing over, which is
             * right. But it also means a mistyped column name produces a section
             * that is silently EMPTY — and "a partial export looks complete, so
             * it is worse than none" is the rule this whole file is built on.
             * Catching and forgetting mechanises the exact failure it exists to
             * prevent.
             *
             * Four columns were wrong when the church-life sections were added
             * here, and every one of them came back as an empty list that looked
             * like somebody with nothing on their rota.
             */
            $this->failures[] = $e->getMessage();

            return [];
        }
    }

    /**
     * Queries that did not run, if any.
     *
     * Public so a test can assert there were none — which is the only cheap way
     * to tell "this member has nothing on their rota" apart from "the rota query
     * names a column that does not exist".
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
