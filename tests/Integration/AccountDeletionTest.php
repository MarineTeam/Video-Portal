<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Account\AccountDeletion;
use Portal\Auth\Capability;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\User;
use Portal\Config;
use Portal\Container;
use Portal\Db;
use Portal\Http\Router;
use Portal\Plugins\Comments\CommentPolicy;
use Portal\Plugins\Comments\CommentRepository;
use Portal\Plugins\Hooks;
use Portal\Plugins\PluginManager;
use Portal\Plugins\Ratings\RatingRepository;
use Portal\Plugins\Reactions\ReactionRepository;

/**
 * Somebody deleting their own account, against a real database.
 *
 * The bundled plugins that keep rows about a person are ACTIVATED here, so the
 * schema check below sees their tables too, and so the hooks that clear them
 * run inside the same transaction a real deletion uses.
 */
final class AccountDeletionTest extends DatabaseTestCase
{
    /**
     * Plugin tables cleared by the plugin's own account_deleting listener.
     * Each one has a behavioural test below, which is what keeps this list
     * honest: naming a table here without clearing it fails that test.
     *
     * @var array<string, string>
     */
    private const PLUGIN_CLEARED = [
        'comments'        => 'CommentRepository::forgetAuthor',
        'comment_reports' => 'CommentRepository::forgetAuthor',
        'ratings'         => 'RatingRepository::forgetRater',
        'reactions'       => 'ReactionRepository::forgetReactor',
    ];

    /**
     * Columns holding an ADDRESS that deletion deliberately leaves alone, each
     * with the reason. An address is not an account: most of these were written
     * by an administrator about somebody who may never have had one.
     *
     * @var array<string, string>
     */
    private const ADDRESS_EXCUSED = [
        'users.email'                => 'the row being deleted',
        'user_identities.email'      => 'removed with the account by its user_id cascade',
        'audit_log.actor_email'      => 'the audit trail is the one trace deletion is meant to leave',
        'access_attempts.email'      => 'a refused sign-in is the site\'s security record, not the member\'s data',
        'grants.email'               => 'an administrator\'s pre-authorization of an address, not the person\'s data',
        'signin_allowlist.email'     => 'who may sign in is the site\'s decision; deleting it would let the address back unasked',
        'guest_exemptions.email'     => 'an administrator\'s decision to exempt a guest, removed on the same screen',
        'shares.recipient_email'     => 'a share is its sender\'s record of what they sent, and revoking it is theirs to do',
        'bundles.recipient_email'    => 'follows the shares it groups',
        'gate_grants.email'          => 'belongs to a share link and expires by itself',
        'private_list_entries.email' => 'an administrator\'s list of who may see a video',
        'viewer_group_members.email' => 'an administrator\'s list of who is in a viewer group',
        'group_members.email'        => 'membership an administrator set up by address; rows linked to the account cascade',
        'shares.created_by'          => 'the sender\'s own record of a share they made',
        'event_signups.email'        => 'kept detached — see AccountDeletion::KEPT; the organiser\'s list needs a contact',
        'form_responses.from_email'  =>'kept detached — see AccountDeletion::KEPT; the address is part of what was sent',
        'api_keys.created_by'        => 'who issued a site credential, which outlives the person who issued it',
        'broadcasts.created_by'      => 'who sent a message on the church\'s behalf',
    ];

    private AccountDeletion $deletion;

    protected function setUp(): void
    {
        (new PermissionSeeder($this->db()))->seed();

        Hooks::reset();
        Container::reset();
        Container::instance()->set(Db::class, $this->db());

        $manager = new PluginManager(
            $this->db(),
            new Config('/nonexistent-config.php'),
            Hooks::instance(),
            new Router(),
        );

        foreach (['comments', 'ratings', 'reactions', 'push', 'whats-new'] as $slug) {
            // Again on every test: hooks were reset above, and activating an
            // active plugin skips its applied migrations and re-registers them.
            $result = $manager->activate($slug);
            self::assertTrue($result['ok'], "Could not activate {$slug}: " . $result['message']);
        }

        $this->deletion = new AccountDeletion($this->db());
    }

    protected function tearDown(): void
    {
        Hooks::reset();
        Container::reset();
    }

    // -------------------------------------------------------------- refusing

    public function testTheTypedAddressMustBeTheirOwn(): void
    {
        $user = $this->person();

        self::assertNotNull($this->deletion->refusal($user, 'somebody-else@example.test'));
        self::assertNotNull($this->deletion->refusal($user, ''));
    }

    /** Typing it is about being deliberate, not exact. */
    public function testCaseAndSurroundingSpaceDoNotMatter(): void
    {
        $user = $this->person();

        self::assertNull($this->deletion->refusal($user, '  ' . strtoupper($user->email) . ' '));
    }

