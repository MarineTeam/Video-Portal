<?php

declare(strict_types=1);

namespace Portal\Auth;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Portal\Config;
use Portal\Http\Request;
use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Portal\Support\Crypto;
use Portal\Support\Http;
use Throwable;

/**
 * Generic OpenID Connect — Authorization Code flow with PKCE.
 *
 * Works with Google, Microsoft Entra, Okta, Keycloak, and anything else that
 * publishes a discovery document. Auth0Provider extends this with nothing more
 * than a friendlier settings form, because Auth0 *is* an OIDC provider; the
 * predecessor apps' dependency on a vendor SDK bought very little.
 *
 * Security properties this implementation actually enforces, since getting any
 * of them wrong is a real vulnerability rather than a bug:
 *
 *  - `state` is random, stored server-side in the session, and compared in
 *    constant time. Without it the callback is CSRF-able.
 *  - PKCE (S256) is always used, so an intercepted authorization code is
 *    useless without the verifier.
 *  - `nonce` is embedded in the ID token and compared, defeating token replay.
 *  - The ID token signature is verified against the provider's published JWKS.
 *    Decoding without verification would let anyone mint any identity.
 *  - `iss` and `aud` are checked, so a token minted for a different client
 *    cannot be replayed at ours.
 */
class OidcProvider implements AuthProvider
{
    /**
     * Pending sign-ins, keyed by state.
     *
     * Deliberately a map rather than a single value. Storing one state means
     * the most recent attempt silently invalidates every earlier one, and
     * there are several ordinary ways to have more than one in flight:
     * a browser prefetching the sign-in link on hover, an impatient second
     * click, two tabs, or a back-button retry. The symptom is an intermittent
     * "the sign-in session expired or did not match" that is impossible to
     * reproduce on demand, and this is what causes it.
     */
    private const SESSION_PENDING = 'oidc.pending';

    /** Enough for a prefetch plus a few retries; old entries are evicted. */
    private const MAX_PENDING = 6;

    /** A sign-in someone abandoned should not linger as a valid state forever. */
    private const PENDING_TTL = 900;

    /** @var array<string, mixed>|null Cached discovery document. */
    private ?array $discovery = null;

    /** @param array<string, string> $credentials */
    public function __construct(
        protected readonly array $credentials,
        protected readonly Config $config,
        protected readonly Session $session,
    ) {
    }

    public static function slug(): string
    {
        return 'oidc';
    }

    public static function label(): string
    {
        return 'OpenID Connect (generic)';
    }

    public static function description(): string
    {
        return 'Sign in with Google, Microsoft, Okta, Keycloak, or any OIDC-compliant provider.';
    }

    public static function requiredExtensions(): array
    {
        return ['curl', 'openssl'];
    }

    public static function fields(): array
    {
        return [
            SettingField::url(
                'issuer',
                'Issuer URL',
                'For example https://accounts.google.com. The discovery document is fetched from {issuer}/.well-known/openid-configuration.'
            ),
            SettingField::text('client_id', 'Client ID'),
            SettingField::secret('client_secret', 'Client Secret'),
            SettingField::text(
                'scopes',
                'Scopes',
                'Space-separated. openid and email are required.',
                required: false
            ),
        ];
    }

    public function isLocal(): bool
    {
        return false;
    }

