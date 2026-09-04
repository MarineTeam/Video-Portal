<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\Capabilities;
use Portal\Auth\Guard;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\Session;
use Portal\Auth\UserRepository;
use Portal\Config;
use Portal\Container;
use Portal\Http\Request;

/**
 * Requiring a confirmed email address.
 *
 * The oldest open item in this project: all three predecessor apps recorded
 * "email_verified is not enforced" as a known gap, and this codebase carried
 * the claim from Phase 1 without acting on it.
 *
 * Acting on it has real lockout risk, so it is a switch rather than a rule —
 * and the two exemptions are what make it a switch rather than a trap. Most of
 * this file is about those, because a mistake there does not produce a failing
 * test on somebody's laptop, it produces a site nobody can get into.
 */
final class VerifiedEmailTest extends DatabaseTestCase
{
    private Guard $guard;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->truncate([
            'grants', 'group_members', 'group_capabilities', 'permission_groups',
            'role_capabilities', 'capabilities', 'roles', 'users', 'sessions', 'settings',
        ]);

        (new PermissionSeeder($this->db()))->seed();

        $this->users = new UserRepository($this->db());

        $session = new Session($this->db());

        $this->guard = new Guard(
            $session,
            $this->users,
            new Capabilities($this->db()),
            new \Portal\Auth\LocalProvider([], $this->db()),
            new Config(),
            new \Portal\Auth\SignInAllowlist($this->db()),
            new \Portal\Auth\AccessAttempts($this->db()),
            new \Portal\Auth\GuestExemptions($this->db()),
        );

        // Guard reads the setting through the container, because it is built in
        // several places and a constructor argument would touch all of them.
        Container::instance()->set(Config::class, new Config());
    }

    protected function tearDown(): void
    {
        $this->setting('0');
    }

    // ------------------------------------------------------------- switched off

    /** Explicitly off. */
    public function testNobodyIsBlockedWhileTheSettingIsOff(): void
    {
        $this->setting('0');

        self::assertNull($this->block($this->viewer(verified: false)));
    }

    /**
     * The state every existing install is in on the upgrade that ships this:
     * no row at all. That is a different case from the row saying "0", and it
     * is the one that decides whether an upgrade quietly locks people out.
     *
     * Written because it was missing: a mutation flipping the default from
     * false to true passed the whole file, since every other test sets the
     * value explicitly and never exercises the default at all.
     */
    public function testAnUpgradeThatNobodyConfiguredBlocksNobody(): void
    {
        $this->db()->execute('DELETE FROM {settings} WHERE `key` = ?', ['require_verified_email']);

        self::assertNull(
            $this->block($this->viewer(verified: false)),
            'shipping this would have started refusing people on sites that never asked for it'
        );
    }

    // -------------------------------------------------------------- switched on

    public function testAnUnverifiedProviderAccountIsBlocked(): void
    {
        $this->setting('1');

        $page = $this->block($this->viewer(verified: false));

        self::assertNotNull($page);
        self::assertStringContainsString('Confirm your email address', $page);
    }

    public function testAVerifiedAccountIsNot(): void
    {
        $this->setting('1');

        self::assertNull($this->block($this->viewer(verified: true)));
    }

    // ------------------------------------------------------------- exemptions

    /**
     * The property that makes this safe to ship.
     *
     * The person who can switch this off must never be locked out by it —
     * otherwise turning it on with a provider that does not send the claim is
     * an unrecoverable mistake on a host with no shell. The same rule the geo
     * plugin protects: restricting the site can never, on its own, close the
     * screen that would undo it.
     */
    public function testAnAdministratorIsNeverBlocked(): void
    {
        $this->setting('1');

        $admin = $this->viewer(verified: false, role: 'admin');

        self::assertNull(
            $this->block($admin),
            'an administrator was locked out by the setting only they can turn off'
        );
    }

    /**
     * The other half of the same protection, and the one that matters on a
     * shared host.
     *
     * Nothing in this app can set email_verified on a local account — there is
     * no confirmation flow for a password somebody typed here — so requiring it
     * would shut every local account out permanently. Local sign-in is the
     * break-glass route when the identity provider is misconfigured, and this
     * must never be what closes it.
     */
    public function testAnAccountWithALocalPasswordIsNeverBlocked(): void
    {
        $this->setting('1');

        $id = $this->viewer(verified: false);
        $this->users->setPassword($id, 'a-local-password-1234');

        self::assertNull(
            $this->block($id),
            'turning this on would lock every local account out with no way back'
        );
    }

    /** And both exemptions hold together. */
    public function testAnAdministratorWithAPasswordIsAlsoFine(): void
    {
        $this->setting('1');

        $id = $this->viewer(verified: false, role: 'admin');
        $this->users->setPassword($id, 'a-local-password-1234');

        self::assertNull($this->block($id));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * What the guard would render, or null if it lets them through.
     *
     * Driven through the real middleware rather than through a predicate, so a
     * version that computed the right answer and never applied it would fail.
     */
    private function block(int $userId): ?string
    {
        (new Session($this->db()))->logout();

        $session = new Session($this->db());
        $session->login($userId);

        $guard = new Guard(
            $session,
            $this->users,
            new Capabilities($this->db()),
            new \Portal\Auth\LocalProvider([], $this->db()),
            new Config(),
            new \Portal\Auth\SignInAllowlist($this->db()),
            new \Portal\Auth\AccessAttempts($this->db()),
            new \Portal\Auth\GuestExemptions($this->db()),
        );

        $response = ($guard->requireAuthorized())(Request::capture());

        return $response === null ? null : $response->body();
    }

    private function setting(string $value): void
    {
        $this->db()->execute(
            'INSERT INTO {settings} (`key`, `value`, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
            ['require_verified_email', $value]
        );
    }

    private function viewer(bool $verified, string $role = 'viewer'): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('users', [
            'email'          => 'verify-' . bin2hex(random_bytes(4)) . '@example.com',
            'name'           => 'A viewer',
            'authorized'     => 1,
            'email_verified' => $verified ? 1 : 0,
            'role_id'        => (int) $this->db()->value('SELECT id FROM {roles} WHERE slug = ?', [$role]),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }
}
