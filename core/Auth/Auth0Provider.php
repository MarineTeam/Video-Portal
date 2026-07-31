<?php

declare(strict_types=1);

namespace Portal\Auth;

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
        try {
            $callback = $this->redirectUri();
        } catch (Throwable) {
            $callback = '(could not determine — set the site URL first)';
        }

        return TestResult::pass(
            'Connected to Auth0.',
            "Before signing in, add this to your Auth0 application:\n"
            . "  Allowed Callback URLs:  {$callback}\n"
            . "  Allowed Logout URLs:    " . $this->config->baseUrl() . "\n"
            . "  Allowed Web Origins:    " . $this->config->baseUrl() . "\n\n"
            . 'If sign-ups are open on this tenant, anyone can create an account. They still cannot '
            . 'watch anything until an administrator authorizes them, but consider disabling sign-ups.'
        );
    }
}
