<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Events\EventRepository;
use Portal\Events\WallClock;
use Portal\Feeds\CalendarFeedRepository;
use Portal\Feeds\Ics;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;

/**
 * Three calendar feeds.
 *
 * One event, so somebody can put it in their diary from its page. A public
 * what's-on. And a member's own diary — their rota, their sign-ups, and the
 * dates a schedule names them on — behind a token they create.
 *
 * # NO SHARED CACHE, EVER
 *
 * The personal feed's answer depends entirely on who the token belongs to. A
 * shared cache holding one member's diary and serving it to the next request
 * for a different token is the whole failure this feature could produce, so
 * every response here is private() and the personal one is noindex besides.
 *
 * # WALL CLOCK BECOMES AN INSTANT HERE
 *
 * Events and services are stored as wall clock plus a zone, deliberately. A
 * calendar needs an instant, so the conversion happens at this exit through the
 * same WallClock the events section uses — which is the one place that knows
 * what to do with the hour that happens twice in October.
 */
final class CalendarFeedController extends Controller
{
    /** How far ahead any feed looks. Far enough for a term, bounded so a
     *  calendar refetching every quarter hour stays cheap. */
    private const DAYS = 180;

    /**
     * What is on, publicly.
     *
     * Members-only events are absent, the same rule their listing keeps: a feed
     * is a listing a machine reads, and the title leaks just as well from one.
     */
    public function whatsOn(Request $request): Response
    {
        $events = $this->events();
        $components = [];

        foreach ($events->upcoming($this->isMember(), false, 200) as $event) {
            $components[] = $this->eventComponent($event);
        }

        return $this->feed(
            (string) $this->config()->setting('site_name', 'Church') . ': what is on',
            $components
        );
    }

    /** One event, so it can go straight into a diary from its page. */
    public function oneEvent(Request $request, array $params): Response
    {
        $events = $this->events();
        $event = $events->findBySlug((string) ($params['slug'] ?? ''));

        if ($event === null || !$event['is_published']) {
            throw HttpException::notFound('There is no event here.');
        }

        if ($event['member_only'] && !$this->isMember()) {
            // The same 404 a missing event gets. A refusal announces that there
            // is something here to be refused, and the title is a leak too.
            throw HttpException::notFound('There is no event here.');
        }

        return $this->feed((string) $event['title'], [$this->eventComponent($event)]);
    }

