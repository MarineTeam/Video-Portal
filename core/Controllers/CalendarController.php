<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Schedules\ScheduleRepository;

/**
 * The schedules calendar, as anybody reads it.
 *
 * NO LOGIN. That is the whole shape of this section: the people on these rotas
 * do not have accounts, and neither do most of the people reading it. Several
 * schedules side by side, one page, no session required.
 *
 * # CHOOSING YOUR NAME
 *
 * The calendar opens on your own dates once you have said who you are, and the
 * choice lives in a cookie.
 *
 * It is NOT signed, and that is deliberate rather than an omission. Everything
 * the choice affects is already on the page: every name and every date is
 * public, and the only thing the cookie does is decide which rows are marked as
 * yours. Somebody editing it sees a different set of rows highlighted on their
 * own screen — which is not an attack, it is a preference they could have set
 * by clicking. Signing it would imply the value guards something.
 */
final class CalendarController extends Controller
{
    /** The cookie holding "who I am" on this device. A year is a rota season. */
    private const COOKIE = 'portal_calendar_me';
    private const COOKIE_DAYS = 365;

    public function index(Request $request): Response
    {
        $schedules = $this->schedules();

        $from = $this->day((string) ($request->query('from') ?? ''), date('Y-m-d'));
        $to = $this->day(
            (string) ($request->query('to') ?? ''),
            date('Y-m-d', strtotime('+' . ScheduleRepository::WINDOW_DAYS . ' days'))
        );

        $meId = (int) ($request->cookie(self::COOKIE) ?? 0);
        $me = $meId > 0 ? $schedules->person($meId) : null;

        $entries = $schedules->calendar($from, $to);

        /*
         * Grouped by day here rather than in the template, so a theme that
         * wants a different layout is not also re-deriving the grouping — and
         * so the "is this mine" mark is decided once.
         */
        $days = [];

        foreach ($entries as $entry) {
            $day = (string) $entry['on_date'];
            $entry['mine'] = $me !== null && (int) $entry['person_id'] === (int) $me['id'];
            $days[$day][] = $entry;
        }

        return $this->view(['calendar'], [
            'title'     => 'Who is on',
            'days'      => $days,
            'schedules' => $schedules->schedules(),
            'people'    => $schedules->peopleOnCalendar($from),
            'me'        => $me,
            'from'      => $from,
            'to'        => $to,
            'token'     => $this->csrfToken(),
            'flash'     => $this->flash(),
        ]);
    }

    /**
     * Say who you are, once.
     *
     * A POST rather than a link, so a crawler cannot set it and a shared link
     * cannot decide it for somebody else.
     */
    public function choose(Request $request): Response
    {
        $this->verifyCsrf($request);

        $id = (int) ($request->input('person') ?? 0);

        $response = $this->back($request, $id > 0
            ? 'The calendar will open on your dates from now on, on this device.'
            : 'Forgotten. The calendar shows everybody again.');

        if ($id <= 0) {
            return $response->clearCookie(self::COOKIE);
        }

        /*
         * A person who does not exist is not stored, so a typed id cannot leave
         * a cookie pointing at nothing that then has to be handled on every
         * render.
         */
        if ($this->schedules()->person($id) === null) {
            return $this->back($request, 'That is not a name on any of these rotas.', 'error');
        }

        return $response->cookie(self::COOKIE, (string) $id, [
            'expires' => time() + self::COOKIE_DAYS * 86400,
            // Lax rather than Strict: somebody following a link to the calendar
            // from a church WhatsApp group should still arrive on their own
            // dates, which is the entire point of remembering it.
            'samesite' => 'Lax',
        ]);
    }

    private function day(string $raw, string $fallback): string
    {
        $raw = trim($raw);
        $stamp = $raw === '' ? false : strtotime($raw);

        return $stamp === false ? $fallback : date('Y-m-d', $stamp);
    }

    private function schedules(): ScheduleRepository
    {
        return new ScheduleRepository($this->db());
    }
}
