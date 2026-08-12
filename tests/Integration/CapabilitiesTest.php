<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\Capabilities;
use Portal\Auth\Capability;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\User;
use Portal\Auth\UserRepository;

/**
 * Permission resolution, exercised against a real database.
 *
 * These are the tests that matter most in the project. A permission bug does
 * not announce itself — it either quietly grants access to something private,
 * or quietly denies a legitimate editor and gets reported as "the admin area is
 * broken". Neither shows up in a syntax check.
 */
final class CapabilitiesTest extends DatabaseTestCase
{
    private Capabilities $capabilities;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->truncate([
            'grants', 'group_members', 'group_capabilities', 'permission_groups',
            'role_capabilities', 'capabilities', 'roles', 'users',
            'video_categories', 'videos', 'series', 'categories',
        ]);

        (new PermissionSeeder($this->db()))->seed();

        $this->capabilities = new Capabilities($this->db());
        $this->users = new UserRepository($this->db());
    }

    // ------------------------------------------------------------- baseline

    public function testAnonymousHoldsNothing(): void
    {
        foreach (array_keys(Capability::all()) as $capability) {
            self::assertFalse(
                $this->capabilities->can(null, $capability),
                "Anonymous should not hold {$capability}"
            );
        }
    }

    public function testAdminHoldsEverythingWithoutAnyExplicitGrant(): void
    {
        $admin = $this->makeUser('admin@example.com', Capability::ROLE_ADMIN, authorized: true);

        foreach (array_keys(Capability::all()) as $capability) {
            self::assertTrue(
                $this->capabilities->can($admin, $capability),
                "Admin should hold {$capability}"
            );
        }
    }

    /**
     * The load-bearing distinction of the whole model: authentication is not
     * authorization. An account that exists but has not been approved must
     * hold nothing, including the capability its role nominally carries.
     */
    public function testUnauthorizedAccountHoldsNothingDespiteItsRole(): void
    {
        $pending = $this->makeUser('pending@example.com', 'editor', authorized: false);

        self::assertFalse($this->capabilities->can($pending, Capability::VIEW_CONTENT));
        self::assertFalse($this->capabilities->can($pending, Capability::MANAGE_VIDEOS));
    }

    public function testAuthorizedViewerHoldsOnlyViewContent(): void
    {
        $viewer = $this->makeUser('viewer@example.com', Capability::ROLE_VIEWER, authorized: true);

        self::assertTrue($this->capabilities->can($viewer, Capability::VIEW_CONTENT));
        self::assertFalse($this->capabilities->can($viewer, Capability::MANAGE_VIDEOS));
        self::assertFalse($this->capabilities->can($viewer, Capability::MANAGE_USERS));
    }

    public function testRoleCapabilitiesApplySiteWide(): void
    {
        $editor = $this->makeUser('editor@example.com', 'editor', authorized: true);

        self::assertTrue($this->capabilities->can($editor, Capability::MANAGE_VIDEOS));
        self::assertTrue($this->capabilities->can($editor, Capability::PUBLISH_CONTENT));
        // An editor manages content, not the site.
        self::assertFalse($this->capabilities->can($editor, Capability::MANAGE_USERS));
        self::assertFalse($this->capabilities->can($editor, Capability::MANAGE_PROVIDERS));
    }

    // --------------------------------------------------------------- scoping

    public function testScopedGrantAppliesInsideItsScopeOnly(): void
    {
        $user = $this->makeUser('scoped@example.com', Capability::ROLE_VIEWER, authorized: true);

        $sermons = $this->makeCategory('Sermons');
        $classes = $this->makeCategory('Classes');

        $this->grant($user->id, Capability::MANAGE_VIDEOS, 'category', $sermons);

        self::assertTrue(
            $this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $sermons),
            'The grant should apply inside its own category.'
        );
        self::assertFalse(
            $this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $classes),
            'The grant must not leak into a sibling category.'
        );
        self::assertFalse(
            $this->capabilities->can($user, Capability::MANAGE_VIDEOS),
            'A scoped grant must not satisfy an unscoped check.'
        );
    }

    public function testCategoryGrantsAreInheritedByDescendants(): void
    {
        $user = $this->makeUser('nested@example.com', Capability::ROLE_VIEWER, authorized: true);

        $sermons = $this->makeCategory('Sermons');
        $year    = $this->makeCategory('2026', $sermons);
        $advent  = $this->makeCategory('Advent', $year);

        $this->grant($user->id, Capability::MANAGE_VIDEOS, 'category', $sermons);

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $year));
        self::assertTrue(
            $this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $advent),
            'A grant on an ancestor must reach two levels down.'
        );
    }

    public function testInheritanceDoesNotRunUpwards(): void
    {
        $user = $this->makeUser('upward@example.com', Capability::ROLE_VIEWER, authorized: true);

        $sermons = $this->makeCategory('Sermons');
        $advent  = $this->makeCategory('Advent', $sermons);

        $this->grant($user->id, Capability::MANAGE_VIDEOS, 'category', $advent);

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $advent));
        self::assertFalse(
            $this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $sermons),
            'A grant on a child must never confer rights over its parent.'
        );
    }

    public function testVideoInheritsFromItsCategory(): void
    {
        $user = $this->makeUser('videocat@example.com', Capability::ROLE_VIEWER, authorized: true);

        $sermons = $this->makeCategory('Sermons');
        $video   = $this->makeVideo('A sermon');
        $this->assignVideoToCategory($video, $sermons);

        $this->grant($user->id, Capability::MANAGE_VIDEOS, 'category', $sermons);

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'video', $video));
    }

    public function testVideoInheritsThroughSeriesToCategory(): void
    {
        $user = $this->makeUser('videoseries@example.com', Capability::ROLE_VIEWER, authorized: true);

        $category = $this->makeCategory('Teaching');
        $series   = $this->makeSeries('Romans', $category);
        $video    = $this->makeVideo('Romans 1', $series);

        $this->grant($user->id, Capability::MANAGE_VIDEOS, 'category', $category);

        self::assertTrue(
            $this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'video', $video),
            'Video -> series -> category inheritance should resolve.'
        );
    }

    public function testSiteWideGrantSatisfiesEveryScope(): void
    {
        $user = $this->makeUser('sitewide@example.com', Capability::ROLE_VIEWER, authorized: true);
        $category = $this->makeCategory('Anything');

        $this->grant($user->id, Capability::MANAGE_VIDEOS, 'site', 0);

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS));
        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $category));
    }

    /**
     * Scoping a site-only capability is meaningless. The resolver must answer
     * the site-wide question rather than silently returning false because a
     * scope was supplied.
     */
    public function testSiteOnlyCapabilitiesIgnoreScope(): void
    {
        $user = $this->makeUser('siteonly@example.com', Capability::ROLE_VIEWER, authorized: true);
        $category = $this->makeCategory('Irrelevant');

        $this->grant($user->id, Capability::MANAGE_PLUGINS, 'site', 0);

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_PLUGINS, 'category', $category));
    }

    // ----------------------------------------------------------- canAnywhere

    /*
     * canAnywhere() is what every admin LISTING asks, because a list has no one
     * object to name. It is deliberately weaker than can(), which makes it the
     * most dangerous method in this class: if it ever drifts into "yes", every
     * listing opens to everyone, and nothing else in the suite would notice
     * because the per-object checks would still be correct.
     *
     * So it is pinned from both directions — what it must admit, and what it
     * must still refuse.
     */

    public function testCanAnywhereAdmitsAScopedGrantThatTheSiteWideCheckRefuses(): void
    {
        $user = $this->makeUser('anywhere@example.com', Capability::ROLE_VIEWER, authorized: true);
        $sermons = $this->makeCategory('Sermons');

        $this->grant($user->id, Capability::MANAGE_VIDEOS, 'category', $sermons);

        self::assertFalse(
            $this->capabilities->can($user, Capability::MANAGE_VIDEOS),
            'Precondition: the site-wide question is still no. This is the gap canAnywhere exists to bridge.'
        );
        self::assertTrue(
            $this->capabilities->canAnywhere($user, Capability::MANAGE_VIDEOS),
            'A grant on one category is a reason to be shown the video list.'
        );
    }

    public function testCanAnywhereRefusesACapabilityHeldNowhere(): void
    {
        $user = $this->makeUser('nothing@example.com', Capability::ROLE_VIEWER, authorized: true);
        $sermons = $this->makeCategory('Sermons');

        // A grant of a DIFFERENT capability, so the row exists and the join is
        // exercised. Matching on the row rather than on the capability is the
        // likeliest way for this to go wrong.
        $this->grant($user->id, Capability::MANAGE_VIDEOS, 'category', $sermons);

        self::assertFalse($this->capabilities->canAnywhere($user, Capability::MANAGE_CATEGORIES));
        self::assertFalse($this->capabilities->canAnywhere($user, Capability::MANAGE_SERIES));
    }

    public function testCanAnywhereRefusesAnonymousAndUnapprovedAccounts(): void
    {
        self::assertFalse($this->capabilities->canAnywhere(null, Capability::MANAGE_VIDEOS));

        $pending = $this->makeUser('pending-anywhere@example.com', 'editor', authorized: false);
        $sermons = $this->makeCategory('Sermons');
        $this->grant($pending->id, Capability::MANAGE_VIDEOS, 'category', $sermons);

        self::assertFalse(
            $this->capabilities->canAnywhere($pending, Capability::MANAGE_VIDEOS),
            'Authorization is the separate decision; a grant does not substitute for approval.'
        );
    }

    public function testCanAnywhereIsStillTrueForASiteWideHolder(): void
    {
        $editor = $this->makeUser('editor-anywhere@example.com', 'editor', authorized: true);

        self::assertTrue($this->capabilities->can($editor, Capability::MANAGE_VIDEOS));
        self::assertTrue($this->capabilities->canAnywhere($editor, Capability::MANAGE_VIDEOS));
    }

    /**
     * A site-only capability has no scoped form — PermissionRepository stores
     * one site-wide whatever the form said — so canAnywhere must not become a
     * second, weaker way to ask about plugins or settings.
     */
    public function testCanAnywhereGivesSiteOnlyCapabilitiesNoSecondChance(): void
    {
        $user = $this->makeUser('siteonly-anywhere@example.com', Capability::ROLE_VIEWER, authorized: true);
        $category = $this->makeCategory('Sermons');

        // Written straight to the table, bypassing the repository rule, because
        // the question is what the RESOLVER does if such a row ever exists.
        $this->grant($user->id, Capability::MANAGE_PLUGINS, 'category', $category);

        self::assertFalse(
            $this->capabilities->canAnywhere($user, Capability::MANAGE_PLUGINS),
            'A stray scoped row must not open the plugins screen.'
        );
    }

    // ---------------------------------------------------------------- groups

    public function testGroupMembershipConfersCapabilities(): void
    {
        $user = $this->makeUser('grouped@example.com', Capability::ROLE_VIEWER, authorized: true);

        $groupId = $this->db()->insert('permission_groups', [
            'slug' => 'moderators', 'name' => 'Moderators', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->attachGroupCapability($groupId, Capability::MODERATE_COMMENTS);
        $this->db()->insert('group_members', [
            'group_id' => $groupId, 'email' => $user->email, 'user_id' => $user->id,
            'added_at' => date('Y-m-d H:i:s'),
        ]);

        self::assertTrue($this->capabilities->can($user, Capability::MODERATE_COMMENTS));
    }

    /**
     * Permissions can be prepared for someone before their first sign-in, by
     * email. This is what lets an admin set up a new editor in advance.
     */
    public function testGroupMembershipByEmailWorksForAnAccountCreatedLater(): void
    {
        $groupId = $this->db()->insert('permission_groups', [
            'slug' => 'editors', 'name' => 'Editors', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->attachGroupCapability($groupId, Capability::MANAGE_SERIES);

        // Membership recorded before the account exists.
        $this->db()->insert('group_members', [
            'group_id' => $groupId, 'email' => 'future@example.com', 'user_id' => null,
            'added_at' => date('Y-m-d H:i:s'),
        ]);

        $user = $this->makeUser('future@example.com', Capability::ROLE_VIEWER, authorized: true);

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_SERIES));
    }

    public function testEmailAddressedGrantWorksBeforeFirstSignIn(): void
    {
        $this->db()->execute(
            'INSERT INTO {grants} (subject_type, subject_id, email, capability_id, scope_type, scope_id, created_at)
             VALUES ("email", 0, ?, (SELECT id FROM {capabilities} WHERE slug = ?), "site", 0, NOW())',
            ['newcomer@example.com', Capability::MANAGE_VIDEOS]
        );

        $user = $this->makeUser('newcomer@example.com', Capability::ROLE_VIEWER, authorized: true);

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS));
    }

    // ----------------------------------------------------------- escalation

    /**
     * The escalation that must be impossible: someone who can hand out every
     * capability still cannot make themselves an administrator, because admin
     * is a role slug and not a capability.
     */
    public function testManagePermissionsCannotBecomeAdmin(): void
    {
        $user = $this->makeUser('almost@example.com', Capability::ROLE_VIEWER, authorized: true);
        $this->grant($user->id, Capability::MANAGE_PERMISSIONS, 'site', 0);

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_PERMISSIONS));
        self::assertFalse($user->isAdmin());

        // Even holding every capability that exists.
        foreach (array_keys(Capability::all()) as $capability) {
            $this->grant($user->id, $capability, 'site', 0);
        }
        $this->capabilities->flush();

        $reloaded = $this->users->find($user->id);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isAdmin(), 'Holding every capability must not confer the admin role.');
    }

    public function testLastAdminIsProtected(): void
    {
        $admin = $this->makeUser('only@example.com', Capability::ROLE_ADMIN, authorized: true);

        self::assertSame(1, $this->users->countAdmins());
        self::assertTrue($this->users->isLastAdmin($admin->id));

        $this->makeUser('second@example.com', Capability::ROLE_ADMIN, authorized: true);

        self::assertSame(2, $this->users->countAdmins());
        self::assertFalse($this->users->isLastAdmin($admin->id));
    }

    // ------------------------------------------------------------- seeding

    public function testSeedingIsIdempotent(): void
    {
        $seeder = new PermissionSeeder($this->db());

        $before = (int) $this->db()->value('SELECT COUNT(*) FROM {capabilities}');
        $rolesBefore = (int) $this->db()->value('SELECT COUNT(*) FROM {roles}');

        $second = $seeder->seed();

        self::assertSame(0, $second['capabilities'], 'A second seed should create no capabilities.');
        self::assertSame(0, $second['roles'], 'A second seed should create no roles.');
        self::assertSame($before, (int) $this->db()->value('SELECT COUNT(*) FROM {capabilities}'));
        self::assertSame($rolesBefore, (int) $this->db()->value('SELECT COUNT(*) FROM {roles}'));
    }

    /**
     * Re-seeding after an upgrade must not restore a capability an admin
     * deliberately removed from a role.
     */
    public function testReSeedingDoesNotRestoreRemovedRoleCapabilities(): void
    {
        $editorRoleId = (int) $this->db()->value('SELECT id FROM {roles} WHERE slug = ?', ['editor']);
        $capabilityId = (int) $this->db()->value(
            'SELECT id FROM {capabilities} WHERE slug = ?',
            [Capability::PUBLISH_CONTENT]
        );

        $this->db()->execute(
            'DELETE FROM {role_capabilities} WHERE role_id = ? AND capability_id = ?',
            [$editorRoleId, $capabilityId]
        );

        (new PermissionSeeder($this->db()))->seed();

        $restored = $this->db()->value(
            'SELECT 1 FROM {role_capabilities} WHERE role_id = ? AND capability_id = ?',
            [$editorRoleId, $capabilityId]
        );

        self::assertNull($restored, 'Re-seeding must respect a deliberate removal.');
    }

    // ------------------------------------------------------------- fixtures

    private function makeUser(string $email, string $roleSlug, bool $authorized): User
    {
        $user = $this->users->create($email, null, $roleSlug, null, $authorized);
        $this->capabilities->flush();
        return $user;
    }

    private function makeCategory(string $name, ?int $parentId = null): int
    {
        $slug = strtolower($name) . '-' . bin2hex(random_bytes(3));
        $now = date('Y-m-d H:i:s');

        $id = $this->db()->insert('categories', [
            'parent_id'  => $parentId,
            'slug'       => $slug,
            'name'       => $name,
            'path'       => '/',
            'depth'      => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The materialized path is what the ancestor walk reads.
        $parentPath = $parentId === null
            ? '/'
            : (string) $this->db()->value('SELECT path FROM {categories} WHERE id = ?', [$parentId]);

        $path = $parentPath . $id . '/';
        $depth = substr_count(trim($path, '/'), '/');

        $this->db()->execute('UPDATE {categories} SET path = ?, depth = ? WHERE id = ?', [$path, $depth, $id]);
        $this->capabilities->flush();

        return $id;
    }

    private function makeSeries(string $title, ?int $categoryId = null): int
    {
        $now = date('Y-m-d H:i:s');
        return $this->db()->insert('series', [
            'category_id' => $categoryId,
            'slug'        => strtolower($title) . '-' . bin2hex(random_bytes(3)),
            'title'       => $title,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    private function makeVideo(string $title, ?int $seriesId = null): int
    {
        $now = date('Y-m-d H:i:s');
        return $this->db()->insert('videos', [
            'provider'    => 'bunny',
            'provider_id' => bin2hex(random_bytes(8)),
            'slug'        => strtolower(str_replace(' ', '-', $title)) . '-' . bin2hex(random_bytes(3)),
            'title'       => $title,
            'series_id'   => $seriesId,
            'status'      => 'ready',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    private function assignVideoToCategory(int $videoId, int $categoryId): void
    {
        $this->db()->insert('video_categories', [
            'video_id'    => $videoId,
            'category_id' => $categoryId,
            'is_primary'  => 1,
        ]);
    }

    private function grant(int $userId, string $capability, string $scopeType, int $scopeId): void
    {
        $this->db()->execute(
            'INSERT IGNORE INTO {grants}
                (subject_type, subject_id, email, capability_id, scope_type, scope_id, created_at)
             VALUES ("user", ?, "", (SELECT id FROM {capabilities} WHERE slug = ?), ?, ?, NOW())',
            [$userId, $capability, $scopeType, $scopeId]
        );
        $this->capabilities->flush();
    }

    private function attachGroupCapability(int $groupId, string $capability): void
    {
        $this->db()->execute(
            'INSERT IGNORE INTO {group_capabilities} (group_id, capability_id)
             VALUES (?, (SELECT id FROM {capabilities} WHERE slug = ?))',
            [$groupId, $capability]
        );
        $this->capabilities->flush();
    }
}
