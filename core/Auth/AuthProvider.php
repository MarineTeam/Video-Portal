<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Http\Request;
use Portal\Providers\Provider;

/**
 * Contract for proving who someone is.
 *
 * Note the deliberate boundary: an auth provider answers "who is this?" and
 * nothing else. Whether that person may watch anything is a separate decision
 * recorded in the users table and evaluated by Capabilities. Conflating the two
 * is how the predecessor apps ended up with authorization spread across an env
 * var, a Redis set, and an implicit admin rule.
 */
interface AuthProvider extends Provider
{
    /**
     * Where to send the browser to begin signing in.
     *
     * @param string $returnTo an already-validated same-origin path
     */
    public function loginUrl(string $returnTo = '/'): string;

    /**
     * Complete the sign-in from the provider's redirect back to us.
     *
     * Must return a failed AuthResult rather than throwing on a bad or
     * tampered response — a failure here is an expected condition (expired
     * state, user pressed cancel), not an exception.
     */
    public function handleCallback(Request $request): AuthResult;

    /**
     * Where to send the browser to sign out, or null if the provider has no
     * remote session to end (local accounts).
     */
    public function logoutUrl(string $returnTo = '/'): ?string;

    /**
     * True when this provider authenticates people itself rather than
     * delegating to a remote identity service. Local accounts need a password
     * form and a user-management UI; Auth0 and OIDC do not.
     */
    public function isLocal(): bool;
}