    /**
     * An extra authorize parameter naming the organization, when that helps.
     *
     * Auth0 takes `organization` and renders that organization's login page
     * directly instead of a generic one. Useful, and wrong in two cases, both
     * learned the hard way in the application this is ported from:
     *
     *   - Several accepted organizations: there is no single one to send, and
     *     sending any of them chooses for the person. Withholding it makes
     *     Auth0 show its own picker, which is the correct behaviour.
     *
     *   - `either` mode: sending it makes Auth0 refuse a non-member at its own
     *     door, before this site ever gets to check its allowlist — so the
     *     personal-account route that mode exists to provide is unreachable.
     *     For that to work the Auth0 application also needs Login Experience →
     *     Type of Users set to "Both", which is what lets a personal account in
     *     alongside organization members.
     *
     * Off unless a parameter name is configured, because it is not part of
     * OIDC — a generic provider handed an unknown parameter may reject the
     * whole request.
     *
     * @return array<string, string>
     */
    private function membershipParam(): array
    {
        try {
            $param = trim((string) $this->config->setting('signin_authorize_param', ''));
            if ($param === '') {
                return [];
            }

            /*
             * A guest sign-in omits it entirely.
             *
             * Sending the organisation makes the provider refuse a non-member
             * at its own door, before this site can apply the exemption that
             * excuses them — so a guest link carrying it would be an invitation
             * that cannot be accepted. The exemption itself is still enforced
             * server-side; this only stops the provider answering first.
             */
            if ($this->session->get('signin_guest') === true) {
                return [];
            }

            $value = ClaimGate::authorizeValue(
                (string) $this->config->setting('signin_mode', ''),
                ClaimGate::parseValues((string) $this->config->setting('signin_claim_values', ''))
            );

            return $value === null ? [] : [$param => $value];
        } catch (Throwable) {
            // Unreadable settings must not stop somebody signing in. The gate
            // that reads the same settings fails to its default, which is off.
            return [];
        }
    }

    /**
     * The value of the membership claim this site asks for, if any.
     *
     * The claim NAME is a site setting rather than a provider credential,
     * because it is a policy decision — which organization, domain or tenant
     * counts — and it belongs on the same screen as the list of accepted
     * values. The provider only knows how to read it out of a verified token.
     *
     * @param array<string, mixed> $claims
     */
    private function membershipClaim(array $claims): ?string
    {
        try {
            $name = trim((string) $this->config->setting('signin_claim_name', ''));
        } catch (Throwable) {
            // Settings unreadable at sign-in. Recording nothing is right: the
            // gate that reads this fails to its default, which is off.
            return null;
        }

        if ($name === '' || !isset($claims[$name])) {
            return null;
        }

        $value = $claims[$name];

        // Scalars only. Some providers put an array here (a list of groups),
        // and flattening one into a single value would invent a membership
        // nobody asserted. Reported as absent, which is the honest answer for
        // a claim this gate cannot evaluate.
        return is_scalar($value) ? (string) $value : null;
    }

    protected function issuer(): string
    {
        return rtrim(trim($this->credentials['issuer'] ?? ''), '/');
    }

    protected function clientId(): string
    {
        return trim($this->credentials['client_id'] ?? '');
    }

    protected function clientSecret(): string
    {
        return trim($this->credentials['client_secret'] ?? '');
    }

    protected function scopes(): string
    {
        $scopes = trim($this->credentials['scopes'] ?? '');
        return $scopes !== '' ? $scopes : 'openid email profile';
    }

    /**
     * The redirect URI registered with the provider.
     *
     * Built from config, never from the request host: a mismatch here is the
     * cause of most "callback URL mismatch" errors, and deriving it from
     * HTTP_HOST would make the value change depending on how the site was
     * reached.
     */
    public function redirectUri(): string
    {
        return $this->config->url('/auth/callback');
    }

    // ------------------------------------------------------------- discovery

    /** @return array<string, mixed> */
    protected function discover(): array
    {
        if ($this->discovery !== null) {
            return $this->discovery;
        }

        $url = $this->issuer() . '/.well-known/openid-configuration';
        $response = Http::get($url, ['Accept' => 'application/json'], ['timeout' => 10]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Could not read the OpenID configuration from ' . $url . ': ' . $response->errorMessage()
            );
        }

