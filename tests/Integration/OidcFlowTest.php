<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\Auth0Provider;
use Portal\Auth\OidcProvider;
use Portal\Auth\Session;
use Portal\Config;
use Portal\Http\Request;

/**
 * The OIDC sign-in handshake.
 *
 * A live install reported an intermittent "the sign-in session expired or did
 * not match". The cause was storing a single pending state: a browser
 * prefetching the sign-in link, a second tab, an impatient double click, or a
 * back-button retry each overwrote the previous one, so a legitimate callback
 * arrived carrying a state that had been silently replaced.
 *
 * These use a real Session against the database, because the whole question is
 * whether state survives across requests.
 */
final class OidcFlowTest extends DatabaseTestCase
{
    private const CREDENTIALS = [
        'domain'        => 'tenant.example.com',
        'client_id'     => 'client-abc',
        'client_secret' => 'secret-def',
    ];

    protected function setUp(): void
    {
        $this->truncate(['sessions']);
    }

    private function session(): Session
    {
        $session = new Session($this->db());
        $session->boot(new Request('GET', '/'));
        return $session;
    }

    /**
     * A provider with discovery stubbed out.
     *
     * loginUrl() and handleCallback() both resolve endpoints from the
     * provider's discovery document, so testing them for real would mean a
     * network call per assertion — slow, flaky, and dependent on someone
     * else's uptime. The stub returns the document Auth0 actually publishes,
     * which is the only part of the network these tests care about.
     */
    private function provider(Session $session): OidcProvider
    {
        $config = new Config('/nonexistent/none.php');
        $config->overlay(['base_url' => 'https://portal.example']);

        // Extends OidcProvider, not Auth0Provider — the latter is final, and
        // the handshake being tested lives in the parent anyway. Auth0's own
        // logout endpoint is covered separately using the real class, which
        // needs no discovery.
        return new class (
            [
                'issuer'        => 'https://tenant.example.com',
                'client_id'     => 'client-abc',
                'client_secret' => 'secret-def',
            ],
            $config,
            $session
        ) extends OidcProvider {
            /** @return array<string, mixed> */
            protected function discover(): array
            {
                return [
                    'issuer'                 => 'https://tenant.example.com',
                    'authorization_endpoint' => 'https://tenant.example.com/authorize',
                    'token_endpoint'         => 'https://tenant.example.com/oauth/token',
                    'jwks_uri'               => 'https://tenant.example.com/.well-known/jwks.json',
                ];
            }
        };
    }

    /**
     * The real Auth0 provider.
     *
     * Used only for logout, which must work without any discovery call at all
     * — that is precisely the fix being tested.
     */
    private function auth0(Session $session): Auth0Provider
    {
        $config = new Config('/nonexistent/none.php');
        $config->overlay(['base_url' => 'https://portal.example']);

        return new Auth0Provider(self::CREDENTIALS, $config, $session);
    }

