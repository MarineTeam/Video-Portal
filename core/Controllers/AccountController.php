<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\LocalProvider;
use Portal\Auth\PasswordPolicy;
use Portal\Auth\Session;
use Portal\Auth\UserRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;
use Portal\Support\RateLimit;
use Throwable;

/**
 * Changing your own password.
 *
 * `UserRepository::setPassword()` has existed since Phase 1 and had no callers,
 * which means this product has never had a way to change a password. On the
 * hosts it targets that is worse than a missing convenience: the local password
 * is the break-glass path — the only way back in when the identity provider is
 * misconfigured and there is no shell — and a credential that cannot be rotated
 * is one that can only ever get older.
 *
 * Deliberately self-service only. An administrator setting somebody else's
 * password is a different feature with a real escalation surface behind it
 * (anybody holding `manage_users` could take over an administrator account by
 * setting its password), and it wants its own thinking rather than being
 * tacked on here.
 */
final class AccountController extends Controller
{
    /**
     * The account area.
     *
     * One page a member can actually get to, rather than a password form that
     * only existed because a method had no callers. Everything here is about
     * this person: what the site has told them, and the credential they sign in
     * with.
     *
     * Guarded by `auth.user` rather than `auth.authorized` throughout, for the
     * same reason the password form is: somebody waiting for approval still has
     * an account, and the notifications they subscribed to are still theirs.
     * Locking them out of their own record until an administrator gets round to
     * them would be refusing them the one page that explains the wait.
     */
    public function index(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        return $this->view(['account'], [
            'title'       => 'Your account',
            'account'     => $user,
            'unread'      => $this->log()->unreadCount($user->email),
            'hasPassword' => $user->hasPassword,
            'flash'       => $this->flash(),
        ]);
    }

    /**
     * The links this person has handed out.
     *
     * Shown whether or not they still hold share_content: withdrawing the
     * capability stops them making new links and must not hide the ones they
     * already made, because revoking those is exactly what somebody needs to
     * do next. The same reasoning the admin screen's revoke button exists for.
     */
    public function sharedLinks(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        /** @var \Portal\Sharing\ShareRepository $shares */
        $shares = $this->container->get(\Portal\Sharing\ShareRepository::class);

        return $this->view(['account-shared-links'], [
            'title'  => 'Links you have shared',
            'shares' => $shares->createdBy($user->email),
            'token'  => $this->csrfToken(),
            'flash'  => $this->flash(),
        ]);
    }

    /**
     * What this site has told you.
     *
     * The channels it sends over cannot be re-read: an email is in a mailbox
     * this app cannot see, and a push notification is gone once dismissed — or
     * never arrived, because the browser was shut or permission was refused.
     * The site has been announcing videos since Phase 4 with no way for anybody
     * to find out what it said.
     */
    public function notifications(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        if ($request->method === 'POST') {
            return $this->act($request, $user->email);
        }

        return $this->view(['account-notifications'], [
            'title'         => 'Notifications',
            'notifications' => $this->log()->forEmail($user->email),
            'unread'        => $this->log()->unreadCount($user->email),
            'token'         => $this->csrfToken(),
            'flash'         => $this->flash(),
        ]);
    }

    /**
     * Mark one read, mark everything read, delete one, or clear the lot.
     *
     * Every branch passes the signed-in address to the repository, which puts
     * it in the WHERE. The id alone would be enough to guess at somebody else's
     * row — they are sequential — and two of these actions destroy data.
     */
    private function act(Request $request, string $email): Response
    {
        $this->verifyCsrf($request);

        $action = (string) ($request->input('action') ?? '');
        $id = (int) ($request->input('id') ?? 0);
        $log = $this->log();

        switch ($action) {
            case 'read':
                $log->markRead($id, $email);

                return $this->back($request);

            case 'read-all':
                $log->markAllRead($email);

                return $this->back($request, 'Everything is marked as read.');

            case 'delete':
                $log->delete($id, $email);

                return $this->back($request);

            case 'clear':
                $count = $log->clear($email);

                return $this->back(
                    $request,
                    $count === 0 ? 'There was nothing to clear.' : 'Your notifications are cleared.'
                );

            default:
                /*
                 * An unrecognised action does nothing rather than erroring.
                 * There is no way to type one of these wrong — the buttons are
                 * the only source — so anything else is a hand-made request,
                 * and two of the real actions destroy data.
                 */
                return $this->back($request);
        }
    }

