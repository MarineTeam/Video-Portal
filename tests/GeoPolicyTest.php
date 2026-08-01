<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\Plugins\Geo\GeoPolicy;

require_once PORTAL_PLUGINS . '/geo/src/GeoPolicy.php';

/**
 * Country restrictions, and specifically all the ways they must NOT fire.
 *
 * Most of this file asserts that a request is allowed. That is the right
 * balance: every wrong block here is a person unable to reach a site, and one
 * particular wrong block — the admin area — is the site's owner locked out of
 * the only screen that could undo it, on a host with no shell.
 */
final class GeoPolicyTest extends TestCase
{
    // ----------------------------------------------------- the fail-open rules

    public function testAnEmptyListMeansNoRestriction(): void
    {
        self::assertSame(GeoPolicy::ALLOW, $this->decide('/', 'RU', null, [
            'viewersEnabled' => true,
            'viewerCountries' => [],
        ]));
    }

    /**
     * Most shared hosts send no country header at all. Reading "unknown" as
     * "not in the list" would block one hundred percent of traffic on a typical
     * install the moment the switch was flipped.
     */
    public function testAnUnknownCountryIsAllowed(): void
    {
        self::assertSame(GeoPolicy::ALLOW, $this->decide('/', '', null, [
            'viewersEnabled' => true,
            'viewerCountries' => ['US'],
        ]));
    }

    public function testTheSwitchBeingOffAllowsEverything(): void
    {
        self::assertSame(GeoPolicy::ALLOW, $this->decide('/', 'RU', null, [
            'viewersEnabled' => false,
            'viewerCountries' => ['US'],
        ]));
    }

    // ------------------------------------------------------------ blocking

    public function testAVisitorOutsideTheListIsBlocked(): void
    {
        self::assertSame(GeoPolicy::BLOCK, $this->decide('/', 'RU', null, [
            'viewersEnabled' => true,
            'viewerCountries' => ['US', 'CA'],
        ]));
    }

    public function testAVisitorInsideTheListIsAllowed(): void
    {
        self::assertSame(GeoPolicy::ALLOW, $this->decide('/', 'CA', null, [
            'viewersEnabled' => true,
            'viewerCountries' => ['US', 'CA'],
        ]));
    }

    public function testShareLinksAreCoveredByTheViewerList(): void
    {
        self::assertSame(GeoPolicy::BLOCK, $this->decide('/s/abcdefghijklmnop', 'RU', null, [
            'viewersEnabled' => true,
            'viewerCountries' => ['US'],
        ]));
    }

    // -------------------------------------------------------- the admin area

    /**
     * The single most important property in this file. An admin who restricts
     * the public site to one country must still be able to reach the screen
     * that turns it off — the two lists are separate controls, and the viewer
     * one never governs the admin area.
     */
    public function testRestrictingViewersNeverBlocksTheAdminArea(): void
    {
        self::assertSame(GeoPolicy::ALLOW, $this->decide('/admin', 'RU', null, [
            'viewersEnabled'  => true,
            'viewerCountries' => ['US'],
            'adminEnabled'    => false,
        ]));
    }

    public function testTheAdminAreaUsesItsOwnList(): void
    {
        self::assertSame(GeoPolicy::BLOCK_ADMIN, $this->decide('/admin/users', 'RU', null, [
            'adminEnabled'   => true,
            'adminCountries' => ['US'],
        ]));

        self::assertSame(GeoPolicy::ALLOW, $this->decide('/admin/users', 'US', null, [
            'adminEnabled'   => true,
            'adminCountries' => ['US'],
        ]));
    }

    /**
     * Matched as a whole segment. A plain prefix test would let a future
     * /administrators page be governed by the admin list by accident.
     */
    public function testAdminMatchingIsSegmentAware(): void
    {
        self::assertTrue(GeoPolicy::isAdminPath('/admin'));
        self::assertTrue(GeoPolicy::isAdminPath('/admin/videos'));
        self::assertFalse(GeoPolicy::isAdminPath('/administrators'));
        self::assertFalse(GeoPolicy::isAdminPath('/adminsomething'));
    }

    // ------------------------------------------------------------- bypasses

    public function testABypassAddressIsAllowedIntoTheAdminArea(): void
    {
        self::assertSame(GeoPolicy::ALLOW, $this->decide('/admin', 'RU', 'owner@example.com', [
            'adminEnabled'   => true,
            'adminCountries' => ['US'],
            'bypassEmails'   => ['owner@example.com'],
        ]));
    }

    /**
     * Bypass applies everywhere, not just to the admin area. Otherwise the
     * owner gets a site they can administer but cannot look at, which reads as
     * a bug rather than a policy.
     */
    public function testABypassAddressIsAlsoAllowedOnThePublicSite(): void
    {
        self::assertSame(GeoPolicy::ALLOW, $this->decide('/', 'RU', 'owner@example.com', [
            'viewersEnabled'  => true,
            'viewerCountries' => ['US'],
            'bypassEmails'    => ['owner@example.com'],
        ]));
    }

