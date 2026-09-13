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
use Portal\Support\SecretGuard;
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
     * What this person has watched, and a way to forget it.
     *
     * The rows are the same ones "continue watching" reads, so clearing is not
     * cosmetic: the video stops being offered to resume. That is what somebody
     * clearing their history means, and the screen says so rather than letting
     * them find out by noticing the row is gone from the front page.
     */
    public function history(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $data = new \Portal\Account\PersonalData($this->db());

        if ($request->method === 'POST') {
            $this->verifyCsrf($request);

            $videoId = (int) ($request->input('video_id') ?? 0);
            $removed = $data->forget($user->id, $videoId > 0 ? $videoId : null);

            return $this->back(
                $request,
                $videoId > 0
                    ? 'Forgotten. It will not be offered to resume.'
                    : sprintf('Cleared %d entr(ies). Nothing is offered to resume now.', $removed)
            );
        }

        return $this->view(['account-history'], [
            'title'   => 'What you have watched',
            'history' => $data->history($user->id),
            'token'   => $this->csrfToken(),
            'flash'   => $this->flash(),
        ]);
    }

    /**
     * Everything this site holds about this person, as a file.
     *
     * Their own data, so no capability is involved — the only thing that
     * decides what is in it is who is signed in, and every query is keyed to
     * them. There is deliberately no way to ask for somebody else's: an
     * identifier in the URL would make this an endpoint worth guessing at.
     *
     * Streamed as JSON rather than rendered, because it is a thing to keep
     * rather than to read on screen.
     */
    public function export(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $payload = (new \Portal\Account\PersonalData($this->db()))->export($user);

        \Portal\Support\Audit::log(
            $this->db(),
            $user->email,
            'account.export',
            'user',
            (string) $user->id
        );

        /*
         * Asserted here as well as at Response::json, because this exit does
         * not go through it — the file is pretty-printed and sent as a
         * download, so it builds its own body.
         *
         * That is exactly the kind of endpoint the guard exists for: it walks
         * eight tables, several of which have columns nobody would put in an
         * export on purpose, and it is the one payload guaranteed to be
         * emailed onwards.
         */
        SecretGuard::assertClean($payload, 'member data export');

        return Response::text((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ))
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="my-data.json"')
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Delete your own account.
     *
     * The rules — what goes, what stays detached, who is refused — live in
     * AccountDeletion, so this handler only asks and renders. The page lists
     * what will stay BEFORE the button, because learning afterwards that a
     * sign-up outlived the account is the surprise this screen exists to
     * prevent.
     *
     * No rate limit and no password prompt: the person is already signed in,
     * and typing their own address is the deliberate step. A password would
     * also be a question somebody signing in through Auth0 cannot answer.
     *
     * Afterwards, through /auth/logout rather than straight home, so an
     * identity provider's own session ends too. Otherwise "Sign in" would
     * silently re-authenticate and create a brand-new account, which reads as
     * the deletion not having happened.
     */
    public function delete(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $deletion = new \Portal\Account\AccountDeletion($this->db());
        $problem = null;

        if ($request->method === 'POST') {
            $this->verifyCsrf($request);

            $problem = $deletion->refusal($user, (string) ($request->input('confirm_email') ?? ''));

            if ($problem === null) {
                $deletion->delete($user);

                /** @var Session $session */
                $session = $this->container->get(Session::class);
                $session->logout();

                return $this->redirect('/auth/logout')->private();
            }
        }

        return $this->view(['account-delete'], [
            'title'   => 'Delete your account',
            'stays'   => $deletion->whatStays($user),
            'problem' => $problem,
            'token'   => $this->csrfToken(),
        ]);
    }

    /**
     * What is saved on this device.
     *
     * The one screen in this application the server cannot fill in. Everything
     * on it lives in Cache Storage in this browser, so the page ships empty
     * and JavaScript renders it — and the page says so, because a list that is
     * blank in a second browser looks broken unless somebody explains that it
     * is a property of the design.
     *
     * The alternative was a table on the server describing what is on people's
     * phones. That is a worse thing to keep than a list which does not survive
     * clearing site data.
     */
    public function downloads(Request $request): Response
    {
        if ($this->user() === null) {
            return $this->redirect('/auth/login');
        }

        return $this->view(['account-downloads'], [
            'title' => 'Saved for offline',
            'flash' => $this->flash(),
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

    /**
     * When to be reminded of a rota you are on.
     *
     * On its own screen rather than folded into the notifications list, because
     * the two answer different questions: that one is "what was I told", this
     * one is "what do I want to be told". And this one is reachable by
     * somebody who has never been told anything, which is exactly who needs it.
     */
    /**
     * What this site may send you, and how.
     *
     * The three consent rules are only real if somebody can exercise them: an
     * opt-out nobody can reach is not an opt-out, and an SMS opt-in that only
     * an administrator can tick is not consent.
     */
    public function messages(Request $request): Response
    {
        $user = $this->user();

        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $country = (string) ($this->config()->setting('sms_country_code') ?? '');
        $broadcasts = new \Portal\Broadcast\BroadcastRepository($this->db(), $country);

        if ($request->method === 'POST') {
            $this->verifyCsrf($request);

            $broadcasts->savePrefs(
                $user->id,
                /*
                 * The box on the screen reads "email me", so an UNTICKED box is
                 * an opt-out. Stored as the negative it is, because a missing
                 * row has to mean "not opted out" — a positively named column
                 * defaulting to zero would silence everybody who has never
                 * visited this page.
                 */
                $request->input('by_email') === null,
                $request->input('by_sms') !== null,
                (string) ($request->input('phone') ?? '')
            );

            return $this->back($request, 'Saved.');
        }

        $prefs = $broadcasts->prefs($user->id);

        return $this->view(['account-messages'], [
            'title' => 'What we send you',
            'prefs' => $prefs,
            /*
             * Whether the number can actually be texted. An opt-in with a
             * number this cannot read is a message the gateway charges for and
             * nobody receives, and the person who typed it is the only one who
             * can fix it — so they are the one who has to be told.
             */
            'phoneReadable' => $prefs['phone'] === null
                || \Portal\Broadcast\PhoneNumber::isSendable((string) $prefs['phone'], $country),
            'token' => $this->csrfToken(),
            'flash' => $this->flash(),
        ]);
    }

    /**
     * A calendar feed for this member's own dates.
     *
     * NOBODY HAS ONE UNTIL THEY ASK. No row exists until this button is
     * pressed, so a URL cannot be guessed for an account that never wanted a
     * feed — and minting one for everybody at install would be a capability
     * handed to anybody who ever reads the database.
     */
    public function calendarFeed(Request $request): Response
    {
        $user = $this->user();

        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $feeds = new \Portal\Feeds\CalendarFeedRepository($this->db());

        if ($request->method === 'POST') {
            $this->verifyCsrf($request);

            $action = (string) ($request->input('action') ?? '');

            if ($action === 'stop') {
                $feeds->revoke($user->id);

                return $this->back($request, 'Stopped. Every calendar subscribed to it will now '
                    . 'find nothing there.');
            }

            $feeds->issue($user->id);

            return $this->back(
                $request,
                /*
                 * Said plainly, because this is the one thing somebody needs to
                 * understand before pressing it a second time: a replacement is
                 * not an addition. It is the answer to a leak, and it has to end
                 * every existing subscription or it would not be one.
                 */
                'Here is the address. If you had one before, it has stopped working everywhere — '
                . 'which is the point of replacing it.'
            );
        }

        $feed = $feeds->forUser($user->id);

        return $this->view(['account-calendar'], [
            'title' => 'Your calendar',
            /*
             * Rendered straight into the page rather than through a guarded
             * payload. SecretGuard forbids `feed_token` by name, so this value
             * can never leave through an export or the read API — and this
             * screen is the one place it is allowed to appear at all.
             */
            'feedToken' => $feed === null ? null : (string) $feed['feed_token'],
            'lastUsed'  => $feed === null ? null : $feed['last_used_at'],
            'fetches'   => $feed === null ? 0 : (int) $feed['fetches'],
            'base'      => rtrim((string) $this->config()->get('base_url', ''), '/'),
            'token'     => $this->csrfToken(),
            'flash'     => $this->flash(),
        ]);
    }

    public function reminders(Request $request): Response
    {
        $user = $this->user();

        if ($user === null) {
            return $this->redirect('/auth/login');
        }

        $schedules = new \Portal\Schedules\ScheduleRepository($this->db());

        if ($request->method === 'POST') {
            $this->verifyCsrf($request);

            $schedules->saveReminderPrefs(
                $user->id,
                $request->input('day_before') !== null,
                $request->input('day_of') !== null,
                (int) ($request->input('send_hour') ?? 18),
                (string) ($request->input('timezone') ?? '')
            );

            return $this->back($request, 'Saved.');
        }

        $upcoming = $schedules->upcomingFor($user->id);

        return $this->view(['account-reminders'], [
            'title'    => 'Rota reminders',
            'prefs'    => $schedules->reminderPrefs($user->id),
            'upcoming' => $upcoming,
            /*
             * Whether this account is linked to a name at all. Without it the
             * screen is a form that quietly does nothing — somebody sets an
             * hour, saves, and is never reminded of anything, with no way to
             * find out that the link is what was missing.
             */
            'linked'   => (int) $this->db()->value(
                'SELECT COUNT(*) FROM {schedule_people} WHERE user_id = ?',
                [$user->id]
            ) > 0,
            'siteZone' => $this->config()->setting('timezone', date_default_timezone_get()),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
        ]);
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
