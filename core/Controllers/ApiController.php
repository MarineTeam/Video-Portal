<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Api\ApiKeys;
use Portal\Api\Scope;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\RateLimit;
use Portal\Support\SecretGuard;

/**
 * The read API. Eleven endpoints, key-authenticated, cursor-paged, read-only.
 *
 * # THE SCOPE CHECK IS THE WHOLE THING
 *
 * Every endpoint but the index begins with requireScope(), naming the scope it
 * exact. Portal\Api\Scope explains at length why `events:read` must not imply
 * `events:registrations`; what matters here is that no endpoint ever infers a
 * scope from another, and each names precisely the one it needs.
 *
 * # A GROUP'S ADDRESS IS ABSENT, NOT GUARDED
 *
 * There is no scope for it and no branch that could produce it. GroupAddress —
 * the one function in this application allowed to make one — is never called
 * from this file, and the groups endpoint selects columns by name so that
 * adding `SELECT *` could not quietly start including it.
 *
 * # READ-ONLY, AND CURSOR-PAGED
 *
 * Cursors rather than page numbers: OFFSET makes the last page the most
 * expensive and silently skips a row when something is inserted mid-walk, which
 * for an integration syncing nightly means one record missing with nothing to
 * show why.
 *
 * Everything that leaves goes through SecretGuard, which throws rather than
 * strips — a forbidden key reaching that point means a query started selecting
 * something it should not, and quietly removing it hides that until the next
 * column arrives.
 */
final class ApiController extends Controller
{
    /** Rows per page unless asked otherwise. */
    private const PER_PAGE = 50;
    private const MAX_PER_PAGE = 200;

    /** Requests per key per minute. */
    private const PER_MINUTE = 120;

    /** @var array<string, mixed>|null The key this request authenticated with. */
    private ?array $key = null;

    /**
     * A self-describing index, which needs NO KEY.
     *
     * Somebody integrating has to be able to see what exists and what a scope
     * gives before they have been given anything — otherwise the first step is
     * asking a person for a key to read documentation. It lists no data.
     */
    public function index(Request $request): Response
    {
        $base = rtrim((string) $this->config()->get('base_url', ''), '/') . '/api/v1';

        $scopes = [];

        foreach (Scope::all() as $scope => $about) {
            $scopes[$scope] = [
                'gives'         => $about['gives'],
                'personal_data' => $about['personal'],
            ];
        }

        return $this->guarded([
            'name'    => 'Video Portal read API',
            'version' => 'v1',

            'authentication' => [
                'header'  => 'Authorization: Bearer <key>',
                'or'      => 'X-Api-Key: <key>',
                'made_at' => '/admin/api-keys',
                'note'    => 'A key is shown once when it is made. Only its hash is stored, so '
                    . 'there is nothing here that can tell you a key again.',
            ],

            /*
             * Said in the index rather than left to be discovered. An
             * integration author who assumes a hierarchy writes code that
             * silently reads less than they think — or asks for more than they
             * need because they are unsure.
             */
            'scopes' => $scopes,
            'scope_rule' => 'Scopes have no hierarchy. events:read does not imply '
                . 'events:registrations. A key allows exactly the scopes it holds.',

            'paging' => [
                'how'   => 'Pass ?cursor= from a response\'s next_cursor. Absent means the end.',
                'limit' => 'Up to ' . self::MAX_PER_PAGE . ' with ?limit=.',
            ],

            'endpoints' => [
                ['path' => $base . '/categories',          'scope' => Scope::CONTENT],
                ['path' => $base . '/series',              'scope' => Scope::CONTENT],
                ['path' => $base . '/videos',              'scope' => Scope::CONTENT],
                ['path' => $base . '/files',               'scope' => Scope::CONTENT],
                ['path' => $base . '/events',              'scope' => Scope::EVENTS],
                ['path' => $base . '/events/{id}/registrations', 'scope' => Scope::REGISTRATIONS],
                ['path' => $base . '/schedules',           'scope' => Scope::CALENDAR],
                ['path' => $base . '/schedule-dates',      'scope' => Scope::CALENDAR],
                ['path' => $base . '/groups',              'scope' => Scope::GROUPS],
                ['path' => $base . '/analytics',           'scope' => Scope::ANALYTICS],
            ],

            'not_available' => [
                /*
                 * Named, so nobody spends an afternoon looking for the scope.
                 * A permission that exists is a permission somebody grants by
                 * mistake, so this one does not exist at all.
                 */
                'A small group\'s address. There is no scope for it — it is not available through '
                . 'this API at any permission level.',
                'Who is in a small group.',
                'Any individual\'s watch history.',
                'Anything anonymous: an anonymous prayer request is anonymous here too.',
            ],
        ]);
    }

