<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\AccessAttempts;
use Portal\Auth\Capabilities;
use Portal\Auth\ClaimGate;
use Portal\Auth\Guard;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\Session;
use Portal\Auth\SignInAllowlist;
use Portal\Auth\UserRepository;
use Portal\Config;
use Portal\Container;
use Portal\Http\Request;

/**
 * The four ways the two sign-in checks combine.
 *
 * Against a real database, deliberately. The allowlist half is a query and the
 * membership half is a column written at sign-in, so a mocked version of this
 * would be asserting the arrangement I already believe rather than the one that
 * runs. Every genuine bug in this area — the container that was not wired, the
 * catch that swallowed it — was found this way and would have been invisible to
 * a double.
 *
 * The subject is always a non-admin with no local password, because both of
 * those are exempt from these gates by design and would make every case pass.
 */
final class SignInModeTest extends DatabaseTestCase
{
    private UserRepository $users;
    private SignInAllowlist $allowlist;

    protected function setUp(): void
    {
        $this->truncate([
            'grants', 'group_members', 'group_capabilities', 'permission_groups',
            'role_capabilities', 'capabilities', 'roles', 'users', 'sessions',
            'settings', 'signin_allowlist', 'access_attempts',
        ]);

        (new PermissionSeeder($this->db()))->seed();

        $this->users = new UserRepository($this->db());
        $this->allowlist = new SignInAllowlist($this->db());

        Container::instance()->set(Config::class, new Config());
    }

    // --------------------------------------------------------- the matrix

    /**
     * The whole matrix, with BOTH gates configured.
     *
     * Four modes across four combinations of (member, listed). This is the
     * table the spec draws, checked against the code that runs.
     */
    public function testTheModeMatrixWithBothChecksConfigured(): void
    {
        $expected = [
            // mode                  member+listed, member only, listed only, neither
            ClaimGate::BOTH         => [true,  false, false, false],
            ClaimGate::ORGANIZATION => [true,  true,  false, false],
            ClaimGate::ALLOWLIST    => [true,  false, true,  false],
            ClaimGate::EITHER       => [true,  true,  true,  false],
        ];

        foreach ($expected as $mode => $results) {
            $cases = [
                [true, true, $results[0]],
                [true, false, $results[1]],
                [false, true, $results[2]],
                [false, false, $results[3]],
            ];

            foreach ($cases as [$member, $listed, $allowed]) {
                $this->configure($mode, claim: true, list: true);

                $email = 'p' . bin2hex(random_bytes(4)) . '@example.test';
                $user = $this->person($email, $member ? 'org_a' : 'org_z');

                if ($listed) {
                    $this->allowlist->add($email);
                }

                self::assertSame(
                    $allowed,
                    $this->letIn($user->id),
                    sprintf(
                        '%s: member=%s listed=%s',
                        $mode,
                        $member ? 'yes' : 'no',
                        $listed ? 'yes' : 'no'
                    )
                );
            }
        }
    }

    // ------------------------------------------- configured vs counted

    /**
     * THE RULE THAT KEEPS A FRESH INSTALL USABLE.
     *
     * A mode may count a check the site has not configured. That check has
     * nothing to check against, so it is skipped rather than failed — otherwise
     * choosing "require both" on a site with no organisation set up refuses
     * every visitor, from a screen only an administrator can reach, on hosting
     * with no shell.
     */
    public function testAModeCannotFailACheckTheSiteHasNotConfigured(): void
    {
        $this->configure(ClaimGate::BOTH, claim: false, list: true);

        $email = 'listed@example.test';
        $user = $this->person($email, null);
        $this->allowlist->add($email);

        self::assertTrue(
            $this->letIn($user->id),
            'BOTH refused somebody on the list because no organisation was configured'
        );
    }

    /** And with nothing configured at all, nobody is refused, whatever the mode. */
    public function testWithNoGateConfiguredNobodyIsRefused(): void
    {
        foreach ([ClaimGate::BOTH, ClaimGate::ORGANIZATION, ClaimGate::ALLOWLIST, ClaimGate::EITHER] as $mode) {
            $this->configure($mode, claim: false, list: false);

            $user = $this->person('nobody' . bin2hex(random_bytes(3)) . '@example.test', null);

            self::assertTrue($this->letIn($user->id), "{$mode} refused somebody on an unconfigured site");
        }
    }

