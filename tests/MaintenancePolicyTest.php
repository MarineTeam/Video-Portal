<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Support\MaintenancePolicy;

/**
 * Who still gets through while the site is closed.
 *
 * The policy is pure, so every branch is testable directly rather than staged
 * through a request — which matters here more than usual, because the branches
 * that must never break are the ones that stop this switch becoming a one-way
 * door. Each of those has its own test below, named for the failure it prevents.
 */
final class MaintenancePolicyTest extends TestCase
{
    public function testEverythingIsAllowedWhileItIsOff(): void
    {
        self::assertTrue(MaintenancePolicy::allows('/', false, false));
        self::assertTrue(MaintenancePolicy::allows('/watch/anything', false, false));
    }

    public function testAVisitorIsStoppedWhileItIsOn(): void
    {
        self::assertFalse(MaintenancePolicy::allows('/', true, false));
        self::assertFalse(MaintenancePolicy::allows('/watch/a-sermon', true, false));
        self::assertFalse(MaintenancePolicy::allows('/category/sermons', true, false));
    }

    /**
     * An admin sees the whole site, not only the admin area.
     *
     * Letting them into /admin alone gives a site they can administer and
     * cannot look at — so they could not check whether the deploy they just
     * made actually works, which is the entire reason they are here.
     */
    public function testAnAdminSeesTheSiteAsNormal(): void
    {
        self::assertTrue(MaintenancePolicy::allows('/', true, true));
        self::assertTrue(MaintenancePolicy::allows('/watch/a-sermon', true, true));
    }

    /**
     * THE lockout guarantee.
     *
     * The rule is "admins get through", which is a fact about a session. So
     * somebody arriving from another browser, or after their session expired,
     * must be able to sign in and become one. Closing /auth turns this switch
     * into a one-way door whose only exit is FTP.
     */
    public function testSignInStaysOpenSoTheSwitchIsNeverOneWay(): void
    {
        self::assertTrue(MaintenancePolicy::allows('/auth/login', true, false));
        self::assertTrue(MaintenancePolicy::allows('/auth/callback', true, false));
        self::assertTrue(MaintenancePolicy::isAlwaysOpen('/auth/login'));
    }

    /**
     * The second guarantee, and deliberately redundant with the first.
     *
     * If the capability lookup ever fails — a database hiccup, a session that
     * will not load — the caller passes isAdmin=false, and without this an
     * administrator would meet the notice on the very screen that turns it off.
     */
    public function testTheAdminAreaIsNeverClosedEvenWhenNobodyLooksLikeAnAdmin(): void
    {
        self::assertTrue(MaintenancePolicy::allows('/admin', true, false));
        self::assertTrue(MaintenancePolicy::allows('/admin/settings', true, false));
    }

    /** A notice that cannot load its own stylesheet reads as a broken site. */
    public function testAssetsStayOpen(): void
    {
        foreach (['/assets/app.css', '/theme-asset/default/theme.css', '/plugin-asset/geo/x.js'] as $path) {
            self::assertTrue(MaintenancePolicy::allows($path, true, false), $path);
        }
    }

    /** Scheduled work must not stop for a deploy, and /cron carries a secret. */
    public function testCronStaysOpen(): void
    {
        self::assertTrue(MaintenancePolicy::allows('/cron', true, false));
    }

    /**
     * Prefix matching must not be a substring match.
     *
     * "/administrators-only" starts with "/admin" as a string but is not inside
     * the admin area, and a naive str_starts_with would leave it open to
     * everybody while the site is closed.
     */
    public function testAPathThatMerelyStartsWithAnOpenPrefixIsNotOpen(): void
    {
        self::assertFalse(MaintenancePolicy::allows('/administrators-only', true, false));
        self::assertFalse(MaintenancePolicy::allows('/authors', true, false));
        self::assertFalse(MaintenancePolicy::allows('/assetsomething', true, false));
    }

    public function testTheMessageFallsBackRatherThanRenderingBlank(): void
    {
        self::assertSame(
            'We are making a few changes and will be back shortly.',
            MaintenancePolicy::message('   ')
        );
        self::assertSame('Back at 4pm.', MaintenancePolicy::message('  Back at 4pm.  '));
    }
}
