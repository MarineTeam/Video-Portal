<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\AuthProvider;
use Portal\Auth\AuthResult;
use Portal\Auth\LocalProvider;
use Portal\Auth\PasswordPolicy;
use Portal\Auth\Session;
use Portal\Auth\UserRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;
use Portal\Support\RateLimit;
use Portal\Support\Str;
use Throwable;

/**
 * Sign in and out.
 *
 * Works with whichever auth provider is active. The local-password form is
 * always offered as a secondary option whenever any account has a password,
 * even when Auth0 is active — that is the deliberate break-glass path. On a
 * host with no shell access, a misconfigured Auth0 tenant would otherwise lock
 * the owner out of their own site permanently.
 */
final class AuthController extends Controller
{
    public function login(Request $request): Response
    {
        if ($this->guard()->isAuthenticated()) {
            return $this->redirect($request->safeReturnTo('/'));
        }

        $provider = $this->authProvider();
        $returnTo = $request->safeReturnTo('/');

        // A remote provider takes over from here; there is no form to show.
        if ($provider !== null && !$provider->isLocal()) {
            if (!$this->localFallbackAvailable()) {
                return Response::redirect($provider->loginUrl($returnTo));
            }
        }

        return $this->renderLoginForm($request, $returnTo, null);
    }

    /**
     * Sign in as a guest — the same provider, without the organisation.
     *
     * A separate route rather than a checkbox on the ordinary one, because the
     * difference is not something a visitor should be asked to understand.
     * Somebody who belongs to the organisation uses the normal link; somebody
     * excused it is sent this one by whoever invited them.
     *
     * WHY IT HAS TO BE A DIFFERENT ROUTE AT ALL: the organisation parameter
     * makes the provider render that organisation's login and refuse anybody
     * outside it — at ITS door, before this site sees the request. A guest
     * exemption applied here would never be reached. So the flag suppresses the
     * parameter for this sign-in only, and the check itself is still waived
     * server-side by GuestExemptions, which is what actually decides.
     *
     * 404 when the feature is off, not a message: an endpoint that explains
     * itself has confirmed it exists, and this one names a way in.
     */
    public function guest(Request $request): Response
    {
        if (!$this->config()->settingBool('signin_guests_enabled', false)) {
            throw HttpException::notFound('There is nothing at that address.');
        }

        if ($this->guard()->isAuthenticated()) {
            return $this->redirect($request->safeReturnTo('/'));
        }

        $provider = $this->authProvider();

        if ($provider === null || $provider->isLocal()) {
            // Nothing to waive: a local password has no organisation behind it,
            // and sending somebody here would be a dead end that looks like a
            // broken invitation.
            return $this->redirect('/auth/login');
        }

        /*
         * Set for this sign-in only and cleared when the callback lands, so a
         * guest link cannot leave a browser permanently signing in without the
         * organisation — which would silently weaken the ordinary route for
         * anybody who ever followed one.
         */
        $this->container->get(\Portal\Auth\Session::class)->put('signin_guest', true);

        return Response::redirect($provider->loginUrl($request->safeReturnTo('/')));
    }

    public function authenticate(Request $request): Response
    {
        $returnTo = $request->safeReturnTo('/');

        // Throttle by IP and by the address being attempted, so neither a
        // single noisy client nor a distributed guess at one account gets an
        // unlimited number of tries.
        $limiter = new RateLimit($this->db());
        $email = strtolower(trim($request->input('email') ?? ''));

        foreach (["login:ip:{$request->ip()}", "login:email:{$email}"] as $bucket) {
            if (!$limiter->allow($bucket, 10, 900)) {
                return $this->renderLoginForm(
                    $request,
                    $returnTo,
                    'Too many attempts. Wait a few minutes and try again.'
                );
            }
        }

        $local = $this->localProvider();
        if ($local === null) {
            return $this->renderLoginForm($request, $returnTo, 'Password sign-in is not available.');
        }

        $result = $local->handleCallback($request);

        if (!$result->ok) {
            return $this->renderLoginForm($request, $returnTo, $result->error ?? 'Sign-in failed.');
        }

        return $this->completeSignIn($result, $returnTo);
    }

