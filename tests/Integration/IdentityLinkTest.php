<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\AuthResult;
use Portal\Auth\IdentityLink;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\UserRepository;
use RuntimeException;

/**
 * One account, reachable by several sign-in providers.
 *
 * Against a real database, because the rule being tested is an account
 * takeover: an identity attaches to an existing account only when the provider
 * says the address is verified. The previous code matched on email alone and
 * rebound the account to whoever signed in last, so anybody who could get any
 * configured provider to assert an address inherited the account holding it.
 *
 * The first test here is that attempt, written to fail against the code as it
 * was.
 */
final class IdentityLinkTest extends DatabaseTestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->truncate([
            'user_identities', 'grants', 'group_members', 'group_capabilities',
            'permission_groups', 'role_capabilities', 'capabilities', 'roles',
            'users', 'sessions',
        ]);

        (new PermissionSeeder($this->db()))->seed();
        $this->users = new UserRepository($this->db());
    }

    // ------------------------------------------------------ the takeover

    /**
     * THE RULE. An unverified identity never attaches to an existing account.
     *
     * Somebody signs in at a provider that will assert any address you type.
     * An account here already holds it. Before this, that sign-in silently
     * became that account.
     */
    public function testAnUnverifiedIdentityCannotJoinAnExistingAccount(): void
    {
        $this->users->create(
            email: 'treasurer@example.test',
            name: 'The Treasurer',
            roleSlug: 'admin',
            password: 'a-long-enough-password-1234',
            authorized: true,
        );

        $this->expectException(RuntimeException::class);

        $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'treasurer@example.test',
            subject: 'oidc|stranger',
            emailVerified: false,
        ));
    }

    /** And nothing was created on the way past — no shadow second account. */
    public function testARefusedLinkCreatesNothing(): void
    {
        $this->users->create(
            email: 'held@example.test',
            name: 'Somebody',
            roleSlug: 'viewer',
            password: null,
            authorized: true,
        );

        try {
            $this->users->findOrCreateFromAuth(AuthResult::success(
                email: 'held@example.test',
                subject: 'oidc|stranger',
                emailVerified: false,
            ));
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {users} WHERE email = ?', ['held@example.test']),
            'two accounts on one address makes "who is this person" unanswerable'
        );
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {user_identities} WHERE subject = ?', ['oidc|stranger'])
        );
    }

    /** A verified one may. That is the whole point of the distinction. */
    public function testAVerifiedIdentityJoinsTheExistingAccount(): void
    {
        $created = $this->users->create(
            email: 'shared@example.test',
            name: 'Somebody',
            roleSlug: 'viewer',
            password: null,
            authorized: true,
        );

        $user = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'shared@example.test',
            subject: 'oidc|genuine',
            emailVerified: true,
        ));

        self::assertSame($created->id, $user->id, 'a verified identity should have joined, not forked');
        self::assertCount(1, $this->users->identitiesFor($user->id));
    }

    // --------------------------------------------------- returning people

    /**
     * A returning identity is found by SUBJECT, whatever address it carries.
     *
     * People are renamed and organisations reassign addresses. Matching on the
     * address would hand somebody a stranger's account the day theirs was
     * reused — or lock them out of their own.
     */
    public function testAReturningIdentityIsFoundByItsSubjectNotItsAddress(): void
    {
        $first = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'before@example.test',
            subject: 'oidc|stable',
            emailVerified: true,
        ));

        $second = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'after-the-rename@example.test',
            subject: 'oidc|stable',
            emailVerified: true,
        ));

        self::assertSame($first->id, $second->id, 'a renamed person got a second account');
    }

    /**
     * And a known identity is not refused because its NEW address happens to
     * belong to somebody else here. They are still the same person, and
     * refusing them would lock them out for a reason they cannot see or fix.
     */
    public function testAKnownIdentityIsNotRefusedByACollidingAddress(): void
    {
        $mine = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'mine@example.test',
            subject: 'oidc|stable',
            emailVerified: true,
        ));

        $this->users->create(
            email: 'theirs@example.test',
            name: 'Somebody else',
            roleSlug: 'viewer',
            password: null,
            authorized: true,
        );

        $again = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'theirs@example.test',
            subject: 'oidc|stable',
            emailVerified: false,
        ));

        self::assertSame($mine->id, $again->id);
    }

    // --------------------------------------------------------- new people

    public function testAnUnknownAddressGetsAnUnapprovedAccount(): void
    {
        $user = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'newcomer@example.test',
            subject: 'oidc|newcomer',
            emailVerified: true,
        ));

        self::assertFalse($user->authorized, 'signing in proves who you are and grants nothing');
        self::assertCount(1, $this->users->identitiesFor($user->id));
    }

    /** An unverified newcomer is fine: nobody holds the address to take over. */
    public function testAnUnverifiedNewcomerIsNotRefused(): void
    {
        $user = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'unverified-newcomer@example.test',
            subject: 'oidc|unverified',
            emailVerified: false,
        ));

        self::assertSame('unverified-newcomer@example.test', $user->email);
    }

    // ------------------------------------------------------- the identity

    /**
     * One identity belongs to one account. The unique key is what stops the
     * state where "who is this person" has two answers.
     */
    public function testASubjectCannotBeClaimedTwice(): void
    {
        $a = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'a@example.test',
            subject: 'oidc|one',
            emailVerified: true,
        ));

        $b = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'b@example.test',
            subject: 'oidc|one',
            emailVerified: true,
        ));

        self::assertSame($a->id, $b->id);
        self::assertSame(
            1,
            (int) $this->db()->value('SELECT COUNT(*) FROM {user_identities} WHERE subject = ?', ['oidc|one'])
        );
    }

    /**
     * A verification is raised and never lowered. A provider that omits the
     * claim on one sign-in has not withdrawn one it made before, and reading
     * silence as a retraction would break that person's next link.
     */
    public function testAVerificationIsNotWithdrawnByASilentSignIn(): void
    {
        $user = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'steady@example.test',
            subject: 'oidc|steady',
            emailVerified: true,
        ));

        $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'steady@example.test',
            subject: 'oidc|steady',
            emailVerified: false,
        ));

        self::assertNotNull(
            $this->users->identitiesFor($user->id)[0]['verified_at'] ?? null,
            'one silent sign-in erased a verification the provider had made'
        );
    }

    // --------------------------------------------------------- local sign-in

    /**
     * A LOCAL PASSWORD PROVES THE ACCOUNT, and must never be refused by this.
     *
     * This is the break-glass path on a host with no shell. The first version
     * refused every existing local account on the first sign-in after the
     * upgrade — the address is "taken" by the very person typing the password,
     * and a local account has no third party to mark it verified. The smoke run
     * found it on the administrator's own sign-in, which is exactly where it
     * would have hurt most.
     */
    public function testALocalPasswordIsNeverRefusedByTheLinkRule(): void
    {
        $created = $this->users->create(
            email: 'local-admin@example.test',
            name: 'Local Admin',
            roleSlug: 'admin',
            password: 'a-long-enough-password-1234',
            authorized: true,
        );

        // Unverified, which every locally-created account is: nothing here can
        // confirm an address somebody typed into this site.
        $user = $this->users->findOrCreateFromAuth(AuthResult::success(
            email: 'local-admin@example.test',
            subject: 'local:' . $created->id,
            emailVerified: false,
        ));

        self::assertSame($created->id, $user->id, 'the local sign-in path was closed by the link rule');
    }

    // -------------------------------------------------------- the pure rule

    public function testTheDecisionTable(): void
    {
        // known, taken, verified
        self::assertSame(IdentityLink::KNOWN, IdentityLink::decide(true, true, false));
        self::assertSame(IdentityLink::KNOWN, IdentityLink::decide(true, false, false));
        self::assertSame(IdentityLink::CREATE, IdentityLink::decide(false, false, false));
        self::assertSame(IdentityLink::ATTACH, IdentityLink::decide(false, true, true));
        self::assertSame(IdentityLink::REFUSE, IdentityLink::decide(false, true, false));
    }
}
