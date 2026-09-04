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
        ];

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

            return [];
        }
    }
}