    // ------------------------------------------------------------- content

    public function categories(Request $request): Response
    {
        $this->requireScope($request, Scope::CONTENT);

        return $this->page($request, 'categories', function (int $after, int $limit): array {
            return $this->db()->all(
                'SELECT id, slug, name, parent_id, position, created_at
                   FROM {categories} WHERE id > ? ORDER BY id LIMIT ' . $limit,
                [$after]
            );
        });
    }

    public function series(Request $request): Response
    {
        $this->requireScope($request, Scope::CONTENT);

        return $this->page($request, 'series', function (int $after, int $limit): array {
            return $this->db()->all(
                'SELECT id, slug, title, description, created_at
                   FROM {series} WHERE id > ? ORDER BY id LIMIT ' . $limit,
                [$after]
            );
        });
    }

    /**
     * Videos, INCLUDING drafts and members-only ones, each flagged.
     *
     * That is what content:read means, and the flags are the point: an
     * integration building a public page has to be able to tell which of these
     * it may show. Omitting them would be safer for this endpoint and would
     * make every consumer guess.
     */
    public function videos(Request $request): Response
    {
        $this->requireScope($request, Scope::CONTENT);

        return $this->page($request, 'videos', function (int $after, int $limit): array {
            return $this->db()->all(
                'SELECT id, slug, title, description, duration, series_id, speaker_id,
                        published_at, unpublish_at, is_published, member_only, thumbnail_mode,
                        status, created_at
                   FROM {videos} WHERE id > ? AND deleted_at IS NULL ORDER BY id LIMIT ' . $limit,
                [$after]
            );
        });
    }

    public function files(Request $request): Response
    {
        $this->requireScope($request, Scope::CONTENT);

        return $this->page($request, 'files', function (int $after, int $limit): array {
            return $this->db()->all(
                'SELECT id, video_id, original_name, content_type, size_bytes, created_at
                   FROM {file_assets} WHERE id > ? ORDER BY id LIMIT ' . $limit,
                [$after]
            );
        });
    }

    // -------------------------------------------------------------- events

    /**
     * Events and how full each one is. NO NAMES.
     *
     * The counts come from an aggregate rather than from rows, so there is no
     * shape of this response that could carry a person — not "the first three
     * names", not "the most recent sign-up". Who signed up is a different scope
     * and a different endpoint.
     */
    public function events(Request $request): Response
    {
        $this->requireScope($request, Scope::EVENTS);

        return $this->page($request, 'events', function (int $after, int $limit): array {
            return $this->db()->all(
                'SELECT e.id, e.slug, e.title, e.description, e.location, e.starts_at, e.ends_at,
                        e.timezone, e.capacity, e.is_published, e.member_only,
                        COALESCE(SUM(CASE WHEN s.state = "going" THEN s.party_size ELSE 0 END), 0)
                            AS places_taken,
                        COALESCE(SUM(CASE WHEN s.state = "waiting" THEN 1 ELSE 0 END), 0)
                            AS waiting
                   FROM {events} e
                   LEFT JOIN {event_signups} s ON s.event_id = e.id
                  WHERE e.id > ?
                  GROUP BY e.id
                  ORDER BY e.id LIMIT ' . $limit,
                [$after]
            );
        });
    }