    /**
     * EITHER only means "either" when both checks are actually being consulted.
     *
     * An unconfigured gate produces no refusal, which is indistinguishable from
     * one that let somebody through — so treating it as a passing half would
     * wave everybody past the gate that IS switched on.
     */
    public function testEitherDoesNotWavePeoplePastTheOnlyConfiguredCheck(): void
    {
        $this->configure(ClaimGate::EITHER, claim: false, list: true);

        $user = $this->person('stranger@example.test', null);

        self::assertFalse(
            $this->letIn($user->id),
            'EITHER let somebody in on the strength of a check that was never configured'
        );
    }

    // ------------------------------------------------------------ typos

    /** An unrecognised mode is the strictest one, not the loosest. */
    public function testAnUnrecognisedModeIsTheStrictestOne(): void
    {
        $this->configure('nonsense', claim: true, list: true);

        $email = 'member-only@example.test';
        $user = $this->person($email, 'org_a');   // a member, but not listed

        self::assertFalse(
            $this->letIn($user->id),
            'a typo resolved to something looser than BOTH'
        );
    }

    // ------------------------------------------------------- exemptions

    /**
     * Administrators and local-password accounts are never refused by these
     * gates. This is the recovery path on hosting with no shell, and removing
     * it fails here.
     */
    public function testTheExemptionsSurviveTheStrictestMode(): void
    {
        $this->configure(ClaimGate::BOTH, claim: true, list: true);

        $admin = $this->person('admin@example.test', 'org_z', role: 'admin');
        self::assertTrue($this->letIn($admin->id), 'the strictest mode locked out an administrator');

        $local = $this->person('local@example.test', 'org_z', password: 'a-long-enough-password-1234');
        self::assertTrue($this->letIn($local->id), 'the strictest mode closed the break-glass path');
    }

    // ------------------------------------------------------------- guests

    /**
     * A guest exemption waives the organisation check.
     *
     * The case it exists for: somebody who legitimately has no account in the
     * organisation, where the alternatives are adding them to somebody else's
     * identity system or loosening the whole site to admit one person.
     */
    public function testAGuestExemptionWaivesTheOrganisationCheck(): void
    {
        $this->configure(ClaimGate::ORGANIZATION, claim: true, list: false);
        $this->setting('signin_guests_enabled', '1');

        $email = 'visiting-speaker@example.test';
        $user = $this->person($email, 'org_z');   // in the wrong organisation

        self::assertFalse($this->letIn($user->id), 'the fixture was not refused, so this proves nothing');

        (new \Portal\Auth\GuestExemptions($this->db()))->add($email, 'Visiting speaker');

        self::assertTrue($this->letIn($user->id), 'the exemption did not waive the organisation check');
    }

    /**
     * AND NOTHING ELSE. This is the whole reason the waiver is applied by
     * switching one check off rather than by short-circuiting the method.
     *
     * A guest under BOTH still has to be on the address list. An exemption that
     * skipped that too would be an admin backdoor wearing the word "guest", and
     * from the screen that grants one the two look identical.
     */
    public function testAGuestExemptionDoesNotWaiveTheAddressList(): void
    {
        $this->configure(ClaimGate::BOTH, claim: true, list: true);
        $this->setting('signin_guests_enabled', '1');

        $email = 'guest-not-listed@example.test';
        $user = $this->person($email, 'org_z');
        (new \Portal\Auth\GuestExemptions($this->db()))->add($email);

        self::assertFalse(
            $this->letIn($user->id),
            'the exemption excused the address list as well as the organisation'
        );

        // And with the list satisfied too, they are in — so the refusal above
        // was the list and not the exemption failing to work at all.
        $this->allowlist->add($email);
        self::assertTrue($this->letIn($user->id));
    }

    /** Nor the approval flag, which is a different decision again. */
    public function testAGuestExemptionDoesNotWaiveApproval(): void
    {
        $this->configure(ClaimGate::ORGANIZATION, claim: true, list: false);
        $this->setting('signin_guests_enabled', '1');

        $email = 'guest-unapproved@example.test';
        $user = $this->person($email, 'org_z', authorized: false);
        (new \Portal\Auth\GuestExemptions($this->db()))->add($email);

        self::assertFalse(
            $this->letIn($user->id),
            'the exemption let through somebody no administrator had approved'
        );
    }

    /**
     * Switched off is a complete answer, whatever rows exist.
     *
     * Otherwise turning the feature off would require also emptying the list to
     * be sure — and "off but still has rows" is exactly the state a site is in
     * while somebody is deciding whether to use it.
     */
    public function testExemptionsDoNothingWhileTheFeatureIsOff(): void
    {
        $this->configure(ClaimGate::ORGANIZATION, claim: true, list: false);
        $this->setting('signin_guests_enabled', '0');

        $email = 'guest-while-off@example.test';
        $user = $this->person($email, 'org_z');
        (new \Portal\Auth\GuestExemptions($this->db()))->add($email);

        self::assertFalse($this->letIn($user->id), 'a row excused somebody while the feature was off');
    }

