<?php

declare(strict_types=1);

namespace Portal\Auth;

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
    private const SESSION_STATE = 'oidc.state';
    private const SESSION_VERIFIER = 'oidc.verifier';
    private const SESSION_NONCE = 'oidc.nonce';
    private const SESSION_RETURN = 'oidc.return_to';

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
        $this->session->put(self::SESSION_STATE, $state);
        $this->session->put(self::SESSION_NONCE, $nonce);
        $this->session->put(self::SESSION_VERIFIER, $verifier);
        $this->session->put(self::SESSION_RETURN, Request::sanitizeReturnTo($returnTo));

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
        ]);

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
        $expectedState = $this->session->pull(self::SESSION_STATE);
        $verifier      = $this->session->pull(self::SESSION_VERIFIER);
        $nonce         = $this->session->pull(self::SESSION_NONCE);
        $returnTo      = Request::sanitizeReturnTo((string) $this->session->pull(self::SESSION_RETURN, '/'));

        // The provider reports user-cancelled and misconfiguration this way.
        $providerError = $request->query('error');
        if ($providerError !== null && $providerError !== '') {
            $description = $request->query('error_description') ?? $providerError;
            return AuthResult::failure('Sign-in was not completed: ' . $description);
        }

        $state = $request->query('state') ?? '';
        if (!is_string($expectedState) || $expectedState === '' || !Crypto::verify($expectedState, $state)) {
            return AuthResult::failure(
                'The sign-in session expired or did not match. Please try signing in again.'
            );
        }

        $code = $request->query('code') ?? '';
        if ($code === '') {
            return AuthResult::failure('The identity provider did not return an authorization code.');
        }

        try {
            $tokens = $this->exchangeCode($code, is_string($verifier) ? $verifier : '');
        } catch (Throwable $e) {
            return AuthResult::failure('Could not complete sign-in: ' . $e->getMessage());
        }

        $idToken = $tokens['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            return AuthResult::failure('The identity provider did not return an ID token.');
        }

        try {
            $claims = $this->verifyIdToken($idToken, is_string($nonce) ? $nonce : null);
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

        // Small leeway for clock skew — shared hosts are not always in sync,
        // and a 2-second drift should not reject a valid token.
        JWT::$leeway = 60;

        $decoded = JWT::decode($idToken, $keys);
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

        return TestResult::pass(
            'Read the OpenID configuration successfully.',
            'Add this exact callback URL to the application at your identity provider: ' . $this->redirectUri()
        );
    }
}