    public function testTheLastAdministratorIsRefused(): void
    {
        $this->db()->execute('UPDATE {users} SET role_id = NULL');
        $admin = $this->person(Capability::ROLE_ADMIN);

        self::assertNotNull($this->deletion->refusal($admin, $admin->email));

        // With a second administrator, nobody is stranded.
        $this->person(Capability::ROLE_ADMIN);
        self::assertNull($this->deletion->refusal($admin, $admin->email));
    }

    // -------------------------------------------------------------- deleting

    public function testTheAccountAndEverythingOnlyTheirsGoes(): void
    {
        $user = $this->person();
        $video = $this->video();
        $now = date('Y-m-d H:i:s');

        $this->db()->insert('watch_progress', [
            'user_id' => $user->id, 'video_id' => $video, 'position_seconds' => 30,
            'duration_seconds' => 100, 'updated_at' => $now,
        ]);
        // Subscribed while signed out: no user_id, and no foreign key reaches it.
        $this->db()->insert('subscriptions', [
            'token' => bin2hex(random_bytes(8)), 'email' => $user->email,
            'scope_type' => 'site', 'created_at' => $now,
        ]);
        $this->db()->insert('notifications', [
            'recipient_email' => $user->email, 'channel' => 'email', 'title' => 'New', 'created_at' => $now,
        ]);
        $this->db()->insert('sessions', [
            'id' => bin2hex(random_bytes(16)), 'user_id' => $user->id, 'payload' => '',
            'created_at' => $now, 'last_active_at' => $now,
        ]);

        $this->deletion->delete($user);

        self::assertSame(0, $this->rowCount('users', 'id', $user->id));
        self::assertSame(0, $this->rowCount('watch_progress', 'user_id', $user->id));
        self::assertSame(0, $this->rowCount('subscriptions', 'email', $user->email),
            'a subscription would keep mailing somebody who deleted their account');
        self::assertSame(0, $this->rowCount('notifications', 'recipient_email', $user->email));
        self::assertSame(0, $this->rowCount('sessions', 'user_id', $user->id));
    }

    /**
     * A grant addressed to the ACCOUNT goes; one addressed to the ADDRESS stays.
     * The second is an administrator's decision about who may be let in.
     */
    public function testGrantsToTheAccountGoAndGrantsToTheAddressStay(): void
    {
        $user = $this->person();
        $capability = (int) $this->db()->value('SELECT id FROM {capabilities} WHERE slug = ?', [Capability::MANAGE_VIDEOS]);
        $now = date('Y-m-d H:i:s');

        $this->db()->insert('grants', [
            'subject_type' => 'user', 'subject_id' => $user->id, 'capability_id' => $capability, 'created_at' => $now,
        ]);
        $this->db()->insert('grants', [
            'subject_type' => 'email', 'email' => $user->email, 'capability_id' => $capability, 'created_at' => $now,
        ]);

        $this->deletion->delete($user);

        self::assertSame(0, (int) $this->db()->value(
            'SELECT COUNT(*) FROM {grants} WHERE subject_type = "user" AND subject_id = ?', [$user->id]
        ));
        self::assertSame(1, (int) $this->db()->value(
            'SELECT COUNT(*) FROM {grants} WHERE subject_type = "email" AND email = ?', [$user->email]
        ));
    }