    // ---------------------------------------------------------- recording

    /** A refusal is recorded, since the person has no other way to be seen. */
    public function testARefusalIsRecorded(): void
    {
        $this->configure(ClaimGate::BOTH, claim: true, list: true);

        $user = $this->person('refused@example.test', 'org_z');
        $this->letIn($user->id);

        self::assertSame(
            1,
            (int) $this->db()->value(
                'SELECT COUNT(*) FROM {access_attempts} WHERE email = ?',
                ['refused@example.test']
            )
        );
    }

    /**
     * NO SESSION SURVIVES A REFUSAL.
     *
     * The refusal has to be a state change rather than a page. Left live, the
     * cookie still names a valid row — so this gate refuses on every request
     * while any route gated on `requireUser` alone would let the same person
     * straight through.
     *
     * Asserted on the {sessions} table rather than on the response, because
     * the row is the thing that grants access; the cleared cookie is how the
     * browser finds out, and Session::commit() attaches that on the way out.
     */
    public function testARefusalEndsTheSession(): void
    {
        $this->configure(ClaimGate::BOTH, claim: true, list: true);

        $user = $this->person('turned-away@example.test', 'org_z');

        self::assertFalse($this->letIn($user->id), 'the fixture was not refused, so this proves nothing');

        self::assertNull(
            $this->lastSession?->userId(),
            'the refused person kept a live session'
        );
    }

    /** And somebody who passes keeps theirs, or the check above is vacuous. */
    public function testSomebodyWhoPassesKeepsTheirSession(): void
    {
        $this->configure(ClaimGate::BOTH, claim: true, list: true);

        $email = 'welcome@example.test';
        $user = $this->person($email, 'org_a');
        $this->allowlist->add($email);

        self::assertTrue($this->letIn($user->id));

        self::assertSame(
            $user->id,
            $this->lastSession?->userId(),
            'ending every session would satisfy the refusal check and break the site'
        );
    }

    // ---------------------------------------------------------- fixture

    /**
     * The session the last letIn() ran against.
     *
     * Kept so a test can ask what the guard did to it. The {sessions} TABLE is
     * no use for that here: a row is only written by Session::commit(), which
     * the kernel calls at the end of a real request and nothing calls in a
     * test — so counting rows was 0 whatever happened, and the first version of
     * the refusal check passed without proving anything at all. The paired
     * check on the other direction is what exposed it.
     */
    private ?Session $lastSession = null;

    /** Does the guard let this person past? */
    private function letIn(int $userId): bool
    {
        (new Session($this->db()))->logout();

        $session = new Session($this->db());
        $session->login($userId);
        $this->lastSession = $session;

        $guard = new Guard(
            $session,
            $this->users,
            new Capabilities($this->db()),
            new \Portal\Auth\LocalProvider([], $this->db()),
            new Config(),
            new SignInAllowlist($this->db()),
            new AccessAttempts($this->db()),
            new \Portal\Auth\GuestExemptions($this->db()),
        );

        return ($guard->requireAuthorized())(Request::capture()) === null;
    }

    private function configure(string $mode, bool $claim, bool $list): void
    {
        $this->setting('signin_mode', $mode);
        $this->setting('signin_claim_name', $claim ? 'org_id' : '');
        $this->setting('signin_claim_values', $claim ? 'org_a' : '');
        $this->setting('signin_allowlist_enabled', $list ? '1' : '0');

        // Config caches settings for the life of the instance, and letIn()
        // builds a fresh one — but the container holds one too, and the guard's
        // exemption path reads through it.
        Container::instance()->set(Config::class, new Config());
    }

    private function setting(string $key, string $value): void
    {
        $this->db()->execute(
            'INSERT INTO {settings} (`key`, `value`, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
            [$key, $value]
        );
    }

    private function person(
        string $email,
        ?string $claim,
        string $role = 'viewer',
        ?string $password = null,
        bool $authorized = true
    ): \Portal\Auth\User {
        $user = $this->users->create(
            email: $email,
            name: 'Test Person',
            roleSlug: $role,
            password: $password,
            authorized: $authorized,
        );

        $this->db()->update('users', ['auth_claim' => $claim], ['id' => $user->id]);

        return $this->users->find($user->id) ?? $user;
    }
}
