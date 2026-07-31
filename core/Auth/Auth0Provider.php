<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Http\Request;
use Portal\Providers\SettingField;
use Portal\Providers\TestResult;
use Throwable;

/**
 * Auth0.
 *
 * Auth0 is a standards-compliant OIDC provider, so this is OidcProvider with a
 * friendlier settings form — people have an Auth0 "domain", not an issuer URL,
 * and pasting `https://` into the domain field is a classic mistake worth
 * absorbing rather than erroring on.
 *
 * The predecessor apps depended on @auth0/nextjs-auth0. Nothing in that SDK is
 * needed here beyond what standard OIDC already gives us, and avoiding it means
 * one less vendored package to patch through app releases.
 */
final class Auth0Provider extends OidcProvider
{
    public static function slug(): string
    {
        return 'auth0';
    }

    public static function label(): string
    {
        return 'Auth0';
    }

    public static function description(): string
    {
        return 'Hosted identity with social logins, MFA, and passwordless. A generous free tier.';
    }

    public static function fields(): array
    {
        return [
            SettingField::text(
                'domain',
                'Auth0 Domain',
                'For example your-tenant.us.auth0.com. Without https:// — it is added automatically if you include it.'
            ),
            SettingField::text('client_id', 'Client ID', 'From your Auth0 application settings.'),
            SettingField::secret('client_secret', 'Client Secret', 'From your Auth0 application settings.'),
        ];
    }

    /**
     * Normalize the domain into an issuer URL.
     *
     * Auth0's own dashboard shows the domain bare, but people paste it with a
     * scheme, a trailing slash, or both. The issuer must end with a slash to
     * match the `iss` claim Auth0 actually mints — except our parent compares
     * with rtrim, so we store it without and let that comparison handle it.
     */
    protected function issuer(): string
    {
        $domain = trim($this->credentials['domain'] ?? '');
        if ($domain === '') {
            return '';
        }

        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');

        return 'https://' . $domain;
    }

    /**
     * Auth0's logout endpoint.
     *
     * Auth0 does not publish `end_session_endpoint` in its OIDC discovery
     * document unless OIDC-conformant logout is explicitly enabled on the
     * tenant, so the inherited implementation found nothing and returned null.
     * The visible effect is that "Sign out" clears our session but leaves the
     * Auth0 SSO cookie intact — clicking "Sign in" then silently re-authenticates
     * and drops the person straight back in, which reads exactly like sign-out
     * being broken.
     *
     * /v2/logout is Auth0's own endpoint and always works.
     */
    public function logoutUrl(string $returnTo = '/'): ?string
    {
        $issuer = $this->issuer();
        if ($issuer === '') {
            return null;
        }

        try {
            $return = $this->config->url(Request::sanitizeReturnTo($returnTo));
        } catch (Throwable) {
            // No base URL configured yet; let Auth0 use its own default.
            $return = null;
        }

        $query = ['client_id' => $this->clientId()];
        if ($return !== null) {
            // Must be listed under "Allowed Logout URLs" in the Auth0
            // application, or Auth0 refuses the redirect after logging out.
            $query['returnTo'] = $return;
        }

        return $issuer . '/v2/logout?' . http_build_query($query);
    }

    public function test(): TestResult
    {
        if (trim($this->credentials['domain'] ?? '') === '') {
            return TestResult::fail('An Auth0 domain is required.');
        }

        $result = parent::test();

        if (!$result->ok) {
            return $result;
        }

        // The single most common Auth0 setup failure is forgetting to register
        // the callback, which produces an opaque "Callback URL mismatch" page
        // long after the install wizard said everything was fine. Say it here.
        //
        // Both lookups are guarded: during installation config.php does not
        // exist yet, and baseUrl() throws rather than inventing a value. A
        // provider test that throws is reported as "unexpected error", which
        // tells the person nothing about what to do.
        try {
            $callback = $this->redirectUri();
            $base = $this->config->baseUrl();
        } catch (Throwable) {
            return TestResult::pass(
                'Connected to Auth0.',
                'The site address is not set yet, so the exact callback URL cannot be shown here. '
                . 'After installing, check the Services screen for the URLs to register in Auth0.'
            );
        }

        return TestResult::pass(
            'Connected to Auth0.',
            "Before signing in, add this to your Auth0 application:\n"
            . "  Allowed Callback URLs:  {$callback}\n"
            . "  Allowed Logout URLs:    {$base}\n"
            . "  Allowed Web Origins:    {$base}\n\n"
            . 'If sign-ups are open on this tenant, anyone can create an account. They still cannot '
            . 'watch anything until an administrator authorizes them, but consider disabling sign-ups.'
        );
    }
}
