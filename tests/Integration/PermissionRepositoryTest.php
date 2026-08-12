<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\Capabilities;
use Portal\Auth\Capability;
use Portal\Auth\PermissionRepository;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\User;

/**
 * Writing the permission model, and the two rules the writer refuses to break.
 *
 * Capabilities is tested separately for reading. What matters here is that the
 * screen cannot store something the resolver would then interpret differently
 * from how its author read it — a permission list that does not mean what it
 * says is worse than no list, because people stop checking it.
 */
final class PermissionRepositoryTest extends DatabaseTestCase
{
    private PermissionRepository $permissions;
    private Capabilities $capabilities;

    protected function setUp(): void
    {
        $this->truncate([
            'grants', 'group_members', 'group_capabilities', 'permission_groups',
            'role_capabilities', 'capabilities', 'roles', 'users', 'categories',
        ]);

        (new PermissionSeeder($this->db()))->seed();

        $this->permissions = new PermissionRepository($this->db());
        $this->capabilities = new Capabilities($this->db());
    }

    // ------------------------------------------------------------------ roles

    public function testRolesComeBackWithWhatTheyHold(): void
    {
        $bySlug = [];
        foreach ($this->permissions->roles() as $role) {
            $bySlug[$role['slug']] = $role;
        }

        self::assertArrayHasKey('editor', $bySlug);
        self::assertContains(Capability::MANAGE_VIDEOS, $bySlug['editor']['capabilities']);
        self::assertNotContains(Capability::MANAGE_USERS, $bySlug['editor']['capabilities']);
    }