    private function stateFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return (string) ($query['state'] ?? '');
    }

    // ------------------------------------------------------------ login URL

    public function testLoginUrlCarriesStateNonceAndPkce(): void
    {
        $provider = $this->provider($this->session());
        $url = $provider->loginUrl('/watch/something');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertNotEmpty($query['state'] ?? '');
        self::assertNotEmpty($query['nonce'] ?? '');
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertNotEmpty($query['code_challenge'] ?? '');
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('https://portal.example/auth/callback', $query['redirect_uri'] ?? null);
    }

    public function testEveryLoginUrlGetsAFreshState(): void
    {
        $provider = $this->provider($this->session());

        $first = $this->stateFrom($provider->loginUrl('/'));
        $second = $this->stateFrom($provider->loginUrl('/'));

        self::assertNotSame($first, $second);
    }

    // --------------------------------------------------- concurrent sign-ins

    /**
     * The reported bug. A prefetch or a second tab starts a second sign-in;
     * the first must still complete.
     */
    public function testAnEarlierSignInStillWorksAfterAnotherIsStarted(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);

        $first = $this->stateFrom($provider->loginUrl('/first'));
        $second = $this->stateFrom($provider->loginUrl('/second'));

        // The first callback arrives after the second was initiated.
        $result = $provider->handleCallback($this->callbackRequest($first));

        // It fails on the token exchange, since there is no real provider —
        // but crucially NOT on the state check.
        self::assertStringNotContainsString('already been used', (string) $result->error);
        self::assertStringNotContainsString('expired', (string) $result->error);

        // And the second is still usable too.
        $result = $provider->handleCallback($this->callbackRequest($second));
        self::assertStringNotContainsString('already been used', (string) $result->error);
    }

    public function testSeveralConcurrentSignInsAllRemainValid(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);

        $states = [];
        for ($i = 0; $i < 5; $i++) {
            $states[] = $this->stateFrom($provider->loginUrl("/page{$i}"));
        }

        foreach ($states as $index => $state) {
            $result = $provider->handleCallback($this->callbackRequest($state));
            self::assertStringNotContainsString(
                'already been used',
                (string) $result->error,
                "Flow {$index} should still have been valid"
            );
        }
    }

    /**
     * A state is single use. Leaving it valid would let a captured callback
     * URL be replayed.
     */
    public function testAStateCannotBeUsedTwice(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);

        $state = $this->stateFrom($provider->loginUrl('/'));

        $provider->handleCallback($this->callbackRequest($state));
        $second = $provider->handleCallback($this->callbackRequest($state));

        self::assertFalse($second->ok);
        self::assertStringContainsString('already been used', (string) $second->error);
    }

    /**
     * A state nothing remembers is retryable, not a hard failure.
     *
     * The usual cause is a sign-in page that outlived the session that issued
     * it: signing out discards every pending state, but the Auth0 URL stays
     * baked into that HTML, so a back button or a restored tab submits a state
     * from a session that no longer exists. The caller starts over rather than
     * showing an error nobody can act on.
     */
    public function testAnUnknownStateIsMarkedRetryable(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);
        $provider->loginUrl('/');

        $result = $provider->handleCallback($this->callbackRequest('a-state-nobody-issued'));

        self::assertFalse($result->ok);
        self::assertTrue($result->retryable, 'A stale sign-in page should be retried, not reported.');
    }

    /**
     * Retrying must not paper over a real refusal. If the provider itself said
     * no, starting again would just ask it to say no a second time.
     */
    public function testAProviderRefusalIsNotRetryable(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);

        $request = new Request('GET', '/auth/callback', [
            'error'             => 'access_denied',
            'error_description' => 'User did not consent',
        ]);

        $result = $provider->handleCallback($request);

        self::assertFalse($result->ok);
        self::assertFalse($result->retryable);
    }

    /**
     * The specific reported sequence: sign in, sign out, then use a sign-in
     * page rendered before the sign-out.
     */
    public function testASignInPageThatPredatesASignOutIsRetryable(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);

        // The page is rendered, baking this state into its Auth0 link.
        $staleState = $this->stateFrom($provider->loginUrl('/'));

        // Signing out discards the session, and every pending state with it.
        $session->logout();

        $freshSession = $this->session();
        $freshProvider = $this->provider($freshSession);

        $result = $freshProvider->handleCallback($this->callbackRequest($staleState));

        self::assertFalse($result->ok);
        self::assertTrue(
            $result->retryable,
            'Clicking a sign-in button rendered before signing out should quietly start over.'
        );
    }

    public function testAnUnknownStateIsRejected(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);
        $provider->loginUrl('/');

        $result = $provider->handleCallback($this->callbackRequest('a-state-nobody-issued'));

        self::assertFalse($result->ok);
        self::assertStringContainsString('already been used', (string) $result->error);
    }

    /** Old flows are evicted so the session cannot grow without bound. */
    public function testPendingSignInsAreCapped(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);

        $first = $this->stateFrom($provider->loginUrl('/oldest'));

        for ($i = 0; $i < 10; $i++) {
            $provider->loginUrl("/newer{$i}");
        }

        $result = $provider->handleCallback($this->callbackRequest($first));

        self::assertFalse($result->ok, 'The oldest flow should have been evicted.');
    }

    public function testProviderErrorIsReportedBeforeTheStateCheck(): void
    {
        $session = $this->session();
        $provider = $this->provider($session);

        $request = new Request('GET', '/auth/callback', [
            'error'             => 'access_denied',
            'error_description' => 'User did not consent',
        ]);

        $result = $provider->handleCallback($request);

        self::assertFalse($result->ok);
        self::assertStringContainsString('User did not consent', (string) $result->error);
    }

    // ---------------------------------------------------------------- logout

    /**
     * Auth0 does not publish end_session_endpoint unless OIDC logout is
     * switched on, so the generic discovery lookup found nothing and sign-out
     * left the SSO session intact.
     */
    public function testAuth0LogoutUsesItsOwnEndpointWithoutDiscovery(): void
    {
        $provider = $this->auth0($this->session());

        $url = $provider->logoutUrl('/');

        self::assertNotNull($url, 'Auth0 must always produce a logout URL.');
        self::assertStringStartsWith('https://tenant.example.com/v2/logout', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('client-abc', $query['client_id'] ?? null);
        self::assertSame('https://portal.example/', $query['returnTo'] ?? null);
    }

    public function testAuth0LogoutStillWorksWithoutABaseUrl(): void
    {
        // Real provider, no stub: logout must not need discovery at all, which
        // is the entire point of the fix.
        $config = new Config('/nonexistent/none.php');
        $provider = new Auth0Provider(self::CREDENTIALS, $config, $this->session());

        $url = $provider->logoutUrl('/');

        self::assertNotNull($url);
        self::assertStringContainsString('/v2/logout', $url);
    }

    /**
     * An open redirect on logout would let a link on another site bounce
     * someone through this domain.
     */
    public function testLogoutReturnPathCannotLeaveTheSite(): void
    {
        $provider = $this->auth0($this->session());

        $url = $provider->logoutUrl('https://evil.example/phish');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('https://portal.example/', $query['returnTo'] ?? null);
    }

    /** The generic provider has no such endpoint without discovery. */
    public function testGenericOidcLogoutIsNullWhenDiscoveryIsUnavailable(): void
    {
        $config = new Config('/nonexistent/none.php');
        $config->overlay(['base_url' => 'https://portal.example']);

        $provider = new OidcProvider(
            ['issuer' => 'https://unreachable.invalid', 'client_id' => 'x', 'client_secret' => 'y'],
            $config,
            $this->session()
        );

        self::assertNull($provider->logoutUrl('/'));
    }

    private function callbackRequest(string $state): Request
    {
        return new Request('GET', '/auth/callback', ['state' => $state, 'code' => 'some-code']);
    }
}