    private function log(): \Portal\Content\NotificationLog
    {
        return new \Portal\Content\NotificationLog($this->db());
    }

    public function password(Request $request): Response
    {
        $user = $this->user();

        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        /*
         * Only for accounts that HAVE a password. Somebody who signs in through
         * Auth0 has no local credential, and offering them a form whose first
         * field is "current password" would be asking for something that does
         * not exist.
         */
        if (!$user->hasPassword) {
            throw HttpException::forbidden(
                'This account signs in through your identity provider, so there is no password here to change.'
            );
        }

        if ($request->method === 'POST') {
            return $this->save($request);
        }

        return $this->view(['account-password'], [
            'title'    => 'Change your password',
            'minimum'  => $this->minimum(),
            'token'    => $this->csrfToken(),
            'problems' => [],
        ]);
    }

    private function save(Request $request): Response
    {
        $this->verifyCsrf($request);

        /** @var \Portal\Auth\User $user */
        $user = $this->user();

        /*
         * Throttled on the CURRENT password, which is a guess like any other.
         * Without this, a borrowed session — a shared machine, a stolen cookie
         * — becomes an offline-speed oracle for the existing password, and the
         * reward for guessing it is the ability to change it.
         */
        $limiter = new RateLimit($this->db());
        if (!$limiter->allow('password-change:' . $user->id, 5, 900)) {
            return $this->form($request, ['Too many attempts. Wait a few minutes and try again.']);
        }

        $current = (string) ($request->post['current_password'] ?? '');
        $new = (string) ($request->post['new_password'] ?? '');
        $confirm = (string) ($request->post['confirm_password'] ?? '');

        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);

        $hash = (string) $this->db()->value('SELECT password_hash FROM {users} WHERE id = ?', [$user->id]);

        if ($hash === '' || !password_verify($current, $hash)) {
            // Deliberately not distinguishable from any other refusal, and
            // recorded, because a run of these is what an attempt to take over
            // an account looks like from the inside.
            Audit::log($this->db(), $user->email, 'password.change.refused', 'user', (string) $user->id);

            return $this->form($request, ['That is not your current password.']);
        }

        if ($new !== $confirm) {
            return $this->form($request, ['The two new passwords do not match.']);
        }

        if ($new === $current) {
            return $this->form($request, ['That is the password you already have — pick a different one.']);
        }

        $problems = PasswordPolicy::problems($new, $this->minimum());
        if ($problems !== []) {
            return $this->form($request, $problems);
        }

        try {
            $users->setPassword($user->id, $new, $this->minimum());
        } catch (Throwable $e) {
            // The repository refuses independently of the check above. If it
            // ever disagrees, the person is told rather than shown a 500.
            return $this->form($request, [$e->getMessage()]);
        }

        /** @var Session $session */
        $session = $this->container->get(Session::class);

        /*
         * Every other session for this account goes.
         *
         * Changing a password is the action somebody takes when they think
         * their account has been used by somebody else, and leaving the other
         * sessions alive makes it a gesture. This browser is signed in again
         * immediately afterwards, so the person who just proved they know both
         * passwords is not the one thrown out.
         */
        $session->logoutEverywhere($user->id);
        $session->login($user->id);

        Audit::log($this->db(), $user->email, 'password.change', 'user', (string) $user->id);

        return $this->redirect('/account/password?changed=1');
    }

    /** @param list<string> $problems */
    private function form(Request $request, array $problems): Response
    {
        return $this->view(['account-password'], [
            'title'    => 'Change your password',
            'minimum'  => $this->minimum(),
            'token'    => $this->csrfToken(),
            'problems' => $problems,
        ]);
    }

    /**
     * The configured minimum, or the default when local sign-in is not the
     * active provider — an account can still hold a password while Auth0 is
     * active, which is precisely the break-glass case.
     */
    private function minimum(): int
    {
        try {
            $provider = $this->container->get(\Portal\Providers\ProviderRegistry::class)
                ->build('auth', LocalProvider::slug());

            if ($provider instanceof LocalProvider) {
                return $provider->minPasswordLength();
            }
        } catch (Throwable) {
            // Fall through to the default.
        }

        return PasswordPolicy::DEFAULT_MINIMUM;
    }
}
