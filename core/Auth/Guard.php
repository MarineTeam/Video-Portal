<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;

/**
 * The gate every protected route passes through.
 *
 * Guards return null to continue or a Response to stop. The distinction that
 * matters is between a browser and an API client: a signed-out person asking
 * for a page should be sent to sign in, while the same condition on an API
 * route must be a 401, because redirecting an fetch() to a login page produces
 * an HTML body where JSON was expected and a baffling console error.
 */
final class Guard
{
    private ?User $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly Capabilities $capabilities,
        private readonly AuthProvider $auth,
        private readonly \Portal\Config $config,
        private readonly SignInAllowlist $allowlist,
        private readonly AccessAttempts $attempts,
    ) {
    }

    /** The signed-in user, or null. Resolved once per request. */
    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $userId = $this->session->userId();
        if ($userId === null) {
            return $this->user = null;
        }

        $user = $this->users->find($userId);

        if ($user === null) {
            // The account was deleted while the session lived on. Clear it
            // rather than leaving a session pointing at nothing.
            $this->session->logout();
            return $this->user = null;
        }

        return $this->user = $user;
    }

    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }

    public function can(string $capability, ?string $scopeType = null, ?int $scopeId = null): bool
    {
        return $this->capabilities->can($this->user(), $capability, $scopeType, $scopeId);
    }

    /**
     * Does this person hold $capability site-wide, or on any single object?
     *
     * For listing screens, which have no object to ask about. Never use it to
     * authorise a change: it says a grant exists somewhere, not that it covers
     * the thing being changed.
     */
    public function canAnywhere(string $capability): bool
    {
        return $this->capabilities->canAnywhere($this->user(), $capability);
    }

    // ------------------------------------------------------------ middleware

    /**
     * Require a signed-in account.
     *
     * @return callable(Request, array<string,string>): (Response|null)
     */
    public function requireUser(): callable
    {
        return function (Request $request): ?Response {
            if ($this->isAuthenticated()) {
                return null;
            }
            return $this->challenge($request);
        };
    }

    /**
     * Require an account an administrator has authorized.
     *
     * This is the check that separates "signed in" from "may watch things",
     * and it is the one the whole approval model rests on. It fails closed.
     */
    public function requireAuthorized(): callable
    {
        return function (Request $request): ?Response {
            $user = $this->user();

            if ($user === null) {
                return $this->challenge($request);
            }

            /*
             * The allowlist gate, in front of the approval flag.
             *
             * A different question from `authorized`, and asked separately: the
             * list says which ADDRESSES may be here at all, the flag says which
             * ACCOUNTS an administrator has approved. Both must pass, and
             * neither is computed from the other — the application this is
             * ported from kept one fact in both places, with the flag
             * recomputed from the list every request, so granting access on the
             * accounts screen was silently undone on the next page load.
             *
             * Checked on every request rather than only at sign-in, which is
             * what makes removal take effect immediately instead of whenever a
             * cookie happens to expire.
             */
            $refusal = $this->signInRefusal($user);

            if ($refusal !== null) {
                $this->attempts->record($user->email, $refusal, $user->authProvider, $request->ip());

                if ($request->wantsJson()) {
                    return Response::error($this->explainRefusal($refusal), 403);
                }

                return Response::html($this->refusedPage($user, $refusal), 403);
            }

            if ($user->isAdmin() || $user->authorized) {
                $unverified = $this->unverifiedBlock($user);

                if ($unverified !== null) {
                    return $request->wantsJson()
                        ? Response::error('Confirm your email address before watching.', 403)
                        : Response::html($unverified, 403);
                }

                $this->users->touchLastSeen($user->id);
                return null;
            }

            if ($request->wantsJson()) {
                return Response::error('Your account is not approved to view videos.', 403);
            }

            // A pending viewer gets an explanation rather than a bare 403 —
            // they have done nothing wrong and there is nothing to retry.
            return Response::html($this->pendingPage($user), 403);
        };
    }

    /**
     * Require a capability, optionally scoped to a route parameter.
     *
     * @param string|null $scopeType    'category' | 'series' | 'video'
     * @param string|null $scopeParam   route parameter holding the scope id
     * @return callable(Request, array<string,string>): (Response|null)
     */
    public function requireCapability(
        string $capability,
        ?string $scopeType = null,
        ?string $scopeParam = null
    ): callable {
        return function (Request $request, array $params) use ($capability, $scopeType, $scopeParam): ?Response {
            $user = $this->user();

            if ($user === null) {
                return $this->challenge($request);
            }

            $scopeId = null;
            if ($scopeType !== null && $scopeParam !== null && isset($params[$scopeParam])) {
                $scopeId = (int) $params[$scopeParam];
            }

            if ($this->capabilities->can($user, $capability, $scopeType, $scopeId)) {
                return null;
            }

            if ($request->wantsJson()) {
                return Response::error('You do not have permission to do that.', 403);
            }

            throw HttpException::forbidden('You do not have permission to do that.');
        };
    }

    /**
     * Require any reason at all to be in the admin area.
     *
     * A coarse gate for the admin shell itself. Individual routes still declare
     * the specific capability they need — this only decides who gets past the
     * front door, so a category editor is not met with a 403 on /admin.
     */
    public function requireAdminArea(): callable
    {
        return function (Request $request): ?Response {
            $user = $this->user();

            if ($user === null) {
                return $this->challenge($request);
            }

            if ($this->capabilities->canSeeAdmin($user)) {
                return null;
            }

            if ($request->wantsJson()) {
                return Response::error('Administrators only.', 403);
            }

            throw HttpException::forbidden('This area is for administrators.');
        };
    }

    // ------------------------------------------------------------- responses

    /**
     * What to do with someone who is not signed in.
     *
     * The return path is preserved so that following a share link while signed
     * out lands on the video after sign-in, rather than dumping the person on
     * the homepage with no idea what they clicked.
     */
    private function challenge(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::error('Login required', 401);
        }

        $returnTo = Request::sanitizeReturnTo($request->path);

        return Response::redirect($this->auth->loginUrl($returnTo));
    }

    /**
     * The page to show instead of the video, or null to let them through.
     *
     * THE OLDEST OPEN ITEM IN THIS PROJECT. All three predecessor apps recorded
     * "email_verified is not enforced" as a known gap, and this codebase has
     * carried the claim from the identity provider since Phase 1 without ever
     * acting on it. Acting on it is a decision with real lockout risk, so it is
     * built as a switch rather than as a rule — and off, so the risky decision
     * belongs to whoever owns the site rather than to whoever wrote this.
     *
     * Two exemptions, and both are the difference between a setting and a trap:
     *
     * ADMINISTRATORS ARE NEVER BLOCKED. The person who can turn this off must
     * never be locked out by it. That is the same property the geo plugin
     * protects — restricting the public site can never, on its own, close the
     * screen that would undo it — and it is the reason this is safe to ship on
     * by anybody who wants it.
     *
     * AN ACCOUNT WITH A LOCAL PASSWORD IS NEVER BLOCKED. There is no
     * verification flow for local passwords; nothing in this app can ever set
     * email_verified on one, so requiring it would lock every local account out
     * permanently with no path back. Local sign-in is the break-glass route on
     * a host with no shell, and this must not be the thing that closes it.
     *
     * What is left is exactly the case the setting is for: an account that came
     * from an identity provider which said, in so many words, that the address
     * was not confirmed.
     */
    /**
     * Does the sign-in allowlist refuse this person, and why?
     *
     * Null when the feature is off, when they are exempt, or when they are on
     * the list — the overwhelmingly common answers, all reached without a
     * query, because the setting is checked first.
     *
     * FAILS TO THE DEFAULT, NOT CLOSED, and that is a deliberate exception to
     * this codebase's standing rule that access checks fail closed. The default
     * is OFF: a site that has never enabled this has no list, and a missing
     * table on a half-migrated install is indistinguishable from the feature
     * not being installed. Failing closed there would refuse every visitor to
     * the whole site — including the administrator, on a host with no shell,
     * with no way to reach the screen that would switch it off.
     *
     * This is the same shape as the geo plugin's four independent fail-open
     * paths and as unverifiedBlock() directly below: failing to a default is
     * not failing past a boundary.
     */
    private function allowlistRefusal(User $user): ?string
    {
        try {
            if (!$this->config->settingBool('signin_allowlist_enabled', false)) {
                return null;
            }

            /*
             * Exemptions resolved before the lookup, so an administrator is
             * never one database hiccup away from being locked out of their own
             * site — and so the query is not run for them at all.
             */
            if ($user->isAdmin() || $user->hasPassword) {
                return null;
            }

            return SignInAllowlist::decide(
                true,
                false,
                false,
                $this->allowlist->statusOf($user->email)
            );
        } catch (\Throwable $e) {
            error_log('Portal: could not read the sign-in allowlist: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Both sign-in gates, combined the way the site asked for.
     *
     *   all     the allowlist AND the membership claim
     *   either  one of them is enough
     *
     * The exemptions are applied ONCE, here, to both gates together. Applying
     * them per-gate would be the same rule written twice, and a local-password
     * account carries no claim at all, so a claim gate without the exemption
     * refuses every one of them — including the account that is the documented
     * way back in when the identity provider is the thing that is broken.
     *
     * Fails to the DEFAULT rather than closed, for the reason written on
     * allowlistRefusal(): both gates are off unless configured, and a
     * half-migrated install must not become a site nobody can enter.
     */
    private function signInRefusal(User $user): ?string
    {
        if ($user->isAdmin() || $user->hasPassword) {
            return null;
        }

        try {
            $accepted = ClaimGate::parseValues(
                (string) $this->config->setting('signin_claim_values', '')
            );

            $mode = ClaimGate::normalizeMode((string) $this->config->setting('signin_mode', ''));

            /*
             * CONFIGURED and COUNTS are two different facts, settled before
             * either gate is asked, and keeping them apart is the whole
             * correctness of this method.
             *
             * Configured: is there anything to check against — a claim name and
             * accepted values, or the allowlist switched on.
             *
             * Counts: does the chosen mode consult that check at all.
             *
             * A check that counts but is not configured cannot refuse anybody,
             * so it is skipped rather than failed. Conflating the two would
             * mean selecting BOTH on a site with no organisation configured
             * refused every visitor — which, on a product installed by
             * strangers on hosting with no shell, is a site nobody can recover.
             *
             * And an unconfigured gate returning "no refusal" is
             * indistinguishable from one that let somebody through, so under
             * EITHER it would wave everybody past the gate that IS switched on.
             * The mode is only applied when there are genuinely two answers.
             */
            $claimOn = trim((string) $this->config->setting('signin_claim_name', '')) !== ''
                && $accepted !== [];
            $listOn = $this->config->settingBool('signin_allowlist_enabled', false);

            $claimCounts = $claimOn && ClaimGate::countsOrganisation($mode);
            $listCounts = $listOn && ClaimGate::countsAllowlist($mode);

            // Nothing to check. A site that has configured no gate refuses
            // nobody, whatever mode is stored — which is every fresh install.
            if (!$claimCounts && !$listCounts) {
                return null;
            }

            $claimRefusal = $claimCounts
                ? ClaimGate::decide(true, $user->authClaim, $accepted)
                : null;

            $listRefusal = $listCounts ? $this->allowlistRefusal($user) : null;

            /*
             * EITHER only means "either" when both are actually being
             * consulted. With one of them out of the picture there is one
             * answer, and it is the answer.
             */
            if ($mode === ClaimGate::EITHER && $claimCounts && $listCounts) {
                return ClaimGate::combine($mode, $listRefusal, $claimRefusal);
            }

            return $listRefusal ?? $claimRefusal;
        } catch (\Throwable $e) {
            error_log('Portal: could not read the sign-in gates: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The page somebody refused by the allowlist sees.
     *
     * Names the reason, which is three different situations needing three
     * different actions — and says who to contact, because unlike the pending
     * page there is nothing here they can do for themselves. An address that
     * was never added and one that was suspended are deliberately worded
     * differently: an administrator reading it over somebody's shoulder should
     * know which screen to open.
     */
    /**
     * The right words for a refusal, whichever gate produced it.
     *
     * Two vocabularies, kept apart because the reasons are about different
     * things — a list this site maintains, and a claim somebody else's system
     * asserts — and one enum holding both would blur exactly the distinction
     * that makes the messages useful.
     */
    private function explainRefusal(string $reason): string
    {
        return in_array($reason, [ClaimGate::NOT_A_MEMBER, ClaimGate::NO_CLAIM, ClaimGate::NEITHER], true)
            ? ClaimGate::explain($reason)
            : SignInAllowlist::explain($reason);
    }

    private function refusedPage(User $user, string $reason): string
    {
        $email = e($user->email);
        $why = e($this->explainRefusal($reason));

        return $this->noticePage(
            'You cannot sign in here',
            <<<HTML
            <p>{$why}</p>
            <p class="muted">You signed in as <strong>{$email}</strong>.</p>
            <p class="muted">If you think this is wrong, ask whoever runs this site to add that
               address to the list of people who may sign in.</p>
            <p><a href="/auth/logout">Sign out</a></p>
            HTML
        );
    }

    private function unverifiedBlock(User $user): ?string
    {
        if ($user->emailVerified || $user->isAdmin() || $user->hasPassword) {
            return null;
        }

        try {
            // Resolved from the container rather than injected: Guard is built
            // in several places and adding a constructor argument would touch
            // every one of them for a setting read on one branch.
            $config = \Portal\Container::instance()->get(\Portal\Config::class);

            if (!$config->settingBool('require_verified_email', false)) {
                return null;
            }
        } catch (\Throwable $e) {
            // Unreadable settings mean this cannot be ON, because it defaults
            // to off. Failing open here is failing to the default, not past it.
            error_log('Portal: could not read the verification setting: ' . $e->getMessage());

            return null;
        }

        return $this->unverifiedPage($user);
    }

    private function unverifiedPage(User $user): string
    {
        $email = e($user->email);

        return $this->noticePage(
            'Confirm your email address',
            <<<HTML
            <h1>Confirm your email address</h1>
            <p>You are signed in as <span class="email">{$email}</span>, but the service you
               signed in with has not confirmed that the address is yours.</p>
            <p>Check that address for a confirmation message, then sign in again. If there is
               nothing there, ask whoever runs this site — they can confirm it for you.</p>
            <a href="/auth/logout">Sign out</a>
            HTML
        );
    }

    private function pendingPage(User $user): string
    {
        $email = e($user->email);

        return $this->noticePage(
            'Account pending approval',
            <<<HTML
            <h1>Your account is not approved yet</h1>
            <p>You are signed in as <span class="email">{$email}</span>, but an administrator
               has not yet given this account access to the video library.</p>
            {$this->requestBlock($user)}
            <a href="/auth/logout">Sign out</a>
            HTML
        );
    }

    /**
     * The part of the pending page that lets somebody do something about it.
     *
     * Until this existed the page ended with "let whoever invited you know",
     * which is a site creating a dead end and then handing the person a
     * telephone. The dashboard had counted them the whole time; counting is not
     * telling, and the person counted had no way to say anything at all.
     *
     * Three states, because "you have asked" and "you may ask" are different
     * things to be told, and so is "this site does not take requests".
     *
     * Rendered here rather than in a controller because this page is a 403 that
     * the guard produces before any controller runs — the same reason it is
     * standalone HTML rather than a theme template.
     */
    private function requestBlock(User $user): string
    {
        try {
            $container = \Portal\Container::instance();

            if (!$container->get(\Portal\Config::class)->settingBool('allow_access_requests', true)) {
                // Switched off deliberately: an invitation-only site does not
                // want a button that invites strangers to knock. Say nothing
                // rather than showing a form that would be refused.
                return '<p>If you were expecting access, let whoever invited you know — they can
                        approve you from the admin area.</p>';
            }

            $requests = $container->get(AccessRequests::class);

            if ($requests->has($user->id)) {
                return '<p><strong>Your request has been sent.</strong> An administrator will see it
                        the next time they sign in. There is nothing more to do here — you will be
                        able to sign in and watch as soon as somebody approves the account.</p>';
            }

            $token = \Portal\Support\Csrf::field($this->session);
            $limit = AccessRequests::MAX_NOTE;

            return <<<HTML
            <p>You can ask for access here, and whoever runs the site will be told.</p>
            <form method="post" action="/request-access">
              {$token}
              <label for="note">Anything worth saying about who you are (optional)</label>
              <textarea id="note" name="note" rows="3" maxlength="{$limit}"
                        placeholder="e.g. I'm on the Thursday team — Sam asked me to sign up."></textarea>
              <button type="submit">Ask for access</button>
            </form>
            HTML;
        } catch (\Throwable $e) {
            /*
             * This page's whole job is to explain a refusal without becoming a
             * different failure. Before migration 0018 has run, or with the
             * container in an odd state, the person still gets the page they
             * came for — just without the form.
             */
            error_log('Access requests: could not render the request form. ' . $e->getMessage());

            return '<p>If you were expecting access, let whoever invited you know — they can
                    approve you from the admin area.</p>';
        }
    }

    /**
     * The chrome both notices share.
     *
     * Standalone HTML rather than a theme template, deliberately: this renders
     * for somebody the site is refusing, and a 403 page that depends on the
     * theme booting is a 403 that can become a 500.
     */
    private function noticePage(string $title, string $body): string
    {
        $title = e($title);

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{$title}</title>
        <style>
          :root { color-scheme: dark; }
          body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
                 padding:2rem; background:#0f172a; color:#e2e8f0;
                 font:16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Inter, sans-serif; }
          .card { max-width:32rem; background:rgba(30,41,59,.6); border:1px solid rgba(148,163,184,.18);
                  border-radius:16px; padding:2.5rem; backdrop-filter:blur(12px); }
          h1 { font-size:1.375rem; margin:0 0 .75rem; font-weight:600; }
          p { margin:0 0 1rem; color:#cbd5e1; }
          .email { color:#38bdf8; font-weight:500; }
          a { display:inline-block; margin-top:.5rem; padding:.5rem 1.125rem; border-radius:10px;
              border:1px solid rgba(148,163,184,.3); color:#e2e8f0; text-decoration:none; font-size:.9375rem; }
          /* The request form. Deliberately quiet: it is an option on this page,
             not the point of it. */
          label { display:block; margin-bottom:.375rem; font-size:.875rem; color:#94a3b8; }
          textarea { width:100%; box-sizing:border-box; padding:.625rem .75rem; border-radius:10px;
                     border:1px solid rgba(148,163,184,.26); background:rgba(15,23,42,.6);
                     color:#e2e8f0; font:inherit; font-size:.9375rem; resize:vertical; }
          button { margin-top:.75rem; padding:.5rem 1.125rem; border-radius:10px; border:0;
                   background:#38bdf8; color:#0b1220; font:inherit; font-weight:600;
                   font-size:.9375rem; cursor:pointer; }
          form { margin:0 0 1rem; }
        </style>
        </head>
        <body>
          <main class="card">
            {$body}
          </main>
        </body>
        </html>
        HTML;
    }
}
