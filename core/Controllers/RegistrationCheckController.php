<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\AccessAttempts;
use Portal\Auth\SignInAllowlist;
use Portal\Auth\UserRepository;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Crypto;
use Portal\Support\RateLimit;
use Portal\Support\Str;

/**
 * The question an identity provider asks before it creates an account.
 *
 * Auth0 has a Pre-User-Registration Action that can refuse a signup. Without
 * something like this, an address nobody listed still gets an account here —
 * unapproved, able to watch nothing, but a row on the People screen all the
 * same. On a site whose sign-in page is public that list fills up with people
 * who were never going to be let in, and the ones who genuinely need approving
 * are lost among them.
 *
 * WHAT IT ANSWERS, AND WHAT IT CANNOT
 *
 * The allowlist only. At pre-registration there is no account, no session and
 * no organization claim — the provider is asking about an address and nothing
 * else exists yet. The membership gate is checked later, at every request,
 * where the claim is actually available.
 *
 * ADVISORY, NOT A BOUNDARY. Refusing here stops a row being created; it is not
 * what stops somebody watching, which is the approval flag and the gates in
 * Guard. If this endpoint is unreachable, or the Action is never installed, or
 * somebody signs in through a provider that has no such hook, nothing is
 * weakened — an unlisted account is refused on its first request exactly as
 * before. That is why it can afford to fail the way it does below.
 *
 * NOT AN ENUMERATION ORACLE. It says whether an address may register, which is
 * precisely the question the magic-link gate has refused to answer since Phase
 * 2. Three things keep it closed: it does nothing at all unless a secret has
 * been configured, the secret is compared in constant time, and it is rate
 * limited per caller. An unconfigured site answers 404 — not 503, not "not
 * configured" — so its existence is not confirmed to somebody guessing.
 */
final class RegistrationCheckController extends Controller
{
    /** Refusals recorded under their own reason, so the screen can say which. */
    public const REFUSED = 'registration_refused';

    public function check(Request $request): Response
    {
        $expected = trim((string) $this->config()->setting('signin_registration_secret', ''));

        /*
         * No secret means the feature was never switched on. 404 rather than an
         * explanation: an endpoint that says "not configured" has confirmed it
         * exists, and this one answers a question about who is expected here.
         */
        if ($expected === '') {
            return Response::text('Not found', 404);
        }

        if (!Crypto::verify($expected, $this->bearer($request))) {
            return Response::text('Not found', 404);
        }

        /*
         * Rate limited even though it is authenticated. A leaked secret should
         * not also be a way to read the whole list one address at a time, and
         * a legitimate provider makes one call per signup — nowhere near this.
         */
        $limiter = new RateLimit($this->db());
        if (!$limiter->allow('registration-check:' . $request->ip(), 60, 60)) {
            return Response::json(['allowed' => false], 429);
        }

        /*
         * data(), not input().
         *
         * input() reads form fields and the query string; the caller here is an
         * Auth0 Action posting JSON, so input() finds nothing and every request
         * takes the refusal below — which is what happened, and which reads
         * from outside as the site refusing addresses it should accept. data()
         * merges the JSON body with form fields, so this endpoint accepts
         * either without caring which.
         */
        $value = $request->data('email');
        $email = Str::normalizeEmail(is_scalar($value) ? (string) $value : '');

        if ($email === '' || !Str::isEmail($email)) {
            // Not an address, so not an address that may register. Refusing is
            // both the safe answer and the true one.
            return Response::json(['allowed' => false]);
        }

        if (!$this->config()->settingBool('signin_allowlist_enabled', false)) {
            /*
             * The gate is off, so this site is not refusing anybody by address
             * and must not start doing so here. An endpoint stricter than the
             * application it guards would refuse people the site would then
             * have admitted, and the person hits a wall at the provider with
             * nothing on this site able to explain it.
             */
            return Response::json(['allowed' => true]);
        }

        $allowed = $this->decide($email);

        if (!$allowed) {
            /*
             * Recorded, because this refusal is the most invisible one in the
             * product: it happens at the provider, no account is ever created,
             * and the person cannot reach any page here to ask. Without a row
             * the administrator has no way to learn it happened at all.
             */
            (new AccessAttempts($this->db()))
                ->record($email, self::REFUSED, 'registration', $request->ip());
        }

        return Response::json(['allowed' => $allowed]);
    }

    /**
     * May this address have an account?
     *
     * An address that already has one is allowed, whatever the list says. The
     * provider only asks this for a NEW account, so an existing one means the
     * two systems disagree about what exists — and refusing then would lock
     * somebody out of an account this site can already see, which no screen
     * here would explain. Their access is still governed by the gates on every
     * request; this decides only whether a row may be created.
     */
    private function decide(string $email): bool
    {
        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);

        if ($users->findByEmail($email) !== null) {
            return true;
        }

        $status = (new SignInAllowlist($this->db()))->statusOf($email);

        return $status === SignInAllowlist::ACTIVE;
    }

    /** The bearer token, or an empty string. */
    private function bearer(Request $request): string
    {
        $header = (string) ($request->header('authorization', '') ?? '');

        return preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1 ? trim($m[1]) : '';
    }
}
