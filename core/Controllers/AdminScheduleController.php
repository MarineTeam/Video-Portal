<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminScheduleView;
use Portal\Auth\Capability;
use Portal\Auth\Session;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Schedules\ScheduleRepository;
use Portal\Schedules\SheetSync;
use Portal\Support\Audit;

/**
 * Keeping the schedules calendar.
 *
 * The people on it have no accounts, so nothing here is a permission somebody
 * exercises about themselves — it is all one person maintaining a list on
 * everybody else's behalf, and it needs MANAGE_SCHEDULES throughout.
 */
final class AdminScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::MANAGE_SCHEDULES);

        $schedules = $this->schedules();

        return $this->render('schedules', [
            'schedules'   => $schedules->schedules(true),
            'people'      => $schedules->people(),
            /*
             * The suggestion list is on the front screen rather than behind a
             * link, because the moment it matters is just after a sync — and
             * somebody who has to go looking for it will not.
             */
            'suggestions' => $schedules->duplicateSuggestions(),
            'accounts'    => $this->accounts(),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_SCHEDULES);

        $schedules = $this->schedules();
        $schedule = $schedules->schedule((int) ($params['id'] ?? 0));

        if ($schedule === null) {
            throw HttpException::notFound('There is no schedule with that id.');
        }

        return $this->render('schedule', [
            'schedule' => $schedule,
            'entries'  => $schedules->forSchedule((int) $schedule['id']),
            'people'   => $schedules->people(),
            'source'   => $schedules->source((int) $schedule['id']),
            /*
             * The preview is held in the session rather than recomputed on
             * render, because it is a request to somebody else's server — a
             * page that re-fetched on every reload would hammer Google every
             * time somebody used the back button.
             */
            'preview'  => $this->takePreview((int) $schedule['id']),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SCHEDULES);

        $schedules = $this->schedules();
        $action = (string) ($request->input('action') ?? '');

        try {
            return match ($action) {
                'create'        => $this->create($request, $schedules),
                'enable'        => $this->enable($request, $schedules, true),
                'disable'       => $this->enable($request, $schedules, false),
                'add-entry'     => $this->addEntry($request, $schedules),
                'remove-entry'  => $this->removeEntry($request, $schedules),
                'merge'         => $this->merge($request, $schedules),
                'link'          => $this->link($request, $schedules),
                'save-source'   => $this->saveSource($request, $schedules),
                'forget-source' => $this->forgetSource($request, $schedules),
                'preview'       => $this->previewSource($request, $schedules),
                'sync'          => $this->syncSource($request, $schedules),
                default         => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    // ----------------------------------------------------------- the actions

    private function create(Request $request, ScheduleRepository $schedules): Response
    {
        $id = $schedules->createSchedule(
            (string) ($request->input('name') ?? ''),
            (string) ($request->input('icon') ?? ''),
            (string) ($request->input('colour') ?? '')
        );

        Audit::log($this->db(), $this->user()?->email, 'schedule.create', 'schedule', (string) $id);

        return $this->redirect('/admin/schedules/' . $id);
    }

    private function enable(Request $request, ScheduleRepository $schedules, bool $enabled): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $schedules->enableSchedule($id, $enabled);

        return $this->back(
            $request,
            $enabled
                ? 'Back on the calendar, with everything that was on it.'
                /*
                 * Said plainly, because the surprising half is what is KEPT.
                 * Somebody who expects disabling to be destructive will
                 * re-enter a year of rota they never lost.
                 */
                : 'Off the calendar. Nothing was deleted — turn it back on and the dates are all '
                    . 'still there.'
        );
    }

    private function addEntry(Request $request, ScheduleRepository $schedules): Response
    {
        $scheduleId = (int) ($request->input('id') ?? 0);
        $name = (string) ($request->input('person') ?? '');

        /*
         * A NAME, not a person id. Whoever keeps a rota types names, and making
         * them pick from a list first would mean adding a person before adding
         * a date — two steps for one intention, and the matching key exists
         * precisely so the name is enough.
         */
        $personId = $schedules->personFor($name);

        $schedules->put(
            $scheduleId,
            $personId,
            (string) ($request->input('on_date') ?? ''),
            (string) ($request->input('role') ?? ''),
            (string) ($request->input('note') ?? '')
        );

        return $this->back($request, 'Added.');
    }

    private function removeEntry(Request $request, ScheduleRepository $schedules): Response
    {
        $schedules->removeEntry((int) ($request->input('entry') ?? 0));

        return $this->back($request, 'Removed.');
    }

    /**
     * Fold two names into one, as somebody's decision.
     *
     * The site never does this on its own — see ScheduleRepository. What it
     * does is offer the pair and report what moved, so the person pressing the
     * button can tell whether they merged what they meant to.
     */
    private function merge(Request $request, ScheduleRepository $schedules): Response
    {
        $keep = (int) ($request->input('keep') ?? 0);
        $drop = (int) ($request->input('drop') ?? 0);

        if ($keep <= 0 || $drop <= 0 || $keep === $drop) {
            return $this->back($request, 'Choose two different people.', 'error');
        }

        $moved = $schedules->merge($keep, $drop);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'schedule.person.merge',
            'schedule_person',
            (string) $keep,
            'merged ' . $drop
        );

        return $this->back($request, sprintf(
            'Merged. %d date(s) moved, and the old spelling still finds them — so a spreadsheet '
            . 'that keeps using it will not make the person again.',
            $moved
        ));
    }

    private function link(Request $request, ScheduleRepository $schedules): Response
    {
        $personId = (int) ($request->input('person') ?? 0);
        $userId = (int) ($request->input('user') ?? 0);

        $schedules->linkToAccount($personId, $userId > 0 ? $userId : null);

        return $this->back(
            $request,
            $userId > 0
                // What linking is FOR, said where it is done. Without this the
                // control looks like tidying.
                ? 'Linked. That is what turns reminders on for them.'
                : 'Unlinked. They will get no reminders — there is nowhere to send one.'
        );
    }

    // ------------------------------------------------------- the spreadsheet

    private function saveSource(Request $request, ScheduleRepository $schedules): Response
    {
        $id = (int) ($request->input('id') ?? 0);

        $schedules->saveSource(
            $id,
            (string) ($request->input('url') ?? ''),
            (string) ($request->input('layout') ?? ''),
            (string) ($request->input('date_order') ?? '')
        );

        Audit::log($this->db(), $this->user()?->email, 'schedule.source.save', 'schedule', (string) $id);

        // Straight to a preview, because "saved" tells nobody whether the
        // address works — and the moment to find out is now, not at the first
        // unattended run in the middle of the night.
        return $this->previewSource($request, $schedules, $id);
    }

    private function forgetSource(Request $request, ScheduleRepository $schedules): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $schedules->deleteSource($id);

        return $this->back(
            $request,
            'The spreadsheet is disconnected. The dates it put on the calendar are still there — '
            . 'they are as real as any typed by hand.'
        );
    }

    /**
     * Read the sheet and say what would happen, having written nothing.
     *
     * This is also the test-connection button. A separate "just check it works"
     * action would be a second thing to keep working, and it would answer a
     * narrower question than the one somebody actually has, which is "will this
     * put the right people on the right days".
     */
    private function previewSource(
        Request $request,
        ScheduleRepository $schedules,
        ?int $id = null
    ): Response {
        $id ??= (int) ($request->input('id') ?? 0);
        $source = $schedules->source($id);

        if ($source === null) {
            return $this->back($request, 'There is no spreadsheet on this schedule yet.', 'error');
        }

        $preview = (new SheetSync($this->db(), $schedules))->preview($source);

        $this->keepPreview($id, $preview);

        return $this->redirect('/admin/schedules/' . $id);
    }

    private function syncSource(Request $request, ScheduleRepository $schedules): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $source = $schedules->source($id);

        if ($source === null) {
            return $this->back($request, 'There is no spreadsheet on this schedule yet.', 'error');
        }

        $result = (new SheetSync($this->db(), $schedules))->run($source);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'schedule.source.sync',
            'schedule',
            (string) $id,
            $result['status']
        );

        return $this->back(
            $request,
            $result['message'],
            $result['status'] === SheetSync::FAILED ? 'error' : 'success'
        );
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function keepPreview(int $scheduleId, array $preview): void
    {
        /*
         * Only the counts and a handful of rows. The whole parse of a year's
         * rota in a session row is a lot of bytes to carry around for one
         * screen, and sessions live in the database here.
         */
        $preview['rows'] = array_slice($preview['rows'], 0, 20);
        $preview['plan']['add'] = count($preview['plan']['add']);
        $preview['plan']['remove'] = count($preview['plan']['remove']);

        $this->session()->put('schedule_preview', ['id' => $scheduleId] + $preview);
    }

    /** @return array<string, mixed>|null */
    private function takePreview(int $scheduleId): ?array
    {
        $held = $this->session()->get('schedule_preview');

        if (!is_array($held) || ($held['id'] ?? 0) !== $scheduleId) {
            return null;
        }

        // Taken rather than read: a preview is of a fetch that happened once,
        // and leaving it to reappear on the next visit would show somebody a
        // report of a sheet as it was last week.
        $this->session()->forget('schedule_preview');

        return $held;
    }

    private function session(): Session
    {
        return $this->container->get(Session::class);
    }

    // ---------------------------------------------------------------- wiring

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        return $this->db()->all(
            'SELECT id, COALESCE(NULLIF(name, ""), email) AS person_name
               FROM {users} WHERE authorized = 1 ORDER BY person_name LIMIT 500'
        );
    }

    private function schedules(): ScheduleRepository
    {
        return new ScheduleRepository($this->db());
    }

    /** @param array<string, mixed> $data */
    private function render(string $screen, array $data): Response
    {
        $view = new AdminScheduleView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }
}