    /**
     * The redirect back from a remote identity provider.
     */
    public function callback(Request $request): Response
    {
        $provider = $this->authProvider();

        if ($provider === null) {
            return $this->renderLoginForm($request, '/', 'No sign-in service is configured.');
        }

        $result = $provider->handleCallback($request);

        if (!$result->ok) {
            // Refreshing the callback, or coming back to it with the back
            // button, replays a state that has already been consumed. If the
            // sign-in it belonged to succeeded, the person is already signed
            // in and an error is both wrong and alarming — send them on.
            if ($this->guard()->isAuthenticated()) {
                return $this->redirect('/');
            }

            if ($result->retryable) {
                return $this->retrySignIn($provider, $request, $result);
            }

            return $this->renderLoginForm($request, '/', $result->error ?? 'Sign-in failed.');
        }

        return $this->completeSignIn($result, $result->returnTo);
    }

    /**
     * Quietly start the sign-in over.
     *
     * Reached when a callback carries a state nothing remembers, which almost
     * always means the sign-in page outlived the session that issued it — the
     * Auth0 URL and its state are baked into that HTML, and signing out
     * discards every pending state. Showing an error there is a dead end:
     * there is nothing wrong and nothing to fix, and the person's next move is
     * to click the same button again, which works.
     *
     * So click it for them. Nothing is granted here — the rejected code is
     * discarded and a brand-new authorization request begins — so this cannot
     * be used to launder an attacker's callback into a session.
     *
     * Guarded against looping: one automatic retry per minute. If a second
     * stale callback arrives that quickly, something is genuinely wrong and
     * the error is shown rather than bouncing forever.
     */
    private function retrySignIn(AuthProvider $provider, Request $request, AuthResult $result): Response
    {
        /** @var Session $session */
        $session = $this->container->get(Session::class);

        $lastRetry = $session->get('auth.retry_at');

        if (is_int($lastRetry) && (time() - $lastRetry) < 60) {
            $session->forget('auth.retry_at');

            return $this->renderLoginForm(
                $request,
                '/',
                'Sign-in could not be completed. Please close any other sign-in tabs and try once more.'
            );
        }

        $session->put('auth.retry_at', time());

        try {
            return Response::redirect($provider->loginUrl('/'))->private();
        } catch (Throwable) {
            return $this->renderLoginForm($request, '/', $result->error ?? 'Sign-in failed.');
        }
    }

    public function logout(Request $request): Response
    {
        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $session->logout();

        $provider = $this->authProvider();

        // End the remote session too where there is one. Without this, someone
        // "signing out" has only cleared our cookie: the identity provider
        // still holds an SSO session, so clicking "Sign in" re-authenticates
        // silently and drops them straight back in, which is indistinguishable
        // from sign-out being broken.
        if ($provider !== null) {
            try {
                $remote = $provider->logoutUrl('/');
                if ($remote !== null) {
                    return Response::redirect($remote)->private();
                }
            } catch (Throwable) {
                // Fall through to the local redirect.
            }
        }

        return $this->redirect('/')->private();
    }

    /**
     * Create your own account, when the site allows it.
     *
     * `allow_signup` has been a field on the Services screen since Phase 1 and
     * `allowsSignup()` was read by nothing, so an administrator could switch on
     * "let visitors create their own account" and no such thing existed. A
     * toggle that does nothing is worse than a missing feature: the setting
     * says the site behaves a way it does not.
     *
     * What it creates is an UNAPPROVED account, exactly as the field's own
     * description promises. That is what makes this safe to expose: the new
     * account can sign in and see nothing, and the pending page it lands on now
     * carries the request-for-access form. Sign up, ask, wait — three steps
     * that already existed separately.
     */
    public function register(Request $request): Response
    {
        $local = $this->localProvider();

        if ($local === null || !$local->allowsSignup()) {
            // Not "forbidden" — as far as this site is concerned there is no
            // such page, and saying otherwise advertises a switch somebody
            // deliberately left off.
            throw HttpException::notFound();
        }

        if ($this->guard()->isAuthenticated()) {
            return $this->redirect('/');
        }

        if ($request->method !== 'POST') {
            return $this->registerPage($local, null);
        }

        $this->verifyCsrf($request);

        /*
         * By IP only. Throttling by the submitted address would let somebody
         * lock a specific person out of registering, and the thing worth
         * limiting here is the rate at which one machine can make accounts.
         */
        $limiter = new RateLimit($this->db());
        if (!$limiter->allow('signup:ip:' . $request->ip(), 5, 3600)) {
            return $this->registerPage($local, 'Too many attempts. Try again later.');
        }

        $email = Str::normalizeEmail($request->input('email') ?? '');
        $password = (string) ($request->post['password'] ?? '');
        $name = trim($request->input('name') ?? '');

        if (!Str::isEmail($email)) {
            return $this->registerPage($local, 'That does not look like an email address.');
        }

        $problems = PasswordPolicy::problems($password, $local->minPasswordLength());
        if ($problems !== []) {
            return $this->registerPage($local, implode(' ', $problems));
        }

        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);

