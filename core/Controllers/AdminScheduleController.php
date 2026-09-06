<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminScheduleView;
use Portal\Auth\Capability;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Schedules\ScheduleRepository;
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
                'create'       => $this->create($request, $schedules),
                'enable'       => $this->enable($request, $schedules, true),
                'disable'      => $this->enable($request, $schedules, false),
                'add-entry'    => $this->addEntry($request, $schedules),
                'remove-entry' => $this->removeEntry($request, $schedules),
                'merge'        => $this->merge($request, $schedules),
                'link'         => $this->link($request, $schedules),
                default        => $this->back($request, 'That is not something this screen can do.', 'error'),
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
