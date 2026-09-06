<?php

declare(strict_types=1);

namespace Portal\Admin;

/**
 * Keeping the schedules calendar.
 *
 * Separate from AdminView for the reason the share, rota and event views are.
 * The shell comes from AdminView; these are the bodies.
 */
final class AdminScheduleView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'schedules' => $this->overview($data),
            'schedule'  => $this->schedule($data),
            default     => '<p>Unknown screen.</p>',
        };

        return (new AdminView())->shell($body, $data);
    }

    /** @param array<string, mixed> $data */
    private function overview(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['schedules'] ?? []) as $schedule) {
            $rows .= sprintf(
                '<tr>
                   <td>%s <a href="/admin/schedules/%d"><strong>%s</strong></a></td>
                   <td>%s</td>
                   <td class="right">
                     <form method="post" action="/admin/schedules" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="%s" class="btn tiny secondary">%s</button>
                     </form>
                   </td>
                 </tr>',
                empty($schedule['icon']) ? '' : e((string) $schedule['icon']),
                (int) $schedule['id'],
                e((string) $schedule['name']),
                $schedule['is_enabled']
                    ? '<span class="pill">on the calendar</span>'
                    : '<span class="pill warn">off</span>',
                $token,
                (int) $schedule['id'],
                $schedule['is_enabled'] ? 'disable' : 'enable',
                $schedule['is_enabled'] ? 'Take off' : 'Put back'
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">No schedules yet.</td></tr>';
        }

        return <<<HTML
        <h1>The calendar</h1>

        <p class="muted">A second rota, for people who do not have accounts here. Names rather than
           sign-ins, and one public page anybody can read without logging in —
           <a href="/calendar">see it</a>.</p>

        <div class="cols">
          <div>
            <h2>Schedules</h2>
            <table>
              <thead><tr><th>Name</th><th></th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
            <p class="muted small">Taking one off hides its dates from the calendar and deletes
               nothing — put it back and everything is where it was.</p>

            {$this->suggestions($data, $token)}
          </div>

          <div>
            <h2>Add a schedule</h2>
            <form method="post" action="/admin/schedules">
              <input type="hidden" name="_token" value="{$token}">
              <label>Name <input type="text" name="name" required placeholder="Coffee"></label>
              <label>Icon <input type="text" name="icon" maxlength="2" placeholder="☕"></label>
              <label>Colour <input type="color" name="colour" value="#38bdf8"></label>
              <p class="muted small">Several schedules sit side by side on one page, so the icon and
                 the colour are how a reader tells them apart at a glance.</p>
              <button class="btn" name="action" value="create">Create</button>
            </form>

            {$this->peopleList($data, $token)}
          </div>
        </div>
        HTML;
    }

    /**
     * Possible duplicates, for a person to decide about.
     *
     * On the front screen rather than behind a link: the moment it matters is
     * just after a sync, and somebody who has to go looking for it will not.
     * Hidden when there are none, like every other "waiting on you" list here.
     *
     * @param array<string, mixed> $data
     */
    private function suggestions(array $data, string $token): string
    {
        $suggestions = (array) ($data['suggestions'] ?? []);

        if ($suggestions === []) {
            return '';
        }

        $rows = '';
        foreach ($suggestions as $pair) {
            $rows .= sprintf(
                '<tr>
                   <td>%s</td><td>%s</td><td class="muted small">%d%%</td>
                   <td class="right">
                     <form method="post" action="/admin/schedules" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="keep" value="%d">
                       <input type="hidden" name="drop" value="%d">
                       <button name="action" value="merge" class="btn tiny secondary"
                               onclick="return confirm(\'Treat these as one person?\')">Keep the first</button>
                     </form>
                     <form method="post" action="/admin/schedules" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="keep" value="%d">
                       <input type="hidden" name="drop" value="%d">
                       <button name="action" value="merge" class="btn tiny secondary"
                               onclick="return confirm(\'Treat these as one person?\')">Keep the second</button>
                     </form>
                   </td>
                 </tr>',
                e((string) $pair['a']['name']),
                e((string) $pair['b']['name']),
                (int) $pair['score'],
                $token,
                (int) $pair['a']['id'],
                (int) $pair['b']['id'],
                $token,
                (int) $pair['b']['id'],
                (int) $pair['a']['id']
            );
        }

        return <<<HTML
        <h2>Might be the same person</h2>
        <p class="muted small">Offered, never done automatically. Two of these are usually one
           person and occasionally a father and son, and a merge cannot be undone — the two
           histories become one and nothing records which dates came from which name.</p>
        <table>
          <thead><tr><th>One</th><th>The other</th><th>Alike</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function peopleList(array $data, string $token): string
    {
        $accounts = '<option value="0">— nobody —</option>';
        foreach ((array) ($data['accounts'] ?? []) as $account) {
            $accounts .= sprintf(
                '<option value="%d">%s</option>',
                (int) $account['id'],
                e((string) $account['person_name'])
            );
        }

        $rows = '';
        foreach ((array) ($data['people'] ?? []) as $person) {
            $rows .= sprintf(
                '<tr>
                   <td>%s</td>
                   <td>%s</td>
                   <td class="right">
                     <form method="post" action="/admin/schedules" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="person" value="%d">
                       <select name="user">%s</select>
                       <button name="action" value="link" class="btn tiny secondary">Link</button>
                     </form>
                   </td>
                 </tr>',
                e((string) $person['name']),
                empty($person['account_name'])
                    ? '<span class="muted small">no account</span>'
                    : e((string) $person['account_name']),
                $token,
                (int) $person['id'],
                $accounts
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">Nobody on any rota yet.</td></tr>';
        }

        return <<<HTML
        <h2>People</h2>
        <p class="muted small"><strong>Linking a name to an account is what turns reminders on.</strong>
           Somebody with no account gets none — there is nowhere to send one, and that is a real
           limitation of a rota kept as names rather than a bug to be fixed.</p>
        <table>
          <thead><tr><th>Name</th><th>Account</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function schedule(array $data): string
    {
        $token = e((string) $data['token']);
        $schedule = (array) $data['schedule'];
        $id = (int) $schedule['id'];

        $rows = '';
        foreach ((array) ($data['entries'] ?? []) as $entry) {
            $rows .= sprintf(
                '<tr>
                   <td>%s</td>
                   <td>%s</td>
                   <td>%s</td>
                   <td class="muted small">%s</td>
                   <td class="right">
                     <form method="post" action="/admin/schedules" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="entry" value="%d">
                       <button name="action" value="remove-entry" class="btn tiny secondary">Remove</button>
                     </form>
                   </td>
                 </tr>',
                e($this->day((string) $entry['on_date'])),
                e((string) $entry['person_name']),
                e((string) ($entry['role'] ?? '')),
                $entry['source'] === 'sheet' ? 'from the spreadsheet' : 'typed here',
                $token,
                (int) $entry['id']
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted">Nothing on this one yet.</td></tr>';
        }

        return <<<HTML
        <p class="muted small"><a href="/admin/schedules">&larr; The calendar</a></p>
        <h1>{$this->text((string) $schedule['name'])}</h1>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>When</th><th>Who</th><th>Doing</th><th></th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>
          <div>
            <h2>Put somebody on</h2>
            <form method="post" action="/admin/schedules">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$id}">
              <label>Name <input type="text" name="person" required></label>
              <p class="muted small">Type the name as you would write it. Accents, capitals and
                 apostrophes do not matter — "José", "Jose" and "JOSE" are all the same person here,
                 so you will not make a second one by typing it differently.</p>
              <label>Date <input type="date" name="on_date" required></label>
              <label>Doing what <input type="text" name="role" placeholder="Coffee"></label>
              <label>Note <input type="text" name="note"></label>
              <button class="btn" name="action" value="add-entry">Add</button>
            </form>

            {$this->sheet($data, $token, $id)}
          </div>
        </div>
        HTML;
    }

    /**
     * The spreadsheet this schedule is fed from.
     *
     * @param array<string, mixed> $data
     */
    private function sheet(array $data, string $token, int $id): string
    {
        $source = $data['source'] ?? null;

        $url = is_array($source) ? e((string) $source['url']) : '';
        $layout = is_array($source) ? (string) $source['layout'] : 'rows';
        $order = is_array($source) ? (string) $source['date_order'] : 'auto';

        $selected = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';

        $buttons = is_array($source)
            ? sprintf(
                '<button class="btn secondary" name="action" value="preview">Check it and show me</button>
                 <button class="btn secondary" name="action" value="sync">Sync now</button>
                 <button class="btn tiny secondary" name="action" value="forget-source"
                         onclick="return confirm(\'Disconnect the spreadsheet? The dates stay.\')">Disconnect</button>'
            )
            : '';

        return <<<HTML
        <h2>From a spreadsheet</h2>

        <p class="muted small">Paste the address from the bar with the sheet open, and in Google
           Sheets set <strong>Share &rarr; Anyone with the link &rarr; Viewer</strong>. Nothing is
           written until you have looked at what it found.</p>

        <form method="post" action="/admin/schedules">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$id}">

          <label>Sheet address
            <input type="url" name="url" value="{$url}"
                   placeholder="https://docs.google.com/spreadsheets/d/...">
          </label>

          <label>How it is laid out
            <select name="layout">
              <option value="rows"{$selected('rows', $layout)}>A line per person per date</option>
              <option value="grid"{$selected('grid', $layout)}>A line per date, a column per job</option>
            </select>
          </label>

          <label>Dates written as
            <select name="date_order">
              <option value="auto"{$selected('auto', $order)}>Ask me if it is unclear</option>
              <option value="dmy"{$selected('dmy', $order)}>Day first — 5/9 is 5 September</option>
              <option value="mdy"{$selected('mdy', $order)}>Month first — 5/9 is 9 May</option>
            </select>
          </label>

          <p class="muted small">"5/9" is September to most of the world and May to the rest, and
             reading it the wrong way puts somebody on a date four months from the one they agreed
             to — nobody finds out until the day. So it is refused rather than guessed until you
             say. Dates like "13/9" and "5 Sep" need none of this.</p>

          <button class="btn" name="action" value="save-source">Save the sheet</button>
          {$buttons}
        </form>

        {$this->lastRun($source)}
        {$this->preview($data)}
        HTML;
    }

    /** What happened last time, in the words the other end used. */
    private function lastRun(mixed $source): string
    {
        if (!is_array($source) || empty($source['last_run_at'])) {
            return '';
        }

        $failed = ($source['last_status'] ?? '') === 'failed';

        return sprintf(
            '<p class="notice %s"><strong>%s</strong><br>%s<br><span class="muted small">%s</span></p>',
            $failed ? 'error' : 'ok',
            $failed ? 'The last sync did not work' : 'Last sync',
            e((string) ($source['last_message'] ?? '')),
            e($this->day((string) $source['last_run_at']))
        );
    }

    /**
     * What a sync WOULD do.
     *
     * The whole point of this panel is that it is shown before anything is
     * written — so the counts are what somebody is agreeing to, and the rows
     * are how they check the parser read their sheet the way they read it.
     *
     * @param array<string, mixed> $data
     */
    private function preview(array $data): string
    {
        $preview = $data['preview'] ?? null;

        if (!is_array($preview)) {
            return '';
        }

        if (($preview['status'] ?? '') !== 'ok') {
            return sprintf(
                '<p class="notice error"><strong>Could not read the sheet</strong><br>%s</p>',
                e((string) ($preview['message'] ?? ''))
            );
        }

        $plan = (array) ($preview['plan'] ?? []);

        $rows = '';
        foreach ((array) ($preview['rows'] ?? []) as $row) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                e($this->day((string) $row['date'])),
                e((string) $row['name']),
                e((string) $row['role'])
            );
        }

        $problems = '';
        foreach ((array) ($preview['problems'] ?? []) as $problem) {
            $problems .= '<li>' . e((string) $problem) . '</li>';
        }

        if ($problems !== '') {
            // Above the good news, because a sync that worked for most of a
            // sheet and silently dropped the rest is the one nobody looks into.
            $problems = '<div class="notice error"><strong>Rows this could not read</strong><ul>'
                . $problems . '</ul></div>';
        }

        $people = (array) ($plan['newPeople'] ?? []);
        $newPeople = $people === []
            ? ''
            : '<p class="muted small">New names: <strong>' . e(implode(', ', array_map(
                static fn ($name): string => (string) $name,
                $people
            ))) . '</strong>. Check none of them is somebody already on the calendar spelled
                 differently — the calendar will offer the pair afterwards, but it will not
                 decide.</p>';

        return <<<HTML
        {$problems}
        <div class="notice ok">
          <strong>Nothing has been written yet.</strong>
          <br>Would add {$plan['add']}, leave {$plan['keep']} alone, and remove
          {$plan['remove']} that are no longer on the sheet.
          <br><span class="muted small">Only rows this sheet put there, and only between
          {$this->text($this->day((string) ($plan['from'] ?? '')))} and
          {$this->text($this->day((string) ($plan['to'] ?? '')))} — dates outside what the
          sheet covers, and anything typed here by hand, are left alone.</span>
        </div>
        {$newPeople}
        <table>
          <thead><tr><th>When</th><th>Who</th><th>Doing</th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    private function day(string $date): string
    {
        $time = strtotime($date);

        return $time === false ? $date : date('D j M Y', $time);
    }

    private function text(string $value): string
    {
        return e($value);
    }
}