    /** Kept rows are detached — and the screen could say so beforehand. */
    public function testWhatStaysIsCountedAndThenDetached(): void
    {
        $user = $this->person();
        $now = date('Y-m-d H:i:s');

        $this->db()->insert('prayer_requests', [
            'user_id' => $user->id, 'body' => 'For my mother', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $stays = $this->deletion->whatStays($user);
        self::assertSame(1, $stays['prayer_requests']['count'] ?? null);
        self::assertArrayNotHasKey('event_signups', $stays, 'only non-empty groups are listed');

        $this->deletion->delete($user);

        self::assertSame(1, (int) $this->db()->value(
            'SELECT COUNT(*) FROM {prayer_requests} WHERE body = ? AND user_id IS NULL', ['For my mother']
        ));
    }

    public function testTheAuditEntryNamesTheAddress(): void
    {
        $user = $this->person();

        $this->deletion->delete($user);

        self::assertSame(1, (int) $this->db()->value(
            'SELECT COUNT(*) FROM {audit_log} WHERE action = ? AND actor_email = ? AND detail = ?',
            ['account.delete', $user->email, $user->email]
        ));
    }

    /**
     * A listener that fails leaves the account WHOLE. The other order — account
     * gone, subscriptions and comments still carrying the address — is the one
     * nobody can put right afterwards.
     */
    public function testAFailurePartWayLeavesTheAccountWhole(): void
    {
        $user = $this->person();
        $this->db()->insert('subscriptions', [
            'token' => bin2hex(random_bytes(8)), 'email' => $user->email,
            'scope_type' => 'site', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        add_action('account_deleting', static function (): void {
            throw new \RuntimeException('a plugin could not clear its table');
        }, 99);

        // Not catch-and-fail: PHPUnit's own failure is a RuntimeException too,
        // and catching that would swallow the very assertion meant to notice.
        $thrown = null;
        try {
            $this->deletion->delete($user);
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        self::assertSame('a plugin could not clear its table', $thrown, 'the failure was swallowed');

        self::assertSame(1, $this->rowCount('users', 'id', $user->id));
        self::assertSame(1, $this->rowCount('subscriptions', 'email', $user->email));
    }

    // ------------------------------------------------------- plugins' tables

    public function testTheirCommentsGoButOtherPeoplesRepliesSurvive(): void
    {
        $user = $this->person();
        $video = $this->video();
        $comments = new CommentRepository($this->db());
        $approved = CommentPolicy::STATUS_APPROVED;

        $answered = $comments->create($video, null, 'Leaver', $user->email, 'Answered', $approved, '10.0.0.1');
        $comments->create($video, (int) $answered['id'], 'Stayer', 'stayer@example.test', 'A reply', $approved, '');
        $alone = $comments->create($video, null, 'Leaver', $user->email, 'Alone', $approved, '');
        $selfAnswered = $comments->create($video, null, 'Leaver', $user->email, 'Self', $approved, '');
        $comments->create($video, (int) $selfAnswered['id'], 'Leaver', $user->email, 'Me again', $approved, '');

        $this->deletion->delete($user);

        self::assertSame(0, $this->rowCount('comments', 'author_email', $user->email));
        self::assertNull($comments->find((int) $alone['id']));
        self::assertNull($comments->find((int) $selfAnswered['id']),
            'a comment whose only replies were their own is not worth a tombstone');

        $tombstone = $this->db()->first('SELECT * FROM {comments} WHERE id = ?', [(int) $answered['id']]);
        self::assertNotNull($tombstone, 'deleting it would have taken somebody else\'s reply with it');
        self::assertSame(CommentPolicy::STATUS_REMOVED, $tombstone['status']);
        self::assertSame(['', '', '', ''], [
            $tombstone['body'], $tombstone['author_name'], $tombstone['author_email'], $tombstone['ip'],
        ]);
        self::assertSame(1, $this->rowCount('comments', 'author_email', 'stayer@example.test'));
    }

    public function testReportsTheyFiledGoAndTheCountIsRecounted(): void
    {
        $user = $this->person();
        $comments = new CommentRepository($this->db());
        $comment = $comments->create($this->video(), null, 'Other', 'other@example.test', 'Hm', CommentPolicy::STATUS_APPROVED, '');

        $comments->report((int) $comment['id'], $user->email, 'rude');
        $comments->report((int) $comment['id'], 'third@example.test', 'rude');

        $this->deletion->delete($user);

        self::assertSame(0, $this->rowCount('comment_reports', 'reporter_email', $user->email));
        self::assertSame(1, (int) $this->db()->value('SELECT report_count FROM {comments} WHERE id = ?', [(int) $comment['id']]));
    }

    public function testTheirRatingsGoAndTheAverageIsRecounted(): void
    {
        $user = $this->person();
        $video = $this->video();
        $ratings = new RatingRepository($this->db());

        $ratings->rate($video, $user->email, 1);
        $ratings->rate($video, 'other@example.test', 5);

        $this->deletion->delete($user);

        self::assertSame(0, $this->rowCount('ratings', 'rater_email', $user->email));
        self::assertSame(1, (int) $this->db()->value('SELECT vote_count FROM {rating_totals} WHERE video_id = ?', [$video]));
    }

    public function testTheirReactionsGo(): void
    {
        $user = $this->person();
        $video = $this->video();
        $reactions = new ReactionRepository($this->db());

        $reactions->toggle($video, $user->id, $user->email, 'amen');
        $reactions->toggle($video, null, 'other@example.test', 'amen');

        $this->deletion->delete($user);

        self::assertSame(0, $this->rowCount('reactions', 'reactor_email', $user->email));
        self::assertSame(1, $reactions->forVideo($video)['amen']);
    }

    // ------------------------------------------------------------ the schema

    /**
     * Every foreign key onto {users} either CASCADEs, or its table is KEPT on
     * purpose, or something clears it. A new table with ON DELETE SET NULL
     * fails here rather than quietly keeping somebody's rows forever.
     */
    public function testEveryForeignKeyOntoUsersIsAccountedFor(): void
    {
        $prefix = $this->db()->prefix();
        $rows = $this->db()->all(
            'SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c, r.DELETE_RULE AS rule
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = ?',
            [$prefix . 'users']
        );

        self::assertNotSame([], $rows, 'no foreign keys onto users were found, so this proves nothing');

        $unaccounted = [];
        foreach ($rows as $row) {
            $table = substr((string) $row['t'], strlen($prefix));

            if ($row['rule'] === 'CASCADE'
                || isset(AccountDeletion::KEPT[$table])
                || isset(self::PLUGIN_CLEARED[$table])
                || in_array($table, AccountDeletion::REMOVED_EXPLICITLY, true)) {
                continue;
            }

            $unaccounted[] = "{$table}.{$row['c']} ({$row['rule']})";
        }

        self::assertSame([], $unaccounted, "Rows that would outlive a deleted account:\n  " . implode("\n  ", $unaccounted)
            . "\n\nCASCADE them, clear them in AccountDeletion or the owning plugin, or add them to KEPT with the reason.");
    }

    /**
     * A user_id with no foreign key at all is reached by nothing — sessions is
     * the example — so each one must be cleared explicitly.
     */
    public function testEveryUnconstrainedUserIdIsClearedExplicitly(): void
    {
        $prefix = $this->db()->prefix();
        $tables = $this->db()->column(
            'SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
              WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = ? AND c.TABLE_NAME LIKE ?
                AND NOT EXISTS (
                  SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k
                   WHERE k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME
                     AND k.COLUMN_NAME = c.COLUMN_NAME AND k.REFERENCED_TABLE_NAME IS NOT NULL)',
            ['user_id', $this->db()->escapeLike($prefix) . '%']
        );

        $unaccounted = array_values(array_filter(
            array_map(static fn ($t): string => substr((string) $t, strlen($prefix)), $tables),
            static fn (string $t): bool => !in_array($t, AccountDeletion::REMOVED_EXPLICITLY, true)
        ));

        self::assertSame([], $unaccounted, 'user_id with no foreign key and nothing clearing it: ' . implode(', ', $unaccounted));
    }

    /**
     * Every column holding an address is cleared or excused with a reason. No
     * foreign key reaches an address, so this is the only thing that notices.
     */
    public function testEveryAddressColumnIsClearedOrExcused(): void
    {
        $prefix = $this->db()->prefix();
        $rows = $this->db()->all(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?
                AND (COLUMN_NAME LIKE ? OR COLUMN_NAME = ?)
                AND DATA_TYPE IN (?, ?)',
            [$this->db()->escapeLike($prefix) . '%', '%email', 'created_by', 'varchar', 'text']
        );

        $unaccounted = [];
        foreach ($rows as $row) {
            $table = substr((string) $row['t'], strlen($prefix));
            $key = "{$table}.{$row['c']}";

            if (isset(self::ADDRESS_EXCUSED[$key])
                || isset(self::PLUGIN_CLEARED[$table])
                || in_array($table, AccountDeletion::REMOVED_EXPLICITLY, true)) {
                continue;
            }

            $unaccounted[] = $key;
        }

        // An excuse for a column that does not exist is a reason nobody is checking.
        $present = array_map(static fn (array $r): string => substr((string) $r['t'], strlen($prefix)) . '.' . $r['c'], $rows);
        self::assertSame([], array_values(array_diff(array_keys(self::ADDRESS_EXCUSED), $present)), 'excused columns that no longer exist');

        self::assertSame([], $unaccounted, "Addresses nothing clears and nobody has excused:\n  " . implode("\n  ", $unaccounted));
    }

    // -------------------------------------------------------------- fixtures

    private function person(string $role = 'viewer'): User
    {
        $now = date('Y-m-d H:i:s');
        $email = 'leaver-' . bin2hex(random_bytes(4)) . '@example.test';
        $roleId = $this->db()->value('SELECT id FROM {roles} WHERE slug = ?', [$role]);

        $id = (int) $this->db()->insert('users', [
            'email' => $email, 'name' => 'Leaver', 'authorized' => 1,
            'role_id' => $roleId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return new User(id: $id, email: $email, name: 'Leaver', roleSlug: $role, authorized: true, emailVerified: true);
    }

    private function video(): int
    {
        $suffix = bin2hex(random_bytes(4));
        $now = date('Y-m-d H:i:s');

        return (int) $this->db()->insert('videos', [
            'provider_id' => 'bunny-' . $suffix, 'slug' => 'video-' . $suffix, 'title' => 'A video',
            'status' => 'ready', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function rowCount(string $table, string $column, int|string $value): int
    {
        return (int) $this->db()->value("SELECT COUNT(*) FROM {{$table}} WHERE {$column} = ?", [$value]);
    }
}