    /**
     * A member's own diary.
     *
     * THE TOKEN IS THE WHOLE OF THE AUTHENTICATION — a calendar application
     * cannot log in. So there is no session here and no capability check: the
     * string in the URL either names a feed or it does not.
     *
     * @param array<string, string> $params
     */
    public function mine(Request $request, array $params): Response
    {
        $feeds = new CalendarFeedRepository($this->db());
        $userId = $feeds->userFor((string) ($params['token'] ?? ''));

        if ($userId === null) {
            /*
             * A 404 rather than a 401. There is nothing to authenticate WITH —
             * a calendar has no credentials to be asked for — and a 401 would
             * tell a crawler that a valid token exists at this shape of URL.
             */
            throw HttpException::notFound('There is no calendar here.');
        }

        $feeds->touch($userId);

        $components = [];
        $stamp = time();
        $base = rtrim((string) $this->config()->get('base_url', ''), '/');
        $zone = (string) ($this->config()->setting('timezone') ?: date_default_timezone_get());

        foreach ($feeds->rotaFor($userId, self::DAYS) as $ask) {
            $start = WallClock::toInstant((string) $ask['starts_at'], $zone);

            $components[] = Ics::event([
                'uid'     => sprintf('rota-%d@%s', (int) $ask['id'], $this->host($base)),
                'summary' => sprintf(
                    '%s — %s',
                    (string) $ask['team_name'],
                    (string) ($ask['position_name'] ?? 'serving')
                ),
                'stamp'       => $stamp,
                'start'       => $start,
                'end'         => $start + 3600,
                'description' => (string) $ask['service_title'],
                /*
                 * A DECLINED DATE IS CANCELLED, NOT OMITTED. Leaving it out
                 * leaves it on the phone of the one person who already synced
                 * — the person who said no, who then turns up. The UID has to
                 * match what they synced, which is why it is derived from the
                 * assignment id rather than generated.
                 */
                'status' => (string) $ask['state'] === 'declined'
                    ? Ics::CANCELLED
                    : Ics::CONFIRMED,
            ]);
        }

        foreach ($feeds->signUpsFor($userId, self::DAYS) as $signUp) {
            $eventZone = trim((string) ($signUp['timezone'] ?? '')) ?: $zone;
            $start = WallClock::toInstant((string) $signUp['starts_at'], $eventZone);

            $components[] = Ics::event([
                'uid'      => sprintf('signup-%d@%s', (int) $signUp['id'], $this->host($base)),
                'summary'  => (string) $signUp['title'],
                'stamp'    => $stamp,
                'start'    => $start,
                'end'      => empty($signUp['ends_at'])
                    ? $start + 3600
                    : WallClock::toInstant((string) $signUp['ends_at'], $eventZone),
                'location' => (string) ($signUp['location'] ?? ''),
                'url'      => $base . '/events/' . (string) $signUp['slug'],
            ]);
        }

        foreach ($feeds->scheduleDatesFor($userId, self::DAYS) as $date) {
            $components[] = Ics::event([
                'uid'     => sprintf('schedule-%d@%s', (int) $date['id'], $this->host($base)),
                'summary' => sprintf(
                    '%s%s',
                    (string) $date['schedule_name'],
                    trim((string) ($date['role'] ?? '')) === '' ? '' : ' — ' . (string) $date['role']
                ),
                'stamp' => $stamp,
                /*
                 * A schedule holds a DATE with no time, so it is an all-day
                 * event — and its DTEND is the following day, because DTEND is
                 * exclusive and a one-day event ending on its own date vanishes
                 * from half the calendars that read it.
                 */
                'allDayStart' => (string) $date['on_date'],
            ]);
        }

        return $this->feed('Your dates', $components)
            /*
             * noindex, because a feed URL turns up in a crawler's hands the
             * moment somebody pastes it anywhere, and this one is a person's
             * whereabouts for the next six months.
             */
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    // ---------------------------------------------------------- internals

    /** @param array<string, mixed> $event */
    private function eventComponent(array $event): string
    {
        $base = rtrim((string) $this->config()->get('base_url', ''), '/');
        $zone = trim((string) ($event['timezone'] ?? ''))
            ?: (string) ($this->config()->setting('timezone') ?: date_default_timezone_get());

        $start = WallClock::toInstant((string) $event['starts_at'], $zone);

        return Ics::event([
            'uid'         => sprintf('event-%d@%s', (int) $event['id'], $this->host($base)),
            'summary'     => (string) $event['title'],
            'stamp'       => time(),
            'start'       => $start,
            'end'         => empty($event['ends_at'])
                ? $start + 3600
                : WallClock::toInstant((string) $event['ends_at'], $zone),
            'description' => trim(strip_tags((string) ($event['description'] ?? ''))),
            'location'    => (string) ($event['location'] ?? ''),
            'url'         => $base . '/events/' . (string) $event['slug'],
        ]);
    }

    /**
     * The host part of a UID.
     *
     * A UID has to be globally unique and STABLE — a calendar matches updates
     * against it, so one that changed would add a second copy of every event
     * rather than updating the first.
     */
    private function host(string $base): string
    {
        $host = parse_url($base, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'video-portal.invalid';
    }

    /** @param list<string> $components */
    private function feed(string $name, array $components): Response
    {
        return Response::text(Ics::calendar($name, $components))
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            // Named so a calendar that saves rather than subscribes produces a
            // file somebody can recognise.
            ->header('Content-Disposition', 'inline; filename="calendar.ics"')
            ->header('X-Content-Type-Options', 'nosniff')
            /*
             * The answer depends on who asked, so no shared cache may hold it.
             * For the personal feed that is the whole of the failure this could
             * produce: one member's diary served to the next request.
             */
            ->private();
    }

    private function events(): EventRepository
    {
        // Constructed rather than resolved: the container has no autowiring, so
        // get() on an unbound class throws.
        return new EventRepository($this->db());
    }

    private function isMember(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isAdmin() || $user->authorized);
    }
}