    /**
     * Who signed up, with name, email and phone.
     *
     * PERSONAL DATA, and a scope of its own that events:read does not imply.
     * The difference between the two endpoints is exactly this list.
     *
     * @param array<string, string> $params
     */
    public function registrations(Request $request, array $params): Response
    {
        $this->requireScope($request, Scope::REGISTRATIONS);

        $eventId = (int) ($params['id'] ?? 0);

        return $this->page(
            $request,
            'registrations',
            function (int $after, int $limit) use ($eventId): array {
                return $this->db()->all(
                    'SELECT id, event_id, name, email, phone, guests, party_size, state, note,
                            created_at
                       FROM {event_signups}
                      WHERE event_id = ? AND id > ? ORDER BY id LIMIT ' . $limit,
                    [$eventId, $after]
                );
            }
        );
    }

    // ------------------------------------------------------------ calendar

    public function schedules(Request $request): Response
    {
        $this->requireScope($request, Scope::CALENDAR);

        return $this->page($request, 'schedules', function (int $after, int $limit): array {
            return $this->db()->all(
                'SELECT id, slug, name, icon, colour, position, is_enabled
                   FROM {schedules} WHERE id > ? ORDER BY id LIMIT ' . $limit,
                [$after]
            );
        });
    }

    /** Dates, with the names on them. Personal data, hence the scope. */
    public function scheduleDates(Request $request): Response
    {
        $this->requireScope($request, Scope::CALENDAR);

        return $this->page($request, 'dates', function (int $after, int $limit): array {
            return $this->db()->all(
                'SELECT e.id, e.schedule_id, e.on_date, e.role, e.note, p.name AS person_name
                   FROM {schedule_entries} e
                   INNER JOIN {schedule_people} p ON p.id = e.person_id
                  WHERE e.id > ? ORDER BY e.id LIMIT ' . $limit,
                [$after]
            );
        });
    }

    // -------------------------------------------------------------- groups

    /**
     * Groups, their areas and their sizes.
     *
     * NEVER THE ADDRESS AND NEVER THE MEMBERS. The columns are named one by one
     * rather than selected with a star, so a schema that gains a column cannot
     * quietly start publishing it — and `address` is simply not among them.
     *
     * There is no scope that would change this. See Scope: a permission that
     * exists is a permission somebody grants by mistake.
     */
    public function groups(Request $request): Response
    {
        $this->requireScope($request, Scope::GROUPS);

        return $this->page($request, 'groups', function (int $after, int $limit): array {
            return $this->db()->all(
                'SELECT g.id, g.slug, g.name, g.description, g.area, g.meets, g.capacity,
                        g.is_published,
                        COALESCE(SUM(CASE WHEN m.state = "member" THEN 1 ELSE 0 END), 0) AS members,
                        COALESCE(SUM(CASE WHEN m.state = "waiting" THEN 1 ELSE 0 END), 0) AS waiting
                   FROM {small_groups} g
                   LEFT JOIN {small_group_members} m ON m.group_id = g.id
                  WHERE g.id > ?
                  GROUP BY g.id
                  ORDER BY g.id LIMIT ' . $limit,
                [$after]
            );
        });
    }

    // ----------------------------------------------------------- analytics

    /**
     * Counts and totals. NO INDIVIDUAL'S HISTORY.
     *
     * Aggregates only, and the same reasoning as the events endpoint: there is
     * no shape of this response that could carry one person's viewing, because
     * nothing here selects a row keyed to anybody.
     */
    public function analytics(Request $request): Response
    {
        $this->requireScope($request, Scope::ANALYTICS);

        $days = max(1, min(365, (int) ($request->query('days') ?? 30)));

        return $this->guarded([
            'window_days' => $days,
            'totals'      => [
                'videos'    => (int) $this->db()->value(
                    'SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NULL'
                ),
                'members'   => (int) $this->db()->value(
                    'SELECT COUNT(*) FROM {users} WHERE authorized = 1'
                ),
                'events'    => (int) $this->db()->value('SELECT COUNT(*) FROM {events}'),
            ],
            'most_watched' => $this->db()->all(
                'SELECT v.slug, v.title, SUM(w.views) AS views
                   FROM {video_views} w
                   INNER JOIN {videos} v ON v.id = w.video_id
                  WHERE w.day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  GROUP BY v.id, v.slug, v.title
                  ORDER BY views DESC LIMIT 25',
                [$days]
            ),
        ]);
    }

