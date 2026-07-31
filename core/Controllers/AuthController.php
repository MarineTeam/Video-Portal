<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\AuthProvider;
use Portal\Auth\AuthResult;
use Portal\Auth\LocalProvider;
use Portal\Auth\Session;
use Portal\Auth\UserRepository;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\RateLimit;
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
            return $this->renderLoginForm($request, '/', $result->error ?? 'Sign-in failed.');
        }

        return $this->completeSignIn($result, $result->returnTo);
    }

    public function logout(Request $request): Response
    {
        /** @var Session $session */
        $session = $this->container->get(Session::class);
        $session->logout();

        $provider = $this->authProvider();

        // End the remote session too where there is one, or someone "signing
        // out" on a shared computer is still signed in at the provider and one
        // click away from being back in.
        if ($provider !== null) {
            try {
                $remote = $provider->logoutUrl('/');
                if ($remote !== null) {
                    return Response::redirect($remote);
                }
            } catch (Throwable) {
                // Fall through to the local redirect.
            }
        }

        return $this->redirect('/');
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
            siteName: (string) ($this->themes()->setting('site_name', 'Video Portal') ?? 'Video Portal'),
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

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Sign in — {$name}</title>
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
            <h1>Sign in to {$name}</h1>
            {$errorBlock}
            {$remoteBlock}
            {$formBlock}
          </main>
        </body>
        </html>
        HTML;
    }
}
