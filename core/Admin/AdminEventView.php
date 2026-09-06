<?php

declare(strict_types=1);

namespace Portal\Admin;

/**
 * The organiser's screens for events and recurring series.
 *
 * Separate from AdminView for the reason AdminShareView and AdminRotaView are:
 * one domain, several screens, and a file that already renders twenty of them
 * is a file nobody can read. The shell comes from AdminView.
 */
final class AdminEventView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'events'       => $this->overview($data),
            'event'        => $this->event($data),
            'event-series' => $this->series($data),
            default        => '<p>Unknown screen.</p>',
        };

        return (new AdminView())->shell($body, $data);
    }

    // -------------------------------------------------------------- overview

    /** @param array<string, mixed> $data */
    private function overview(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['events'] ?? []) as $event) {
            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/events/%d"><strong>%s</strong></a><br>
                       <span class="muted">%s</span></td>
                   <td>%s %s %s</td>
                   <td class="right">
                     <form method="post" action="/admin/events" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="%s" class="btn tiny secondary">%s</button>
                     </form>
                   </td>
                 </tr>',
                (int) $event['id'],
                e((string) $event['title']),
                e($this->when((string) $event['starts_at'])),
                $event['is_published']
                    ? '<span class="pill">published</span>'
                    : '<span class="pill warn">draft</span>',
                $event['member_only'] ? '<span class="pill warn">members only</span>' : '',
                $event['signup_enabled'] ? '<span class="pill">sign-up</span>' : '',
                $token,
                (int) $event['id'],
                $event['is_published'] ? 'unpublish' : 'publish',
                $event['is_published'] ? 'Unpublish' : 'Publish'
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">Nothing coming up.</td></tr>';
        }

        $seriesRows = '';
        foreach ((array) ($data['series'] ?? []) as $series) {
            $seriesRows .= sprintf(
                '<li><a href="/admin/events/series/%d">%s</a> <span class="muted small">%s</span></li>',
                (int) $series['id'],
                e((string) $series['title']),
                e((string) $series['rrule'])
            );
        }

        if ($seriesRows === '') {
            $seriesRows = '<li class="muted">No repeating events.</li>';
        }

        return <<<HTML
        <h1>Events</h1>

        <p class="muted">Anybody can sign up — no account needed, which is the point: the people
           most worth having at an event are often the ones who never made one. A members-only event
           is invisible to everybody else rather than refused.</p>

        <div class="cols">
          <div>
            <h2>Coming up</h2>
            <table>
              <thead><tr><th>What</th><th></th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>

            <h2>Repeating</h2>
            <ul>{$seriesRows}</ul>
          </div>

          <div>
            <h2>Add an event</h2>
            <form method="post" action="/admin/events">
              <input type="hidden" name="_token" value="{$token}">
              <label>Name <input type="text" name="title" required></label>
              <label>When <input type="datetime-local" name="starts_at" required></label>
              <label>Where <input type="text" name="location"></label>
              <label>About it <textarea name="description" rows="3"></textarea></label>

              <label class="checkbox">
                <input type="checkbox" name="signup_enabled" value="1"> People can sign up
              </label>
              <p class="muted small">Plenty of events are worth publishing with nothing to fill in.</p>

              <label>Places <input type="number" name="capacity" min="0" placeholder="no limit"></label>
              <p class="muted small">Leave empty for no limit. Everybody past the limit joins a
                 waiting list in order, and moves up when a place comes free.</p>

              <label>Guests each person may bring
                <input type="number" name="max_guests" min="0" value="0"></label>
              <p class="muted small">A guest takes a place, because a guest sits somewhere.</p>

              <label>Sign-up opens <input type="datetime-local" name="signup_opens_at"></label>
              <label>Sign-up closes <input type="datetime-local" name="signup_closes_at"></label>
              <p class="muted small">Both optional. Sign-up closes on its own when the event starts.</p>

              <label class="checkbox">
                <input type="checkbox" name="member_only" value="1"> Members only
              </label>

              <button class="btn" name="action" value="create">Create</button>
            </form>

            {$this->seriesForm($token)}
          </div>
        </div>
        HTML;
    }

    private function seriesForm(string $token): string
    {
        return <<<HTML
        <h2>Add a repeating event</h2>
        <form method="post" action="/admin/events">
          <input type="hidden" name="_token" value="{$token}">
          <label>Name <input type="text" name="title" required></label>
          <label>First one <input type="datetime-local" name="starts_at" required></label>
          <label>Repeat rule <input type="text" name="rrule" required value="FREQ=WEEKLY;BYDAY=TU"></label>

          <p class="muted small">FREQ (DAILY, WEEKLY, MONTHLY, YEARLY), INTERVAL, BYDAY, BYMONTHDAY,
             COUNT, UNTIL. <code>FREQ=MONTHLY;BYDAY=1SU</code> is the first Sunday of the month;
             <code>BYDAY=-1SA</code> the last Saturday. Anything this does not understand is refused
             when you press the button rather than quietly ignored — a rule that half-works produces
             a year of wrong dates nobody checks.</p>

          <label>How long, in minutes <input type="number" name="duration_minutes" min="0"></label>
          <label>Where <input type="text" name="location"></label>
          <label>Places each time <input type="number" name="capacity" min="0" placeholder="no limit"></label>

          <label class="checkbox">
            <input type="checkbox" name="signup_enabled" value="1"> People can sign up
          </label>
          <label class="checkbox">
            <input type="checkbox" name="is_published" value="1"> Publish them as they are made
          </label>

          <p class="muted small">Meetings are made six months ahead and the window moves forward
             daily. Each one is an ordinary event afterwards — edit, cancel or fill any of them on its
             own, and stopping the series never removes the ones already made.</p>

          <button class="btn secondary" name="action" value="create-series">Create the series</button>
        </form>
        HTML;
    }

    // ----------------------------------------------------------------- event

    /** @param array<string, mixed> $data */
    private function event(array $data): string
    {
        $token = e((string) $data['token']);
        $event = (array) $data['event'];
        $id = (int) $event['id'];
        $taken = (int) ($data['taken'] ?? 0);

        $capacity = $event['capacity'] === null ? '' : (string) (int) $event['capacity'];
        $places = $event['capacity'] === null
            ? sprintf('%d signed up, no limit', $taken)
            : sprintf('%d of %d places taken', $taken, (int) $event['capacity']);

        $rows = '';
        foreach ((array) ($data['signups'] ?? []) as $signup) {
            $rows .= sprintf(
                '<tr class="%s">
                   <td><strong>%s</strong>%s<br><span class="muted small">%s</span></td>
                   <td>%s</td>
                   <td>%s</td>
                   <td class="muted small">%s</td>
                   <td class="right">%s</td>
                 </tr>',
                $signup['state'] === 'cancelled' ? 'muted' : '',
                e((string) $signup['name']),
                // The column the spec asks for, and the reason the list is
                // worth exporting: the question afterwards is which of these
                // people the church already knows.
                empty($signup['is_member']) ? '' : ' <span class="pill">member</span>',
                e((string) $signup['email']),
                $this->stateBadge((string) $signup['state']),
                (int) $signup['party_size'],
                e((string) ($signup['note'] ?? '')),
                $signup['state'] === 'cancelled' ? '' : sprintf(
                    '<form method="post" action="/admin/events" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <input type="hidden" name="email" value="%s">
                       <button name="action" value="remove-signup" class="btn tiny secondary"
                               onclick="return confirm(\'Take this person off the list?\')">Remove</button>
                     </form>',
                    $token,
                    $id,
                    e((string) $signup['email'])
                )
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted">Nobody has signed up yet.</td></tr>';
        }

        return <<<HTML
        <p class="muted small"><a href="/admin/events">&larr; Events</a></p>
        <h1>{$this->text((string) $event['title'])}</h1>
        <p class="page-subtitle muted">{$this->text($this->when((string) $event['starts_at']))}
           &middot; <a href="/events/{$this->text((string) $event['slug'])}">See it as everybody else does</a></p>

        <p>{$places}</p>

        <form method="post" action="/admin/events" class="inline">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$id}">
          <label>Places <input type="number" name="capacity" min="0" value="{$capacity}"
                               placeholder="no limit"></label>
          <button name="action" value="capacity" class="btn tiny secondary">Change</button>
        </form>
        <p class="muted small">Raising it moves the waiting list up straight away — as far as it can,
           stopping at the first party that does not fit rather than passing over them.</p>

        <h2>Who is coming</h2>
        <p><a class="btn secondary" href="/admin/events/{$id}.csv">Download as a spreadsheet</a>
           <span class="muted small">Includes a column saying who has an account here.</span></p>

        <table>
          <thead><tr><th>Who</th><th></th><th>Places</th><th>Note</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    // ---------------------------------------------------------------- series

    /** @param array<string, mixed> $data */
    private function series(array $data): string
    {
        $token = e((string) $data['token']);
        $series = (array) $data['series'];
        $id = (int) $series['id'];

        $rows = '';
        foreach ((array) ($data['meetings'] ?? []) as $meeting) {
            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/events/%d">%s</a></td>
                   <td>%s</td>
                   <td class="right">
                     <form method="post" action="/admin/events" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <input type="hidden" name="event_id" value="%d">
                       <button name="action" value="cancel-date" class="btn tiny danger"
                               onclick="return confirm(\'Cancel this one? It will not come back.\')">Cancel</button>
                     </form>
                   </td>
                 </tr>',
                (int) $meeting['id'],
                e($this->when((string) $meeting['starts_at'])),
                $meeting['is_published']
                    ? '<span class="pill">published</span>'
                    : '<span class="pill warn">draft</span>',
                $token,
                $id,
                (int) $meeting['id']
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">Nothing made yet.</td></tr>';
        }

        $excluded = '';
        foreach ((array) ($data['exclusions'] ?? []) as $day) {
            $excluded .= sprintf(
                '<li>%s
                   <form method="post" action="/admin/events" class="inline">
                     <input type="hidden" name="_token" value="%s">
                     <input type="hidden" name="id" value="%d">
                     <input type="hidden" name="day" value="%s">
                     <button name="action" value="uncancel-date" class="btn tiny secondary">Put back</button>
                   </form>
                 </li>',
                e((string) $day),
                $token,
                $id,
                e((string) $day)
            );
        }

        if ($excluded === '') {
            $excluded = '<li class="muted">None cancelled.</li>';
        }

        return <<<HTML
        <p class="muted small"><a href="/admin/events">&larr; Events</a></p>
        <h1>{$this->text((string) $series['title'])}</h1>
        <p class="page-subtitle muted"><code>{$this->text((string) $series['rrule'])}</code></p>

        <p class="muted">This is a template, not an event. Every date below is an ordinary event with
           its own places and its own list — edit or cancel any of them without touching the rest, and
           deleting the series leaves them all standing.</p>

        <div class="cols">
          <div>
            <h2>The meetings</h2>
            <table>
              <thead><tr><th>When</th><th></th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>

            <form method="post" action="/admin/events" class="inline">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$id}">
              <button name="action" value="generate" class="btn secondary">Make any that are missing</button>
            </form>
          </div>

          <div>
            <h2>Cancelled dates</h2>
            <p class="muted small">A cancelled date stays cancelled — the rule still names it, and
               without this list the next nightly run would put the meeting straight back.</p>
            <ul>{$excluded}</ul>

            <h2>Stop this series</h2>
            <p class="muted small">Nothing more is made. Every meeting already made stays exactly
               where it is, with everybody who signed up for it.</p>
            <form method="post" action="/admin/events">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$id}">
              <button name="action" value="delete-series" class="btn danger"
                      onclick="return confirm('Stop this series? The meetings already made are kept.')">
                Stop it
              </button>
            </form>
          </div>
        </div>
        HTML;
    }

    // --------------------------------------------------------------- helpers

    private function stateBadge(string $state): string
    {
        return match ($state) {
            'going'     => '<span class="pill">coming</span>',
            'waiting'   => '<span class="pill warn">waiting</span>',
            default     => '<span class="muted small">cancelled</span>',
        };
    }

    private function when(string $stamp): string
    {
        $time = strtotime($stamp);

        return $time === false ? $stamp : date('D j M Y, g:ia', $time);
    }

    private function text(string $value): string
    {
        return e($value);
    }
}