        return $this->discovery = $response->json();
    }

    protected function endpoint(string $key): string
    {
        $value = $this->discover()[$key] ?? '';
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException("The identity provider did not publish a {$key}.");
        }
        return $value;
    }

    // ----------------------------------------------------------------- login

    public function loginUrl(string $returnTo = '/'): string
    {
        $state = Crypto::token(16);
        $nonce = Crypto::token(16);
        $verifier = Crypto::token(48);

        // Server-side only. A state kept in a cookie the client controls is
        // not a CSRF defence.
        $pending = $this->pending();

        $pending[$state] = [
            'nonce'    => $nonce,
            'verifier' => $verifier,
            'returnTo' => Request::sanitizeReturnTo($returnTo),
            'at'       => time(),
        ];

        $this->session->put(self::SESSION_PENDING, $this->prune($pending));

        $challenge = Crypto::base64url(hash('sha256', $verifier, true));

        $query = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->clientId(),
            'redirect_uri'          => $this->redirectUri(),
            'scope'                 => $this->scopes(),
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ] + $this->membershipParam());

        return $this->endpoint('authorization_endpoint') . '?' . $query;
    }

    public function logoutUrl(string $returnTo = '/'): ?string
    {
        try {
            $endpoint = $this->discover()['end_session_endpoint'] ?? null;
        } catch (Throwable) {
            return null;
        }

        if (!is_string($endpoint) || $endpoint === '') {
            return null;
        }

        return $endpoint . '?' . http_build_query([
            'client_id'                => $this->clientId(),
            'post_logout_redirect_uri' => $this->config->url(Request::sanitizeReturnTo($returnTo)),
        ]);
    }

    // -------------------------------------------------------------- callback

    public function handleCallback(Request $request): AuthResult
    {
        // The provider reports user-cancelled and misconfiguration this way.
        $providerError = $request->query('error');
        if ($providerError !== null && $providerError !== '') {
            $description = $request->query('error_description') ?? $providerError;
            return AuthResult::failure('Sign-in was not completed: ' . $description);
        }

        $state = $request->query('state') ?? '';
        $pending = $this->prune($this->pending());

        // Look the state up rather than comparing against "the" state, so a
        // prefetch or a second tab cannot invalidate a legitimate flow.
        $flow = null;
        foreach ($pending as $candidate => $details) {
            if (Crypto::verify((string) $candidate, $state)) {
                $flow = $details;
                unset($pending[$candidate]);
                break;
            }
        }

        // Consume it. A state is single-use: leaving it usable would let a
        // captured callback URL be replayed.
        $this->session->put(self::SESSION_PENDING, $pending);

        /*
         * The guest flag is consumed here too, for the same reason: it belongs
         * to one sign-in. Left set, a browser that once followed a guest link
         * would go on omitting the organisation for every later sign-in — so
         * one invitation would quietly weaken the ordinary route on that
         * machine, and nothing on any screen would say so.
         */
        $this->session->forget('signin_guest');

        if ($flow === null) {
            // Marked retryable, not failed. The overwhelmingly common cause is
            // a sign-in page that outlived the session that issued it: the
            // Auth0 URL and its state are baked into that HTML, and signing
            // out discards every pending state. A back button, a restored tab,
            // or a tab left open across a sign-out then submits a state
            // nothing remembers.
            //
            // The person did nothing wrong and has nothing to fix, so the
            // caller starts a fresh sign-in rather than showing them this.
            return AuthResult::retryable(
                'That sign-in link has already been used or has expired. Please try signing in again.'
            );
        }

        $verifier = (string) ($flow['verifier'] ?? '');
        $nonce    = (string) ($flow['nonce'] ?? '');
        $returnTo = Request::sanitizeReturnTo((string) ($flow['returnTo'] ?? '/'));

        $code = $request->query('code') ?? '';
        if ($code === '') {
            return AuthResult::failure('The identity provider did not return an authorization code.');
        }

        try {
            $tokens = $this->exchangeCode($code, $verifier);
        } catch (Throwable $e) {
            return AuthResult::failure('Could not complete sign-in: ' . $e->getMessage());
        }

        $idToken = $tokens['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            return AuthResult::failure('The identity provider did not return an ID token.');
        }

        try {
            $claims = $this->verifyIdToken($idToken, $nonce !== '' ? $nonce : null);
        } catch (Throwable $e) {
            return AuthResult::failure('The ID token could not be verified: ' . $e->getMessage());
        }

        $email = (string) ($claims['email'] ?? '');
        if ($email === '') {
            return AuthResult::failure(
                'The identity provider did not return an email address. Make sure the "email" scope is granted.'
            );
        }

        return AuthResult::success(
            email: $email,
            subject: isset($claims['sub']) ? (string) $claims['sub'] : null,
            // Absent means unverified. Assuming true when the provider is
            // silent would defeat the point of checking.
            emailVerified: ($claims['email_verified'] ?? false) === true,
            name: isset($claims['name']) ? (string) $claims['name'] : null,
            returnTo: $returnTo,
            /*
             * The one claim this site was configured to care about, taken from
             * the VERIFIED token — never from anything the browser sent. Absent
             * stays null rather than becoming an empty string, because "the
             * provider did not say" and "the provider said nothing" lead to
             * different fixes.
             */
            claim: $this->membershipClaim($claims),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function exchangeCode(string $code, string $verifier): array
    {
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri(),
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'code_verifier' => $verifier,
        ]);

        $response = Http::request(
            'POST',
            $this->endpoint('token_endpoint'),
            $body,
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            ['timeout' => 15]
        );

        if ($response->failed()) {
            throw new \RuntimeException($response->errorMessage());
        }

        return $response->json();
    }

    /**
     * Verify signature, issuer, audience, expiry, and nonce.
     *
     * @return array<string, mixed>
     */
    protected function verifyIdToken(string $idToken, ?string $expectedNonce): array
    {
        if (!class_exists(JWT::class)) {
            throw new \RuntimeException(
                'The JWT library is not installed. Reinstall from the official ZIP, which bundles it.'
            );
        }

        $jwksUri = $this->endpoint('jwks_uri');
        $jwksResponse = Http::get($jwksUri, ['Accept' => 'application/json'], ['timeout' => 10]);
        if ($jwksResponse->failed()) {
            throw new \RuntimeException('Could not fetch the signing keys from ' . $jwksUri . '.');
        }

        $keys = JWK::parseKeySet($jwksResponse->json());

        /*
         * Clock skew tolerance.
         *
         * The identity provider's clock is authoritative and ours is whatever
         * the host happens to be running. On shared hosting the admin cannot
         * run ntpd, cannot inspect the drift, and cannot do anything at all
         * about a clock that is a minute out — so a hard rejection is an
         * unfixable dead end rather than a security win.
         *
         * 120 seconds by default, raisable in config.php for a badly drifting
         * host. This widens the window on `exp` too, which is the real cost;
         * acceptable here because the ID token is consumed once at sign-in and
         * never stored, so there is nothing to replay it against later.
         */
        $leeway = $this->config->int('jwt_leeway', 120);
        JWT::$leeway = max(0, min(900, $leeway));

        try {
            $decoded = JWT::decode($idToken, $keys);
        } catch (BeforeValidException | ExpiredException $e) {
            // These two mean the signature verified and only the timing was
            // rejected, so the token itself is trustworthy enough to read for
            // diagnostics. Say by how much and in which direction, because
            // "could not be verified" sends people to check their credentials.
            throw new \RuntimeException($this->explainClockSkew($idToken, $e->getMessage()));
        }
        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($decoded) ?: '{}', true) ?: [];

        $issuer = (string) ($claims['iss'] ?? '');
        if (rtrim($issuer, '/') !== $this->issuer()) {
            throw new \RuntimeException('The token was issued by an unexpected party.');
        }

        $audience = $claims['aud'] ?? '';
        $audiences = is_array($audience) ? $audience : [$audience];
        if (!in_array($this->clientId(), array_map('strval', $audiences), true)) {
            throw new \RuntimeException('The token was not issued for this application.');
        }

        if ($expectedNonce !== null && $expectedNonce !== '') {
            $tokenNonce = (string) ($claims['nonce'] ?? '');
            if (!Crypto::verify($expectedNonce, $tokenNonce)) {
                throw new \RuntimeException('The token nonce did not match.');
            }
        }

        return $claims;
    }

    /**
     * Sign-ins currently in flight.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pending(): array
    {
        $stored = $this->session->get(self::SESSION_PENDING);

        if (!is_array($stored)) {
            return [];
        }

        $pending = [];
        foreach ($stored as $state => $details) {
            if (is_string($state) && is_array($details)) {
                $pending[$state] = $details;
            }
        }

        return $pending;
    }

    /**
     * Drop expired entries and cap the rest, oldest first.
     *
     * @param array<string, array<string, mixed>> $pending
     * @return array<string, array<string, mixed>>
     */
    private function prune(array $pending): array
    {
        $cutoff = time() - self::PENDING_TTL;

        $pending = array_filter(
            $pending,
            static fn (array $details): bool => (int) ($details['at'] ?? 0) >= $cutoff
        );

        if (count($pending) > self::MAX_PENDING) {
            uasort(
                $pending,
                static fn (array $a, array $b): int => ((int) ($a['at'] ?? 0)) <=> ((int) ($b['at'] ?? 0))
            );
            $pending = array_slice($pending, -self::MAX_PENDING, null, true);
        }

        return $pending;
    }

    /**
     * Turn a timing rejection into the actual measurement.
     *
     * Reads iat/exp straight out of the token payload without verifying —
     * safe, because this is only ever reached after the signature has already
     * been checked, and the result is used solely to write an error message.
     */
    private function explainClockSkew(string $idToken, string $original): string
    {
        $parts = explode('.', $idToken);
        $claims = [];

        if (count($parts) === 3) {
            $decoded = json_decode((string) Crypto::base64urlDecode($parts[1]), true);
            if (is_array($decoded)) {
                $claims = $decoded;
            }
        }

        $now = time();
        $issuedAt = isset($claims['iat']) ? (int) $claims['iat'] : null;

        if ($issuedAt !== null && $issuedAt > $now) {
            $skew = $issuedAt - $now;

            return sprintf(
                'This server\'s clock is %s behind the identity provider, so the sign-in token looks '
                . 'like it was issued in the future and was rejected. Ask your host to sync the server '
                . 'clock. If that is not possible, raise the tolerance by adding '
                . "\$config['jwt_leeway'] = %d; to config.php (currently %d seconds). "
                . 'Server time is %s; the token was issued at %s.',
                $this->describeDuration($skew),
                min(900, ($skew + 120)),
                JWT::$leeway,
                gmdate('Y-m-d H:i:s', $now) . ' UTC',
                gmdate('Y-m-d H:i:s', $issuedAt) . ' UTC'
            );
        }

        $expiresAt = isset($claims['exp']) ? (int) $claims['exp'] : null;

        if ($expiresAt !== null && $expiresAt < $now) {
            return sprintf(
                'The sign-in token had already expired when it arrived, by %s. This is usually a '
                . 'server clock running ahead of the identity provider. Server time is %s; the token '
                . 'expired at %s.',
                $this->describeDuration($now - $expiresAt),
                gmdate('Y-m-d H:i:s', $now) . ' UTC',
                gmdate('Y-m-d H:i:s', $expiresAt) . ' UTC'
            );
        }

        return $original;
    }

    private function describeDuration(int $seconds): string
    {
        $seconds = abs($seconds);

        if ($seconds < 120) {
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }
        if ($seconds < 7200) {
            $minutes = (int) round($seconds / 60);
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }

        $hours = (int) round($seconds / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    // ------------------------------------------------------------------ test

    public function test(): TestResult
    {
        if ($this->issuer() === '') {
            return TestResult::fail('An issuer URL is required.');
        }
        if ($this->clientId() === '' || $this->clientSecret() === '') {
            return TestResult::fail('Client ID and client secret are both required.');
        }
        if (!function_exists('curl_init')) {
            return TestResult::unavailable('The curl PHP extension is not enabled.');
        }
        if (!class_exists(JWT::class)) {
            return TestResult::unavailable('The JWT library is not installed.');
        }

        try {
            $discovery = $this->discover();
        } catch (Throwable $e) {
            return TestResult::fail(
                'Could not read the OpenID configuration. Check the issuer URL.',
                $e->getMessage()
            );
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (empty($discovery[$required])) {
                return TestResult::fail("The provider's configuration is missing {$required}.");
            }
        }

        // Guarded: during installation config.php does not exist yet and
        // baseUrl() throws rather than inventing a value from the request.
        try {
            $callback = $this->redirectUri();
        } catch (Throwable) {
            return TestResult::pass(
                'Read the OpenID configuration successfully.',
                'The site address is not set yet, so the exact callback URL cannot be shown here. '
                . 'After installing, check the Services screen for the URL to register.'
            );
        }

        return TestResult::pass(
            'Read the OpenID configuration successfully.',
            'Add this exact callback URL to the application at your identity provider: ' . $callback
        );
    }
}