    public function testChangingARoleTakesEffectForItsHolders(): void
    {
        $viewer = $this->roleId('viewer');
        $user = $this->user('someone@example.com', $viewer);

        self::assertFalse($this->capabilities->can($user, Capability::MANAGE_VIDEOS));

        $this->permissions->setRoleCapabilities($viewer, [
            Capability::MANAGE_SHARES,
            Capability::MANAGE_VIDEOS,
        ]);
        $this->capabilities->flush();

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS));
    }

    /**
     * The admin role holds everything implicitly and short-circuits every
     * check, so an edit form over it would appear to work while doing nothing.
     * Refused at the repository rather than merely hidden in the UI.
     */
    public function testTheAdminRoleCannotBeEdited(): void
    {
        $this->expectExceptionMessage('administrator role cannot be edited');

        $this->permissions->setRoleCapabilities($this->roleId('admin'), []);
    }

    /** An administrator stays one no matter what the tables say. */
    public function testAnAdministratorIsUnaffectedByRoleCapabilities(): void
    {
        $admin = $this->user('boss@example.com', $this->roleId('admin'));

        self::assertTrue($this->capabilities->can($admin, Capability::MANAGE_PLUGINS));
        self::assertTrue($this->capabilities->can($admin, Capability::MANAGE_PERMISSIONS));
    }

    public function testUnknownCapabilitiesAreIgnoredRatherThanStored(): void
    {
        $viewer = $this->roleId('viewer');

        $this->permissions->setRoleCapabilities($viewer, [
            Capability::MANAGE_SHARES,
            'become_root',
        ]);

        $roles = array_column($this->permissions->roles(), 'capabilities', 'slug');

        self::assertSame([Capability::MANAGE_SHARES], $roles['viewer']);
    }

    // ----------------------------------------------------------------- groups

    public function testAGroupGrantsItsCapabilitiesToItsMembers(): void
    {
        $group = $this->permissions->createGroup('Sermon editors');
        $this->permissions->setGroupCapabilities($group, [Capability::MANAGE_VIDEOS]);
        $this->permissions->addGroupMember($group, 'member@example.com');

        $user = $this->user('member@example.com', $this->roleId('viewer'));

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS));
    }

    /**
     * Membership is by address so permissions can be prepared for somebody who
     * has never signed in — the whole reason group_members keys on email.
     */
    public function testSomeoneCanBeAddedBeforeTheyHaveAnAccount(): void
    {
        $group = $this->permissions->createGroup('Future staff');
        $this->permissions->setGroupCapabilities($group, [Capability::MANAGE_VIDEOS]);
        $this->permissions->addGroupMember($group, 'newcomer@example.com');

        // The account arrives afterwards, as it would on first sign-in.
        $user = $this->user('newcomer@example.com', $this->roleId('viewer'));

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS));
    }

    public function testRemovingSomeoneFromAGroupRemovesWhatItGranted(): void
    {
        $group = $this->permissions->createGroup('Temporary');
        $this->permissions->setGroupCapabilities($group, [Capability::MANAGE_VIDEOS]);
        $this->permissions->addGroupMember($group, 'temp@example.com');

        $user = $this->user('temp@example.com', $this->roleId('viewer'));
        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS));

        $this->permissions->removeGroupMember($group, 'temp@example.com');
        $this->capabilities->flush();

        self::assertFalse($this->capabilities->can($user, Capability::MANAGE_VIDEOS));
    }

    public function testDeletingAGroupRemovesWhatItGranted(): void
    {
        $group = $this->permissions->createGroup('Doomed');
        $this->permissions->setGroupCapabilities($group, [Capability::MANAGE_VIDEOS]);
        $this->permissions->addGroupMember($group, 'doomed@example.com');

        $user = $this->user('doomed@example.com', $this->roleId('viewer'));

        $this->permissions->deleteGroup($group);
        $this->capabilities->flush();

        self::assertFalse($this->capabilities->can($user, Capability::MANAGE_VIDEOS));
    }

    public function testAddressesAreNormalisedSoTheSamePersonIsNotAddedTwice(): void
    {
        $group = $this->permissions->createGroup('Normalised');
        $this->permissions->addGroupMember($group, 'Person@Example.COM');
        $this->permissions->addGroupMember($group, '  person@example.com ');

        $groups = array_column($this->permissions->groups(), 'members', 'name');

        self::assertSame(['person@example.com'], $groups['Normalised']);
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        $group = $this->permissions->createGroup('Picky');

        $this->expectExceptionMessage('not a valid email address');
        $this->permissions->addGroupMember($group, 'not-an-address');
    }

    // ----------------------------------------------------------------- grants

    public function testAScopedGrantAppliesOnlyInsideItsScope(): void
    {
        $sermons = $this->category('Sermons');
        $classes = $this->category('Classes');

        $user = $this->user('editor@example.com', $this->roleId('viewer'));

        $this->permissions->grant('user', (string) $user->id, Capability::MANAGE_VIDEOS, 'category', $sermons);
        $this->capabilities->flush();

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $sermons));
        self::assertFalse($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $classes));
        self::assertFalse($this->capabilities->can($user, Capability::MANAGE_VIDEOS));
    }

    public function testAGrantByEmailWorksBeforeTheAccountExists(): void
    {
        $sermons = $this->category('Sermons');

        $this->permissions->grant('email', 'later@example.com', Capability::MANAGE_VIDEOS, 'category', $sermons);

        $user = $this->user('later@example.com', $this->roleId('viewer'));

        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $sermons));
    }

    /**
     * The rule that keeps the stored row honest: a site-only capability is
     * always stored site-wide, because that is how it will be CHECKED. Storing
     * the requested scope would record a containment that does not exist, and
     * the admin would read a limit that was never applied.
     */
    public function testASiteOnlyCapabilityIsStoredSiteWideWhateverWasAsked(): void
    {
        $sermons = $this->category('Sermons');
        $user = $this->user('plugins@example.com', $this->roleId('viewer'));

        $this->permissions->grant('user', (string) $user->id, Capability::MANAGE_PLUGINS, 'category', $sermons);

        $grants = $this->permissions->grants();

        self::assertCount(1, $grants);
        self::assertSame('the whole site', $grants[0]['scope'], 'A site-only capability must not appear scoped.');

        $this->capabilities->flush();
        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_PLUGINS));
    }

    public function testAScopedGrantWithNothingToAttachToIsRefused(): void
    {
        $user = $this->user('nowhere@example.com', $this->roleId('viewer'));

        $this->expectExceptionMessage('Choose something to attach this permission to.');
        $this->permissions->grant('user', (string) $user->id, Capability::MANAGE_VIDEOS, 'category', 0);
    }

    public function testGrantingTwiceDoesNotCreateTwoRows(): void
    {
        $user = $this->user('twice@example.com', $this->roleId('viewer'));

        $this->permissions->grant('user', (string) $user->id, Capability::MANAGE_VIDEOS);
        $this->permissions->grant('user', (string) $user->id, Capability::MANAGE_VIDEOS);

        self::assertCount(1, $this->permissions->grants());
    }

    public function testRevokingRemovesTheAbility(): void
    {
        $sermons = $this->category('Sermons');
        $user = $this->user('revoked@example.com', $this->roleId('viewer'));

        $this->permissions->grant('user', (string) $user->id, Capability::MANAGE_VIDEOS, 'category', $sermons);
        $this->capabilities->flush();
        self::assertTrue($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $sermons));

        $this->permissions->revoke($this->permissions->grants()[0]['id']);
        $this->capabilities->flush();

        self::assertFalse($this->capabilities->can($user, Capability::MANAGE_VIDEOS, 'category', $sermons));
    }

    /** A list nobody can read is a list nobody audits. */
    public function testGrantsAreDescribedInWordsNotIds(): void
    {
        $sermons = $this->category('Sermons');
        $user = $this->user('described@example.com', $this->roleId('viewer'));

        $this->permissions->grant('user', (string) $user->id, Capability::MANAGE_VIDEOS, 'category', $sermons);

        $grant = $this->permissions->grants()[0];

        self::assertSame('described@example.com', $grant['subject']);
        self::assertSame('Category: Sermons', $grant['scope']);
    }

    // --------------------------------------------------------------- fixtures

    private function roleId(string $slug): int
    {
        return (int) $this->db()->value('SELECT id FROM {roles} WHERE slug = ?', [$slug]);
    }

    private function user(string $email, int $roleId): User
    {
        $id = (int) $this->db()->insert('users', [
            'email'      => $email,
            'role_id'    => $roleId,
            'authorized' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $row = $this->db()->first(
            'SELECT u.*, r.slug AS role_slug FROM {users} u
               LEFT JOIN {roles} r ON r.id = u.role_id WHERE u.id = ?',
            [$id]
        );

        self::assertNotNull($row);

        return User::fromRow($row);
    }

    private function category(string $name): int
    {
        $id = (int) $this->db()->insert('categories', [
            'slug'       => strtolower($name) . '-' . bin2hex(random_bytes(3)),
            'name'       => $name,
            'path'       => '/',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->db()->execute('UPDATE {categories} SET path = ? WHERE id = ?', ['/' . $id . '/', $id]);

        return $id;
    }
}
