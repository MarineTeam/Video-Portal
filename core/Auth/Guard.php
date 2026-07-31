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

            if ($user->isAdmin() || $user->authorized) {
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

    private function pendingPage(User $user): string
    {
        $email = e($user->email);

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>Account pending approval</title>
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
        </style>
        </head>
        <body>
          <main class="card">
            <h1>Your account is not approved yet</h1>
            <p>You are signed in as <span class="email">{$email}</span>, but an administrator
               has not yet given this account access to the video library.</p>
            <p>If you were expecting access, let whoever invited you know — they can approve
               you from the admin area.</p>
            <a href="/auth/logout">Sign out</a>
          </main>
        </body>
        </html>
        HTML;
    }
}
