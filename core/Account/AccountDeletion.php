<?php

declare(strict_types=1);

namespace Portal\Account;

use Portal\Auth\Capability;
use Portal\Auth\User;
use Portal\Db;
use Portal\Plugins\Hooks;
use Portal\Support\Audit;

/**
 * Somebody deleting their own account.
 *
 * # WHAT GOES, AND WHAT STAYS BEHIND DETACHED
 *
 * "Delete account" has to mean the person's data goes — and it cannot mean
 * that everything they ever touched vanishes, because some of it is a record
 * somebody else relies on. So every table keyed to a person is in exactly one of
 * two groups, and a test reads the schema and fails on any table in neither:
 *
 *   GONE. Everything that is only theirs: watch history, saved videos, notes,
 *   bookmarks, rota memberships and blockouts, reminder settings, calendar
 *   feeds, chat, identities — removed by the foreign keys' own CASCADE when the
 *   user row goes. And the things keyed by EMAIL rather than by id, which no
 *   foreign key reaches: subscriptions (which would otherwise keep mailing a
 *   person who deleted their account) and the record of what was sent to them.
 *   Plus the two with no foreign key at all: their sessions, and permission
 *   grants addressed to them.
 *
 *   KEPT, DETACHED. Things they gave to somebody else: an event sign-up is on an
 *   organiser's list and holds a place under a row lock and a waiting list,
 *   which deleting the row would bypass; a form response is a record the church
 *   received; a prayer request may already be on a wall other people prayed
 *   for. Their user_id becomes NULL through the foreign key, so they are no
 *   longer connected to any account — and the screen lists them BEFORE anything
 *   is deleted, with how to cancel a sign-up first, so nobody learns afterwards
 *   that something outlived the button.
 *
 * # THE LAST ADMINISTRATOR IS REFUSED
 *
 * On a host with no shell, the last administrator deleting themselves leaves
 * nobody who can grant access to anybody, and there is no command line to put
 * it back. Local sign-in is this product's break-glass path precisely because of
 * that situation; this refusal is the same reasoning pointed the other way.
 */
final class AccountDeletion
{
    /**
     * Tables whose rows are KEPT when their person deletes their account, and
     * why. Their foreign key is ON DELETE SET NULL, which does the detaching.
     *
     * @var array<string, string>
     */
    public const KEPT = [
        'event_signups'        => 'a sign-up is on an organiser\'s list and holds a place; cancel it on the event first to free the place',
        'form_responses'       => 'a response is a record of something you sent the church',
        'prayer_requests'      => 'a request others have read and prayed for stays, no longer connected to your account',
        'broadcast_recipients' => 'the log of who a message was sent to, kept so a resend never reaches you twice',
        'schedule_people'      => 'a name on a rota the church keeps, no longer linked to your account',
        'rota_assignments'     => 'only where somebody covered a slot you had asked cover for — the slot is theirs now',
    ];

    /**
     * Tables REMOVED explicitly, because no foreign key reaches all of their
     * rows: they are keyed by email, or by an id with no constraint. Named here
     * so the completeness test can tell "handled" from "forgotten".
     *
     * @var list<string>
     */
    public const REMOVED_EXPLICITLY = ['subscriptions', 'notifications', 'grants', 'sessions'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Why this person may not delete their account right now, or null.
     *
     * The typed address is compared case-insensitively and trimmed, because the
     * point of typing it is to be deliberate, not to be exact — somebody who
     * types their own address with a capital letter has plainly meant it.
     */
    public function refusal(User $user, string $typedEmail): ?string
    {
        if (strcasecmp(trim($typedEmail), $user->email) !== 0) {
            return 'Type your own email address exactly to confirm.';
        }

        if ($user->isAdmin() && $this->adminCount() <= 1) {
            return 'You are the only administrator. Make somebody else an administrator first, '
                . 'or nobody will be able to let anybody in again.';
        }

        return null;
    }

    /**
     * What will stay behind, detached, for the screen to say BEFORE deletion.
     *
     * @return array<string, array{count: int, reason: string}> only non-empty
     */
    public function whatStays(User $user): array
    {
        $out = [];

        foreach (self::KEPT as $table => $reason) {
            $column = $table === 'rota_assignments' ? 'covering_for_user_id' : 'user_id';

            try {
                $count = (int) $this->db->value(
                    "SELECT COUNT(*) FROM {{$table}} WHERE {$column} = ?",
                    [$user->id]
                );
            } catch (\Throwable) {
                // A table from a migration not yet applied has nothing in it.
                continue;
            }

            if ($count > 0) {
                $out[$table] = ['count' => $count, 'reason' => $reason];
            }
        }

        return $out;
    }

    /**
     * Delete the account.
     *
     * In ONE transaction: a failure part-way must leave the account whole rather
     * than a person with no subscriptions and still an account, or an account
     * gone and its email still subscribed. The audit entry is written inside it
     * too, and stores the email rather than an id, because the id is about to
     * mean nothing.
     *
     * Plugins own their own tables and are told first, through the same kind of
     * hook the export uses, so a comment or a push subscription does not outlive
     * the account merely because core does not know the table exists.
     */
    public function delete(User $user): void
    {
        $this->db->transaction(function () use ($user): void {
            // Strict: a plugin that cannot clear its rows stops the deletion.
            Hooks::instance()->doActionOrFail('account_deleting', $user);

            $email = $user->email;

            foreach ([
                'DELETE FROM {subscriptions} WHERE email = ? OR user_id = ?' => [$email, $user->id],
                'DELETE FROM {notifications} WHERE recipient_email = ?'      => [$email],
                /*
                 * Grants addressed to this ACCOUNT. No foreign key covers them.
                 *
                 * NOT the ones addressed to the email: those are an
                 * administrator's pre-authorization of an address, made before
                 * any account existed — a decision about who may be let in,
                 * like the sign-in allowlist, rather than this person's data.
                 */
                'DELETE FROM {grants} WHERE subject_type = "user" AND subject_id = ?'
                    => [$user->id],
                'DELETE FROM {sessions} WHERE user_id = ?'                    => [$user->id],
            ] as $sql => $params) {
                try {
                    $this->db->execute($sql, $params);
                } catch (\Throwable $e) {
                    /*
                     * Only a missing table is tolerated — a site part-way through
                     * its migrations. Anything else must abort the transaction,
                     * or the account is deleted with its subscriptions intact.
                     */
                    if (!str_contains($e->getMessage(), "doesn't exist")) {
                        throw $e;
                    }
                }
            }

            $this->db->execute('DELETE FROM {users} WHERE id = ?', [$user->id]);

            Audit::log($this->db, $email, 'account.delete', 'user', (string) $user->id, $email);
        });
    }

    private function adminCount(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {users} u INNER JOIN {roles} r ON r.id = u.role_id WHERE r.slug = ?',
            [Capability::ROLE_ADMIN]
        );
    }
}
