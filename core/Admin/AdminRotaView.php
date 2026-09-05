<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Rota\Assignment;

/**
 * The screens for building a rota.
 *
 * Separate from AdminView for the same reason AdminShareView is: one domain,
 * several screens, and a shared file that already renders twenty of them is a
 * file nobody can read. The shell — navigation, flash, layout — comes from
 * AdminView, so these are only the bodies.
 */
final class AdminRotaView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'rota'         => $this->overview($data),
            'rota-service' => $this->service($data),
            'rota-team'    => $this->team($data),
            default        => '<p>Unknown screen.</p>',
        };

        return (new AdminView())->shell($body, $data);
    }

    // -------------------------------------------------------------- overview

    /** @param array<string, mixed> $data */
    private function overview(array $data): string
    {
        $token = e((string) $data['token']);

        $services = '';
        foreach ((array) ($data['services'] ?? []) as $service) {
            $services .= sprintf(
                '<tr>
                   <td><a href="/admin/rota/services/%d"><strong>%s</strong></a><br>
                       <span class="muted">%s</span></td>
                   <td>%s</td>
                   <td class="right">
                     <form method="post" action="/admin/rota" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="%s" class="btn tiny secondary">%s</button>
                     </form>
                   </td>
                 </tr>',
                (int) $service['id'],
                e((string) $service['title']),
                e($this->when((string) $service['starts_at'])),
                $service['is_published']
                    ? '<span class="pill">published</span>'
                    : '<span class="pill warn">draft</span>',
                $token,
                (int) $service['id'],
                $service['is_published'] ? 'unpublish' : 'publish',
                $service['is_published'] ? 'Unpublish' : 'Publish'
            );
        }

        if ($services === '') {
            $services = '<tr><td colspan="3" class="muted">No services yet.</td></tr>';
        }

        $teams = '';
        foreach ((array) ($data['teams'] ?? []) as $team) {
            $teams .= sprintf(
                '<li><a href="/admin/rota/teams/%d">%s</a></li>',
                (int) $team['id'],
                e((string) $team['name'])
            );
        }

        if ($teams === '') {
            $teams = '<li class="muted">No teams yet.</li>';
        }

        $chasing = $this->chasing((array) ($data['unanswered'] ?? []), (array) ($data['cover'] ?? []));

        return <<<HTML
        <h1>The rota</h1>

        <p class="muted">A rota is a list of ASKS. Everybody you put on a service is invited, and it
           is theirs to answer — nobody here can accept on their behalf. Publishing a service is what
           makes its asks visible to the people asked.</p>

        {$chasing}

        <div class="cols">
          <div>
            <h2>Services</h2>
            <table>
              <thead><tr><th>When</th><th></th><th></th></tr></thead>
              <tbody>{$services}</tbody>
            </table>
          </div>
          <div>
            <h2>Add a service</h2>
            <form method="post" action="/admin/rota">
              <input type="hidden" name="_token" value="{$token}">
              <label>Name <input type="text" name="title" required placeholder="Sunday Morning"></label>
              <label>When <input type="datetime-local" name="starts_at" required></label>
              <p class="muted small">The time as it appears on the board. It is stored as a wall
                 clock rather than an instant, so it does not move by an hour when the clocks do.</p>
              <button class="btn" name="action" value="create-service">Create</button>
            </form>

            <h2>Teams</h2>
            <ul>{$teams}</ul>

            <form method="post" action="/admin/rota">
              <input type="hidden" name="_token" value="{$token}">
              <label>New team <input type="text" name="name" required placeholder="Welcome team"></label>
              <button class="btn secondary" name="action" value="create-team">Add team</button>
            </form>
          </div>
        </div>
        HTML;
    }

    /**
     * What still needs doing, above everything else.
     *
     * A rota is built once and chased all week, so the chasing is the part of
     * this screen somebody opens it for on the other six days. Hidden entirely
     * when there is nothing — a permanent "0 waiting" is a spot people learn to
     * skip, which is the same reason the video Failed tab hides at zero.
     *
     * @param list<array<string, mixed>> $unanswered
     * @param list<array<string, mixed>> $cover
     */
    private function chasing(array $unanswered, array $cover): string
    {
        if ($unanswered === [] && $cover === []) {
            return '';
        }

        $rows = '';

        foreach ($unanswered as $ask) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td class="muted small">no answer yet</td></tr>',
                e($this->when((string) $ask['starts_at'])),
                e((string) $ask['person_name']),
                e((string) $ask['team_name'])
            );
        }

        foreach ($cover as $slot) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td>'
                . '<td class="muted small">asked for cover%s</td></tr>',
                e($this->when((string) $slot['starts_at'])),
                e((string) $slot['person_name']),
                e((string) $slot['team_name']),
                empty($slot['cover_note']) ? '' : ' — ' . e((string) $slot['cover_note'])
            );
        }

        return <<<HTML
        <h2>Waiting on somebody</h2>
        <p class="muted small">Only for services that are published and still ahead. An unanswered ask
           on a draft is a rota that has not been sent out, not somebody being slow.</p>
        <table>
          <thead><tr><th>When</th><th>Who</th><th>Team</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    // --------------------------------------------------------------- service

    /** @param array<string, mixed> $data */
    private function service(array $data): string
    {
        $token = e((string) $data['token']);
        $service = (array) $data['service'];
        $serviceId = (int) $service['id'];
        $teamId = (int) ($data['teamId'] ?? 0);

        $rows = '';
        /** @var list<Assignment> $assignments */
        $assignments = (array) ($data['assignments'] ?? []);

        foreach ($assignments as $ask) {
            $rows .= sprintf(
                '<tr>
                   <td>%s</td>
                   <td>%s</td>
                   <td>%s%s</td>
                   <td class="right">
                     <form method="post" action="/admin/rota" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="withdraw" class="btn tiny danger"
                               onclick="return confirm(\'Withdraw this ask?\')">Withdraw</button>
                     </form>
                   </td>
                 </tr>',
                e($ask->personName),
                e($ask->label()),
                $this->stateBadge($ask->state),
                $ask->reason === null ? '' : '<br><span class="muted small">' . e($ask->reason) . '</span>',
                $token,
                $ask->id
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">Nobody has been asked yet.</td></tr>';
        }

        $teamTabs = '';
        foreach ((array) ($data['teams'] ?? []) as $team) {
            $teamTabs .= sprintf(
                '<a class="btn tiny %s" href="/admin/rota/services/%d?team=%d">%s</a> ',
                (int) $team['id'] === $teamId ? '' : 'secondary',
                $serviceId,
                (int) $team['id'],
                e((string) $team['name'])
            );
        }

        $picker = $this->picker($data, $serviceId, $teamId, $token);

        return <<<HTML
        <p class="muted small"><a href="/admin/rota">&larr; The rota</a></p>
        <h1>{$this->text((string) $service['title'])}</h1>
        <p class="page-subtitle muted">{$this->text($this->when((string) $service['starts_at']))}</p>

        <table>
          <thead><tr><th>Who</th><th>Doing what</th><th>Answer</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>

        <h2>Ask somebody</h2>
        <p>{$teamTabs}</p>
        {$picker}
        HTML;
    }

    /**
     * The people picker, with the answer to "what would happen" beside each name.
     *
     * THIS IS WHERE THE WARNING HAS TO BE. A blockout reported after the ask
     * has gone out is no use to anybody; read while choosing, it is the whole
     * feature. A refused name is shown greyed rather than removed, because a
     * name that silently disappears reads as a bug where "already on this
     * service" answers the question being asked.
     *
     * @param array<string, mixed> $data
     */
    private function picker(array $data, int $serviceId, int $teamId, string $token): string
    {
        $candidates = (array) ($data['candidates'] ?? []);

        if ($teamId <= 0) {
            return '<p class="muted">Add a team first — an ask names the team it is for.</p>';
        }

        if ($candidates === []) {
            return '<p class="muted">Nobody is on that team yet. '
                . '<a href="/admin/rota/teams/' . $teamId . '">Add somebody</a>.</p>';
        }

        $positions = '<option value="0">— no particular position —</option>';
        foreach ((array) ($data['positions'] ?? []) as $position) {
            $positions .= sprintf(
                '<option value="%d">%s</option>',
                (int) $position['id'],
                e((string) $position['name'])
            );
        }

        $rows = '';
        foreach ($candidates as $person) {
            $note = '';

            if (!empty($person['warning'])) {
                $note = '<br><span class="pill warn">away</span> <span class="muted small">'
                    . e((string) $person['warning']) . '</span>';
            }

            $rows .= sprintf(
                '<tr class="%s">
                   <td>%s%s</td>
                   <td class="right">%s</td>
                 </tr>',
                !empty($person['refused']) ? 'muted' : '',
                e((string) $person['person_name']),
                $note,
                !empty($person['refused'])
                    ? '<span class="muted small">already on this service</span>'
                    : sprintf(
                        '<form method="post" action="/admin/rota" class="inline">
                           <input type="hidden" name="_token" value="%s">
                           <input type="hidden" name="service_id" value="%d">
                           <input type="hidden" name="team_id" value="%d">
                           <input type="hidden" name="user_id" value="%d">
                           <select name="position_id">%s</select>
                           <button name="action" value="ask" class="btn tiny">Ask</button>
                         </form>',
                        $token,
                        $serviceId,
                        $teamId,
                        (int) $person['user_id'],
                        $positions
                    )
            );
        }

        return <<<HTML
        <p class="muted small">Somebody marked away can still be asked — the note is a warning, not a
           rule, because you may know something the calendar does not. Somebody already on this
           service cannot be asked twice.</p>
        <table>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    // ------------------------------------------------------------------ team

    /** @param array<string, mixed> $data */
    private function team(array $data): string
    {
        $token = e((string) $data['token']);
        $team = (array) $data['team'];
        $teamId = (int) $team['id'];

        $members = '';
        foreach ((array) ($data['members'] ?? []) as $member) {
            $members .= sprintf(
                '<tr>
                   <td>%s<br><span class="muted small">%s</span></td>
                   <td>%s</td>
                   <td class="right">
                     <form method="post" action="/admin/rota" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="team_id" value="%d">
                       <input type="hidden" name="user_id" value="%d">
                       <button name="action" value="remove-member" class="btn tiny secondary">Remove</button>
                     </form>
                   </td>
                 </tr>',
                e((string) $member['person_name']),
                e((string) $member['person_email']),
                e((string) ($member['position_name'] ?? '')),
                $token,
                $teamId,
                (int) $member['user_id']
            );
        }

        if ($members === '') {
            $members = '<tr><td colspan="3" class="muted">Nobody on this team yet.</td></tr>';
        }

        $positionOptions = '<option value="0">— none —</option>';
        $positionList = '';
        foreach ((array) ($data['positions'] ?? []) as $position) {
            $positionOptions .= sprintf(
                '<option value="%d">%s</option>',
                (int) $position['id'],
                e((string) $position['name'])
            );
            $positionList .= '<li>' . e((string) $position['name']) . '</li>';
        }

        if ($positionList === '') {
            $positionList = '<li class="muted">No positions — asks will name the team alone.</li>';
        }

        $people = '';
        foreach ((array) ($data['people'] ?? []) as $person) {
            $people .= sprintf(
                '<option value="%d">%s</option>',
                (int) $person['id'],
                e((string) $person['person_name'])
            );
        }

        return <<<HTML
        <p class="muted small"><a href="/admin/rota">&larr; The rota</a></p>
        <h1>{$this->text((string) $team['name'])}</h1>

        <div class="cols">
          <div>
            <h2>Who is on it</h2>
            <table>
              <thead><tr><th>Name</th><th>Usually</th><th></th></tr></thead>
              <tbody>{$members}</tbody>
            </table>
            <p class="muted small">Taking somebody off stops them being suggested. Anything they have
               already been asked to do still stands — a rota that rewrote its own past would be
               lying about who was there.</p>
          </div>
          <div>
            <h2>Add somebody</h2>
            <form method="post" action="/admin/rota">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="team_id" value="{$teamId}">
              <label>Person <select name="user_id" required>{$people}</select></label>
              <label>Usually does <select name="position_id">{$positionOptions}</select></label>
              <p class="muted small">Only approved accounts are listed. Somebody waiting for approval
                 cannot open their rota page, so an ask would have nowhere to be answered.</p>
              <button class="btn" name="action" value="add-member">Add</button>
            </form>

            <h2>Positions</h2>
            <ul>{$positionList}</ul>
            <form method="post" action="/admin/rota">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="team_id" value="{$teamId}">
              <label>New position <input type="text" name="name" required placeholder="Sound desk"></label>
              <button class="btn secondary" name="action" value="add-position">Add position</button>
            </form>
          </div>
        </div>
        HTML;
    }

    // --------------------------------------------------------------- helpers

    private function stateBadge(string $state): string
    {
        return match ($state) {
            Assignment::ACCEPTED => '<span class="pill">yes</span>',
            Assignment::DECLINED => '<span class="pill warn">no</span>',
            default              => '<span class="muted small">waiting</span>',
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