    // ---------------------------------------------------------- internals

    /**
     * Authenticate, rate-limit, and check the scope. In that order.
     *
     * The scope check is last because the first two are about whether this
     * caller may make a request at all, and the third about what this request
     * may read. A key that authenticates and is then refused for scope still
     * counts as a use — an integration hammering an endpoint it may not read is
     * somebody relying on the key.
     */
    private function requireScope(Request $request, string $scope): void
    {
        if ($this->key === null) {
            $this->key = $this->authenticate($request);
        }

        $keys = new ApiKeys($this->db());
        $keys->touch((int) $this->key['id']);

        if (!Scope::allows(ApiKeys::scopesOf($this->key), $scope)) {
            /*
             * Names the scope that was missing. An integration author debugging
             * a 403 with no detail guesses, and the most common guess is to ask
             * for every scope — which is the opposite of what this is for.
             */
            throw HttpException::forbidden(sprintf(
                'This key does not hold %s. Scopes have no hierarchy: holding another one in the '
                . 'same family does not include this.',
                $scope
            ));
        }
    }

    /** @return array<string, mixed> */
    private function authenticate(Request $request): array
    {
        $presented = $this->presentedKey($request);
        $keys = new ApiKeys($this->db());
        $key = $keys->authenticate($presented);

        if ($key === null) {
            throw HttpException::unauthorized(
                'Send a key as "Authorization: Bearer <key>" or "X-Api-Key: <key>". '
                . 'See /api/v1 for what exists — that page needs no key.'
            );
        }

        /*
         * Rate-limited per KEY rather than per address. An integration on a
         * cloud host shares an address with thousands of others, and a
         * misbehaving key should not be able to lock out everybody on the same
         * IP — nor escape a limit by moving.
         *
         * Atomic: RateLimit uses a conditional write, so two requests arriving
         * together cannot both read "119 so far" and both be allowed.
         */
        $limiter = new RateLimit($this->db());

        if (!$limiter->allow('api:' . $key['id'], self::PER_MINUTE, 60)) {
            throw new HttpException(
                429,
                sprintf('Too many requests. This key may make %d a minute.', self::PER_MINUTE)
            );
        }

        return $key;
    }

    private function presentedKey(Request $request): string
    {
        $header = (string) ($request->header('authorization') ?? '');

        if (stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return trim((string) ($request->header('x-api-key') ?? ''));
    }

    /**
     * One page, cursor-paged.
     *
     * The cursor is the last id seen, not an offset. OFFSET makes the last page
     * the most expensive and SKIPS A ROW when something is inserted mid-walk —
     * which for a nightly sync is one record missing, silently, with nothing to
     * show why.
     *
     * @param callable(int, int): list<array<string, mixed>> $fetch
     */
    private function page(Request $request, string $key, callable $fetch): Response
    {
        $after = max(0, (int) ($request->query('cursor') ?? 0));
        $limit = (int) ($request->query('limit') ?? self::PER_PAGE);
        $limit = max(1, min(self::MAX_PER_PAGE, $limit));

        $rows = $fetch($after, $limit);
        $last = $rows === [] ? null : (int) $rows[count($rows) - 1]['id'];

        return $this->guarded([
            $key => $rows,
            /*
             * Absent rather than null at the end, so a caller looping "while
             * next_cursor" terminates without having to know that null means
             * finished. A short page is not the signal: a page can be short
             * because rows were filtered.
             */
            'next_cursor' => count($rows) < $limit || $last === null ? null : $last,
        ]);
    }

    /**
     * Everything that leaves, past the guard.
     *
     * Response::json already asserts, and this repeats it with a context name
     * so an error says which endpoint rather than leaving somebody to find it.
     *
     * @param array<string, mixed> $payload
     */
    private function guarded(array $payload): Response
    {
        SecretGuard::assertClean($payload, 'read API');

        return Response::json($payload)
            // The answer depends on which key asked, so no shared cache may
            // hold it.
            ->private()
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