    public function testBypassMatchingIgnoresCaseAndSupportsWholeDomains(): void
    {
        self::assertTrue(GeoPolicy::isBypassed('Owner@Example.com', ['OWNER@EXAMPLE.COM']));
        self::assertTrue(GeoPolicy::isBypassed('anyone@example.com', ['@example.com']));
        self::assertFalse(GeoPolicy::isBypassed('anyone@notexample.com', ['@example.com']));
        self::assertFalse(GeoPolicy::isBypassed(null, ['@example.com']));
    }

    // ---------------------------------------------------------- exempt paths

    /**
     * Sign-in must stay reachable from anywhere. Being on the bypass list is a
     * property of an email address, and we only learn an address once somebody
     * has signed in — so blocking sign-in makes the bypass list useless to
     * exactly the people it exists for.
     */
    public function testSignInIsNeverBlocked(): void
    {
        self::assertSame(GeoPolicy::ALLOW, $this->decide('/auth/login', 'RU', null, [
            'viewersEnabled'  => true,
            'viewerCountries' => ['US'],
            'adminEnabled'    => true,
            'adminCountries'  => ['US'],
        ]));

        self::assertSame(GeoPolicy::ALLOW, $this->decide('/auth/callback', 'RU', null, [
            'viewersEnabled'  => true,
            'viewerCountries' => ['US'],
        ]));
    }

    public function testCronAndAssetsAreNeverBlocked(): void
    {
        foreach (['/cron', '/assets/app.css', '/theme-asset/default/x.css', '/plugin-asset/geo/x.js'] as $path) {
            self::assertSame(
                GeoPolicy::ALLOW,
                $this->decide($path, 'RU', null, ['viewersEnabled' => true, 'viewerCountries' => ['US']]),
                "{$path} must never be geo-blocked."
            );
        }
    }

    /** The exemption is by segment too, so /authors is an ordinary page. */
    public function testExemptPathMatchingIsSegmentAware(): void
    {
        self::assertTrue(GeoPolicy::isExemptPath('/auth'));
        self::assertTrue(GeoPolicy::isExemptPath('/auth/login'));
        self::assertFalse(GeoPolicy::isExemptPath('/authors'));
        self::assertFalse(GeoPolicy::isExemptPath('/cronjobs'));
    }

    // ------------------------------------------------------ the cheap gate

    /**
     * couldBlock() exists so the middleware can skip a user lookup on the
     * overwhelming majority of requests. If it ever says "no" where decide()
     * would have blocked, requests get through that should not have — so the
     * two are checked against each other rather than independently.
     */
    public function testCouldBlockIsNeverMoreLenientThanTheRealDecision(): void
    {
        $rules = [
            'viewersEnabled'  => true,
            'adminEnabled'    => true,
            'viewerCountries' => ['US'],
            'adminCountries'  => ['CA'],
            'bypassEmails'    => [],
        ];

        foreach (['/', '/s/abc', '/admin', '/admin/users', '/auth/login', '/cron', '/watch/x'] as $path) {
            foreach (['', 'US', 'CA', 'RU'] as $country) {
                $blocked = GeoPolicy::decide($path, $country, null, $rules) !== GeoPolicy::ALLOW;

                if ($blocked) {
                    self::assertTrue(
                        GeoPolicy::couldBlock($path, $country, $rules),
                        "couldBlock said no for {$path} from '{$country}', but decide() blocked it."
                    );
                }
            }
        }
    }

    public function testCouldBlockSkipsTheLookupWhenNothingIsEnabled(): void
    {
        self::assertFalse(GeoPolicy::couldBlock('/', 'RU', $this->rules([])));
        self::assertFalse(GeoPolicy::couldBlock('/', '', $this->rules(['viewersEnabled' => true, 'viewerCountries' => ['US']])));
    }

    // --------------------------------------------------------------- fixtures

    /** @param array<string, mixed> $overrides */
    private function decide(string $path, string $country, ?string $email, array $overrides): string
    {
        return GeoPolicy::decide($path, $country, $email, $this->rules($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{viewersEnabled: bool, adminEnabled: bool, viewerCountries: list<string>, adminCountries: list<string>, bypassEmails: list<string>}
     */
    private function rules(array $overrides): array
    {
        /** @var array{viewersEnabled: bool, adminEnabled: bool, viewerCountries: list<string>, adminCountries: list<string>, bypassEmails: list<string>} */
        return $overrides + [
            'viewersEnabled'  => false,
            'adminEnabled'    => false,
            'viewerCountries' => [],
            'adminCountries'  => [],
            'bypassEmails'    => [],
        ];
    }
}