        /*
         * An address that already has an account produces the SAME page as one
         * that does not, and nothing is created.
         *
         * Otherwise this form is an oracle: submit an address, read the error,
         * learn whether that person has an account here. The magic-link gate
         * has been built around avoiding exactly that since Phase 2, and a
         * registration form is the easier place to ask the question.
         *
         * The cost is that somebody who forgot they had signed up is told to
         * wait for approval they may already have. They find out by signing in,
         * which is the thing they should have done.
         */
        if ($users->findByEmail($email) === null) {
            try {
                $user = $users->create(
                    email: $email,
                    name: $name !== '' ? $name : null,
                    roleSlug: \Portal\Auth\Capability::ROLE_VIEWER,
                    password: $password,
                    authorized: false,
                );

                Audit::log($this->db(), $email, 'user.signup', 'user', (string) $user->id);
                do_action('user_signed_up', $user);
            } catch (Throwable $e) {
                // Including the repository refusing the password. Reported the
                // same way as anything else rather than as a 500.
                return $this->registerPage($local, $e->getMessage());
            }
        }

        /*
         * Never signed in automatically, even on the branch that created an
         * account. A session established here would differ observably between
         * the two cases — a Set-Cookie header is all a prober needs — which
         * would undo the whole point of the identical response above.
         */
        return $this->registerDone($local);
    }

    private function registerPage(LocalProvider $local, ?string $error): Response
    {
        $siteName = (string) ($this->themeManager()->setting('site_name', 'Video Portal') ?? 'Video Portal');

        return Response::html(
            $this->registerHtml($siteName, $error, $local->minPasswordLength(), $this->csrfToken()),
            $error === null ? 200 : 422
        )->private();
    }

    private function registerDone(LocalProvider $local): Response
    {
        $siteName = e((string) ($this->themeManager()->setting('site_name', 'Video Portal') ?? 'Video Portal'));

        return Response::html($this->authPage($siteName, <<<HTML
            <h1>Thank you</h1>
            <p>If that address did not already have an account here, one has been created and is
               waiting for an administrator to approve it.</p>
            <p>You can sign in now — until somebody approves the account you will see a page
               explaining that, with a button to ask.</p>
            <a class="btn" href="/auth/login">Sign in</a>
            HTML))->private();
    }

    /**
     * "I would like access, please."
     *
     * Only reachable by somebody who is signed in and not approved, which is
     * the entire population this is for. An approved account has nothing to
     * ask for and an anonymous visitor has no identity to attach a request to,
     * so both are turned away rather than quietly accepted.
     *
     * Not behind `auth.authorized` — that middleware is exactly what refuses
     * these people, and putting it here would mean the only way to ask for
     * access was to already have it.
     */
    public function requestAccess(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();

        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        if (!$this->config()->settingBool('allow_access_requests', true)) {
            throw HttpException::forbidden('This site does not take access requests.');
        }

        // Nothing to ask for, and recording a request from somebody who already
        // has access would put a row on the pending list that can never be
        // cleared by approving anyone.
        if ($user->isAdmin() || $user->authorized) {
            return $this->redirect('/');
        }

        /** @var \Portal\Auth\AccessRequests $requests */
        $requests = $this->container->get(\Portal\Auth\AccessRequests::class);

        $note = \Portal\Auth\AccessRequests::sanitize($request->input('note') ?? '');
        $isFirstAsk = $requests->submit($user->id, $note);

        Audit::log(
            $this->db(),
            $user->email,
            'access.request',
            'user',
            (string) $user->id,
            $isFirstAsk ? 'first request' : 'updated an existing request'
        );

        /*
         * Mail only on the first ask, and only after the row exists.
         *
         * The row is the record; the email is a courtesy. Sending first would
         * mean a delivery failure could lose a request, and sending on every
         * ask would turn a button in front of any stranger who can authenticate
         * into a way to mail the administrators repeatedly.
         */
        if ($isFirstAsk) {
            try {
                $mailer = new \Portal\Auth\AccessRequestMailer(
                    $this->db(),
                    $this->config(),
                    $this->container->get(\Portal\Mail\MailProvider::class)
                );

                if ($mailer->notify($user, $note)) {
                    $requests->markNotified($user->id);
                }
            } catch (Throwable $e) {
                // The request is already stored and visible on the People
                // screen. A mail provider that is down must not turn asking for
                // access into an error page.
                error_log('Access request: notification failed. ' . $e->getMessage());
            }
        }

        /*
         * Back to the page they were refused on, which now reports that the
         * request has been sent.
         *
         * Not the homepage. Somebody who clicks a button and lands somewhere
         * else with no acknowledgement concludes it did not work and clicks it
         * again — and the second click is the one that is silently ignored,
         * because a person may only ask once. The confirmation is not a
         * courtesy here; it is what stops the fire-once guard from reading as
         * a broken button.
         */
        return $this->back($request);
    }

    // -------------------------------------------------------------- internals

    private function completeSignIn(AuthResult $result, string $returnTo): Response
    {
        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);

        $user = $users->findOrCreateFromAuth($result);

        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $session->login($user->id);

        // A successful sign-in clears the retry guard, so a stale page opened
        // days later still gets its one automatic retry.
        $session->forget('auth.retry_at');

        do_action('user_signed_in', $user);

        return $this->redirect(Request::sanitizeReturnTo($returnTo));
    }

    private function authProvider(): ?AuthProvider
    {
        try {
            return $this->container->get(AuthProvider::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A local provider instance, whether or not it is the active one.
     *
     * Built directly from the registry rather than via the active-provider
     * accessor, precisely so break-glass sign-in works when Auth0 is active.
     */
    private function localProvider(): ?LocalProvider
    {
        try {
            $provider = $this->app()->providers()->build('auth', 'local');
            return $provider instanceof LocalProvider ? $provider : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Is there anyone who could sign in with a password?
     *
     * If not, showing the form would be a dead end, so the remote provider
     * gets the redirect immediately.
     */
    private function localFallbackAvailable(): bool
    {
        try {
            return (int) $this->db()->value(
                'SELECT COUNT(*) FROM {users} WHERE password_hash IS NOT NULL AND password_hash <> ""'
            ) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function renderLoginForm(Request $request, string $returnTo, ?string $error): Response
    {
        $provider = $this->authProvider();
        $remoteUrl = null;
        $remoteLabel = null;

        if ($provider !== null && !$provider->isLocal()) {
            try {
                $remoteUrl = $provider->loginUrl($returnTo);
                $remoteLabel = $provider::label();
            } catch (Throwable) {
                $remoteUrl = null;
            }
        }

        $status = $error !== null ? 401 : 200;

        return Response::html($this->loginHtml(
            siteName: (string) ($this->themeManager()->setting('site_name', 'Video Portal') ?? 'Video Portal'),
            returnTo: $returnTo,
            error: $error,
            remoteUrl: $remoteUrl,
            remoteLabel: $remoteLabel,
            showPasswordForm: $this->localFallbackAvailable() || ($provider?->isLocal() ?? false),
            token: $this->csrfToken(),
        ), $status)->private();
    }

    /**
     * A standalone sign-in page.
     *
     * Not themed on purpose: it has to render when the theme is broken, and it
     * is the page someone needs most in exactly that situation.
     */
    private function loginHtml(
        string $siteName,
        string $returnTo,
        ?string $error,
        ?string $remoteUrl,
        ?string $remoteLabel,
        bool $showPasswordForm,
        string $token
    ): string {
        $name = e($siteName);
        $return = e($returnTo);
        $tokenAttr = e($token);

        $errorBlock = $error !== null
            ? '<div class="notice">' . e($error) . '</div>'
            : '';

        $remoteBlock = '';
        if ($remoteUrl !== null) {
            $remoteBlock = '<a class="btn" href="' . e($remoteUrl) . '">Sign in with ' . e((string) $remoteLabel) . '</a>';
            if ($showPasswordForm) {
                $remoteBlock .= '<div class="divider"><span>or use a password</span></div>';
            }
        }

        /*
         * Offered only when the site actually takes registrations. A link to a
         * page that 404s is worse than no link, and the switch being off is a
         * decision somebody made.
         */
        $local = $this->localProvider();
        $signupBlock = ($local !== null && $local->allowsSignup())
            ? '<p style="font-size:.8125rem;color:#94a3b8;margin:1.25rem 0 0;text-align:center">'
              . 'No account? <a href="/auth/register" style="color:#38bdf8">Create one</a>.</p>'
            : '';

        $formBlock = '';
        if ($showPasswordForm) {
            $secondary = $remoteUrl !== null ? ' secondary' : '';
            $formBlock = <<<HTML
            <form method="post" action="/auth/login">
              <input type="hidden" name="_token" value="{$tokenAttr}">
              <input type="hidden" name="returnTo" value="{$return}">
              <label>Email address
                <input type="email" name="email" required autocomplete="username" autofocus>
              </label>
              <label>Password
                <input type="password" name="password" required autocomplete="current-password">
              </label>
              <button class="btn{$secondary}" type="submit">Sign in</button>
            </form>
            HTML;
        }

        return $this->authPage($name, <<<HTML
            <h1>Sign in to {$name}</h1>
            {$errorBlock}
            {$remoteBlock}
            {$formBlock}
            {$signupBlock}
            HTML);
    }

    /**
     * The registration form.
     *
     * Deliberately asks for as little as possible. An account that cannot see
     * anything until somebody approves it does not need a profile; everything
     * else can be filled in later by whoever approves it.
     */
    private function registerHtml(string $siteName, ?string $error, int $minimum, string $token): string
    {
        $name = e($siteName);
        $tokenAttr = e($token);

        $errorBlock = $error !== null
            ? '<div class="notice">' . e($error) . '</div>'
            : '';

        return $this->authPage($name, <<<HTML
            <h1>Create an account</h1>
            {$errorBlock}
            <form method="post" action="/auth/register">
              <input type="hidden" name="_token" value="{$tokenAttr}">
              <label>Your name <span style="opacity:.6">(optional)</span>
                <input type="text" name="name" autocomplete="name">
              </label>
              <label>Email address
                <input type="email" name="email" required autocomplete="username" autofocus>
              </label>
              <label>Password
                <input type="password" name="password" required autocomplete="new-password"
                       minlength="{$minimum}">
              </label>
              <p style="font-size:.8125rem;color:#94a3b8;margin:-.5rem 0 1rem">
                At least {$minimum} characters. A few ordinary words together beat a short one with
                symbols in it.
              </p>
              <button class="btn" type="submit">Create account</button>
            </form>
            <p style="font-size:.8125rem;color:#94a3b8;margin:1.25rem 0 0">
              A new account cannot watch anything until an administrator approves it. You can ask
              them to, once you have signed in.
            </p>
            <div class="divider"><span>or</span></div>
            <a class="btn secondary" href="/auth/login">Sign in instead</a>
            HTML);
    }

    /**
     * The chrome the sign-in and sign-up pages share.
     *
     * Standalone rather than themed, for the same reason the guard's notice
     * pages are: these have to render when the active theme is broken, and
     * signing in is how somebody gets to the screen that would fix it.
     */
    private function authPage(string $name, string $body): string
    {
        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{$name}</title>
        <style>
          :root { color-scheme: dark; }
          * { box-sizing: border-box; }
          body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
                 padding:2rem; background:#0f172a; color:#e2e8f0;
                 font:16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Inter, sans-serif; }
          .card { width:100%; max-width:24rem; background:rgba(30,41,59,.6);
                  border:1px solid rgba(148,163,184,.18); border-radius:16px; padding:2rem;
                  backdrop-filter:blur(12px); }
          h1 { font-size:1.25rem; margin:0 0 1.5rem; font-weight:600; }
          label { display:block; font-size:.875rem; font-weight:550; margin-bottom:1rem; }
          input { width:100%; margin-top:.375rem; padding:.5625rem .8125rem; border-radius:9px;
                  border:1px solid rgba(148,163,184,.28); background:rgba(15,23,42,.55);
                  color:#e2e8f0; font:inherit; font-size:.9375rem; }
          input:focus-visible, .btn:focus-visible { outline:2px solid #38bdf8; outline-offset:2px; }
          .btn { display:block; width:100%; text-align:center; padding:.625rem 1.25rem;
                 border-radius:9px; border:1px solid transparent; background:#38bdf8; color:#0b1220;
                 font:inherit; font-weight:600; font-size:.9375rem; cursor:pointer; text-decoration:none; }
          .btn.secondary { background:transparent; border-color:rgba(148,163,184,.3); color:#e2e8f0; }
          .notice { padding:.75rem 1rem; border-radius:9px; margin-bottom:1.25rem; font-size:.875rem;
                    border:1px solid rgba(239,68,68,.5); background:rgba(239,68,68,.1); }
          .divider { display:flex; align-items:center; gap:.75rem; margin:1.25rem 0;
                     color:#64748b; font-size:.8125rem; }
          .divider::before, .divider::after { content:""; flex:1; height:1px; background:rgba(148,163,184,.2); }
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
