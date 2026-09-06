<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Events\EventRepository;
use Portal\Events\SignupWindow;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;

/**
 * What is on, and putting your name down.
 *
 * # NO ACCOUNT NEEDED, AND THAT IS THE POINT
 *
 * The people a church most wants at an event are the ones who never made an
 * account. So these routes are open, the form asks for a name and an address,
 * and a signed-in person simply gets theirs filled in.
 *
 * A members-only event is INVISIBLE to a stranger rather than refused: absent
 * from the list, and a 404 at its own address. "You may not see this event"
 * tells somebody there is an event and roughly what it is called.
 */
final class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $events = $this->events();

        return $this->view(['events'], [
            'title'  => 'What is on',
            'events' => $events->upcoming($this->isMember(), $this->canManage()),
            'mine'   => $this->mine(),
            'token'  => $this->csrfToken(),
            'flash'  => $this->flash(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $events = $this->events();
        $event = $events->findBySlug((string) ($params['slug'] ?? ''));

        if ($event === null || !$this->maySee($event)) {
            throw HttpException::notFound('There is no event at that address.');
        }

        $taken = $events->taken((int) $event['id']);
        $capacity = $event['capacity'] === null ? null : (int) $event['capacity'];

        return $this->view(['event'], [
            'title'       => (string) $event['title'],
            'event'       => $event,
            'signupState' => SignupWindow::state($event, date('Y-m-d H:i:s')),
            'taken'       => $taken,
            'placesLeft'  => $capacity === null ? null : max(0, $capacity - $taken),
            /*
             * Their own place, if they have one. Shown on the event page as
             * well as in the account area because "and can cancel from either
             * place" is the requirement — somebody who has just remembered they
             * cannot go is looking at the event, not at their profile.
             */
            'yours'       => $this->yoursFor((int) $event['id']),
            'me'          => $this->user(),
            'token'       => $this->csrfToken(),
            'flash'       => $this->flash(),
        ]);
    }

    /** Put a name down. */
    public function signUp(Request $request): Response
    {
        $this->verifyCsrf($request);

        $events = $this->events();
        $event = $events->findBySlug((string) ($request->input('event') ?? ''));

        if ($event === null || !$this->maySee($event)) {
            throw HttpException::notFound('There is no event at that address.');
        }

        $user = $this->user();

        try {
            $result = $events->signUp(
                (int) $event['id'],
                (string) ($request->input('name') ?? ($user?->name ?? '')),
                (string) ($request->input('email') ?? ($user?->email ?? '')),
                (int) ($request->input('guests') ?? 0),
                $user?->id,
                (string) ($request->input('phone') ?? ''),
                (string) ($request->input('note') ?? '')
            );
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }

        /*
         * The cancellation link is given to somebody with no account, because
         * otherwise they are on a list with no way off it — and the organiser's
         * list slowly fills with people who told somebody in person and were
         * never removed.
         *
         * Signed-in people are not given it: they have a page that lists their
         * places, and a long URL in a flash message would be noise.
         */
        $message = $result->message();

        if ($user === null && $result->token !== '') {
            $message .= ' If you need to take your name off, use this link — keep it: '
                . $this->config()->url('/events/cancel/' . $result->token);
        }

        return $this->back($request, $message, $result->isGoing() ? 'success' : 'error');
    }

    /** Take a name off, as the person who put it down. */
    public function cancel(Request $request): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();

        if ($user === null) {
            throw HttpException::forbidden('Sign in, or use the link you were given.');
        }

        $events = $this->events();
        $event = $events->findBySlug((string) ($request->input('event') ?? ''));

        if ($event === null) {
            return $this->back($request, 'There is no event at that address.', 'error');
        }

        /*
         * Keyed to their own address, so an id in a form is not a way to take
         * somebody else off a list. The same rule every personal write in this
         * codebase follows.
         */
        $moved = $events->cancel((int) $event['id'], $user->email);

        return $this->back($request, $this->cancelMessage($moved));
    }

    /**
     * Take a name off with the token from the link.
     *
     * A GET that shows what would happen and a POST that does it. A cancel that
     * happened on the GET would fire the first time anything fetched the link —
     * a mail client's preview, a security scanner, a chat app's unfurler — and
     * the person would find themselves off a list they never meant to leave.
     *
     * @param array<string, string> $params
     */
    public function cancelByToken(Request $request, array $params): Response
    {
        $events = $this->events();
        $token = (string) ($params['token'] ?? '');
        $signup = $events->findByToken($token);

        if ($signup === null) {
            throw HttpException::notFound('That link does not point at anything.');
        }

        $event = $events->find((int) $signup['event_id']);

        if ($request->method === 'POST') {
            $this->verifyCsrf($request);

            $moved = $events->cancelByToken($token);

            return $this->view(['event-cancelled'], [
                'title'   => 'Taken off the list',
                'event'   => $event,
                'done'    => true,
                'message' => $this->cancelMessage($moved),
            ]);
        }

        return $this->view(['event-cancelled'], [
            'title'  => 'Take your name off?',
            'event'  => $event,
            'signup' => $signup,
            'done'   => false,
            'token'  => $this->csrfToken(),
        ]);
    }

    // ------------------------------------------------------------- helpers

    /**
     * What somebody is told when a place is given up.
     *
     * Whoever moved up is named as a number rather than left unsaid: the spec
     * requires that they are told, and until an event mailer exists this is the
     * only place it can be said at all. Stated here rather than silently
     * skipped, so it is visible that the notification is still owed.
     */
    private function cancelMessage(array $moved): string
    {
        if ($moved === []) {
            return 'Taken off the list.';
        }

        return count($moved) === 1
            ? 'Taken off the list, and one person has moved up from the waiting list.'
            : sprintf('Taken off the list, and %d people have moved up from the waiting list.', count($moved));
    }

    /** @param array<string, mixed> $event */
    private function maySee(array $event): bool
    {
        if ($this->canManage()) {
            return true;
        }

        if (!$event['is_published']) {
            return false;
        }

        return !$event['member_only'] || $this->isMember();
    }

    private function isMember(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isAdmin() || $user->authorized);
    }

    private function canManage(): bool
    {
        return $this->guard()->can(\Portal\Auth\Capability::MANAGE_EVENTS);
    }

    /** @return list<array<string, mixed>> */
    private function mine(): array
    {
        $user = $this->user();

        return $user === null ? [] : $this->events()->signupsFor($user->email);
    }

    /** @return array<string, mixed>|null */
    private function yoursFor(int $eventId): ?array
    {
        foreach ($this->mine() as $signup) {
            if ((int) $signup['event_id'] === $eventId) {
                return $signup;
            }
        }

        return null;
    }

    private function events(): EventRepository
    {
        return new EventRepository($this->db());
    }
}
