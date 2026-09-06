<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminEventView;
use Portal\Auth\Capability;
use Portal\Events\EventRepository;
use Portal\Events\Recurrence;
use Portal\Events\SeriesRepository;
use Portal\Events\Signup;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;
use Portal\Support\Csv;

/**
 * Events and recurring series, as the organiser sees them.
 *
 * The counterpart to EventController, which is open to anybody and has no
 * guard. Everything here needs MANAGE_EVENTS, and the division is the same one
 * the rota uses: one screen decides what is on, the other puts a name down.
 */
final class AdminEventController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::MANAGE_EVENTS);

        return $this->render('events', [
            'events' => $this->events()->upcoming(true, true, 100),
            'series' => $this->seriesRepo()->all(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_EVENTS);

        $events = $this->events();
        $event = $events->find((int) ($params['id'] ?? 0));

        if ($event === null) {
            throw HttpException::notFound('There is no event with that id.');
        }

        return $this->render('event', [
            'event'   => $event,
            'signups' => $events->signups((int) $event['id']),
            'taken'   => $events->taken((int) $event['id']),
        ]);
    }

    /** @param array<string, string> $params */
    public function series(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_EVENTS);

        $series = $this->seriesRepo();
        $row = $series->find((int) ($params['id'] ?? 0));

        if ($row === null) {
            throw HttpException::notFound('There is no series with that id.');
        }

        return $this->render('event-series', [
            'series'     => $row,
            'meetings'   => $series->events((int) $row['id']),
            'exclusions' => array_keys($series->exclusions((int) $row['id'])),
        ]);
    }

    /**
     * The list as a file.
     *
     * WITH A COLUMN SAYING WHO IS A MEMBER, which the spec asks for and which
     * is the reason this is worth exporting at all: the list an organiser holds
     * on the night is a list of names, and the question they are usually asked
     * afterwards is which of them the church already knows.
     *
     * Streamed through Csv, which defuses the leading characters a spreadsheet
     * reads as a formula. Every name and note here was typed by somebody else,
     * so this is exactly the file where that matters.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_EVENTS);

        $events = $this->events();
        $event = $events->find((int) ($params['id'] ?? 0));

        if ($event === null) {
            throw HttpException::notFound('There is no event with that id.');
        }

        $rows = [];

        foreach ($events->signups((int) $event['id']) as $signup) {
            $rows[] = [
                $signup['name'],
                $signup['email'],
                $signup['phone'] ?? '',
                // Not "yes"/"no": a column somebody sorts by should say what it
                // means when it is read out of context.
                empty($signup['is_member']) ? 'guest of the church' : 'member',
                (int) $signup['guests'],
                (int) $signup['party_size'],
                $signup['state'],
                $signup['note'] ?? '',
                $signup['created_at'],
            ];
        }

        $csv = Csv::document(
            ['Name', 'Email', 'Phone', 'Account', 'Guests', 'Places', 'Status', 'Note', 'Signed up'],
            $rows
        );

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'event.export',
            'event',
            (string) $event['id'],
            (string) $event['title']
        );

        return Response::text($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="' . Csv::filename('signups-' . $event['slug']) . '"'
            )
            // Somebody else's name and address in a file. A shared cache
            // holding one event's list and serving it to the next request is
            // the failure mode.
            ->header('X-Content-Type-Options', 'nosniff')
            ->private();
    }

    /** Everything the organiser can do, matched exhaustively. */
    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_EVENTS);

        $action = (string) ($request->input('action') ?? '');

        try {
            return match ($action) {
                'create'        => $this->create($request),
                'publish'       => $this->publish($request, true),
                'unpublish'     => $this->publish($request, false),
                'capacity'      => $this->capacity($request),
                'remove-signup' => $this->removeSignup($request),
                'create-series' => $this->createSeries($request),
                'generate'      => $this->generate($request),
                'cancel-date'   => $this->cancelDate($request),
                'uncancel-date' => $this->uncancelDate($request),
                'delete-series' => $this->deleteSeries($request),
                default         => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    // ----------------------------------------------------------- the actions

    private function create(Request $request): Response
    {
        $id = $this->events()->create([
            'title'            => $request->input('title'),
            'description'      => $request->input('description'),
            'location'         => $request->input('location'),
            'starts_at'        => $request->input('starts_at'),
            'ends_at'          => $request->input('ends_at'),
            'timezone'         => $request->input('timezone'),
            'member_only'      => $request->input('member_only') !== null,
            'signup_enabled'   => $request->input('signup_enabled') !== null,
            'capacity'         => $request->input('capacity'),
            'max_guests'       => $request->input('max_guests'),
            'signup_opens_at'  => $request->input('signup_opens_at'),
            'signup_closes_at' => $request->input('signup_closes_at'),
        ]);

        Audit::log($this->db(), $this->user()?->email, 'event.create', 'event', (string) $id);

        return $this->redirect('/admin/events/' . $id);
    }

    private function publish(Request $request, bool $published): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $this->events()->publish($id, $published);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            $published ? 'event.publish' : 'event.unpublish',
            'event',
            (string) $id
        );

        return $this->back(
            $request,
            $published ? 'Published. It is on the site now.' : 'Unpublished. It is off the site.'
        );
    }

    /**
     * Change how many places there are, and let the queue move.
     *
     * The promotion is not optional and not a separate button: the spec names a
     * capacity RISE alongside a cancellation as a thing that moves the waiting
     * list, and an organiser who raised the number and then had to remember a
     * second step would leave people waiting beside empty seats.
     */
    private function capacity(Request $request): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $raw = trim((string) ($request->input('capacity') ?? ''));

        $events = $this->events();
        $events->setCapacity($id, $raw === '' ? null : max(0, (int) $raw));

        $moved = $events->promote($id);

        if ($moved === []) {
            return $this->back($request, $raw === ''
                ? 'There is no limit now.'
                : 'Changed. Nobody on the waiting list fits yet.');
        }

        /*
         * Whoever moved up has to be told, and there is no event mailer yet —
         * so the count is reported here and the fact that they still need
         * telling is said out loud rather than quietly skipped.
         */
        return $this->back($request, sprintf(
            '%d moved up from the waiting list. They have not been emailed — there is no event '
            . 'mail yet, so they will need telling.',
            count($moved)
        ));
    }

    /**
     * Take somebody off the list, as the organiser.
     *
     * Goes through cancel() rather than deleting the row, so the queue moves
     * exactly as it would if the person had cancelled themselves. A delete
     * would free the place and move nobody.
     */
    private function removeSignup(Request $request): Response
    {
        $eventId = (int) ($request->input('id') ?? 0);
        $email = (string) ($request->input('email') ?? '');

        $moved = $this->events()->cancel($eventId, $email);

        return $this->back($request, $moved === []
            ? 'Taken off the list.'
            : sprintf('Taken off, and %d moved up from the waiting list.', count($moved)));
    }

    private function createSeries(Request $request): Response
    {
        $id = $this->seriesRepo()->create([
            'title'            => $request->input('title'),
            'description'      => $request->input('description'),
            'location'         => $request->input('location'),
            'rrule'            => $request->input('rrule'),
            'starts_at'        => $request->input('starts_at'),
            'duration_minutes' => $request->input('duration_minutes'),
            'timezone'         => $request->input('timezone'),
            'member_only'      => $request->input('member_only') !== null,
            'signup_enabled'   => $request->input('signup_enabled') !== null,
            'is_published'     => $request->input('is_published') !== null,
            'capacity'         => $request->input('capacity'),
            'max_guests'       => $request->input('max_guests'),
        ]);

        // Made straight away rather than waiting for the nightly job, so the
        // organiser can see what the rule produced while it is still in mind.
        $made = $this->seriesRepo()->generate($id);

        Audit::log($this->db(), $this->user()?->email, 'event_series.create', 'event_series', (string) $id);

        $this->session()->put('flash', [
            'type'    => 'success',
            'message' => sprintf('Made %d meeting(s). They are ordinary events now — edit or cancel any of them.', $made),
        ]);

        return $this->redirect('/admin/events/series/' . $id);
    }

    private function generate(Request $request): Response
    {
        $made = $this->seriesRepo()->generate((int) ($request->input('id') ?? 0));

        return $this->back($request, $made === 0
            ? 'Everything up to the horizon is already made.'
            : sprintf('Made %d more.', $made));
    }

    private function cancelDate(Request $request): Response
    {
        $done = $this->seriesRepo()->cancelDate(
            (int) ($request->input('id') ?? 0),
            (int) ($request->input('event_id') ?? 0)
        );

        return $this->back($request, $done
            ? 'Cancelled. It will not come back the next time the rule runs.'
            : 'That meeting is not part of this series.', $done ? 'success' : 'error');
    }

    private function uncancelDate(Request $request): Response
    {
        $done = $this->seriesRepo()->uncancelDate(
            (int) ($request->input('id') ?? 0),
            (string) ($request->input('day') ?? '')
        );

        return $this->back(
            $request,
            $done
                /*
                 * Said plainly: this is not an undelete. The sign-ups that were
                 * on the old row went with it, and there is no honest way to
                 * bring those back.
                 */
                ? 'Put back. The meeting will be made again — but anybody who had signed up for the '
                    . 'old one is not on it.'
                : 'That date was not cancelled.',
            $done ? 'success' : 'error'
        );
    }

    private function deleteSeries(Request $request): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $this->seriesRepo()->delete($id);

        Audit::log($this->db(), $this->user()?->email, 'event_series.delete', 'event_series', (string) $id);

        return $this->redirect('/admin/events');
    }

    // ---------------------------------------------------------------- wiring

    private function events(): EventRepository
    {
        return new EventRepository($this->db());
    }

    private function seriesRepo(): SeriesRepository
    {
        return new SeriesRepository($this->db(), $this->events());
    }

    private function session(): \Portal\Auth\Session
    {
        return $this->container->get(\Portal\Auth\Session::class);
    }

    /** @param array<string, mixed> $data */
    private function render(string $screen, array $data): Response
    {
        $view = new AdminEventView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
            'states'   => Signup::STATES,
            'example'  => 'FREQ=WEEKLY;BYDAY=TU',
            'known'    => Recurrence::FREQUENCIES,
        ]))->private();
    }
}
