<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Groups\GroupCard;

/**
 * Keeping the small-group directory.
 *
 * The address IS editable here, because somebody has to be able to enter it —
 * but it is read from the raw row for that one field only, and everything else
 * on the screen comes from GroupCard, which has no address on it.
 */
final class AdminGroupView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'groups' => $this->overview($data),
            'group'  => $this->group($data),
            default  => '<p>Unknown screen.</p>',
        };

        return (new AdminView())->shell($body, $data);
    }

    /** @param array<string, mixed> $data */
    private function overview(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['groups'] ?? []) as $group) {
            /** @var GroupCard $group */
            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/groups/%d"><strong>%s</strong></a>
                       <div class="muted small">%s</div></td>
                   <td>%s</td>
                   <td>%s</td>
                 </tr>',
                $group->id,
                e($group->name),
                e((string) ($group->area ?? 'no area given')),
                $group->capacity === null
                    ? sprintf('%d in it, no limit', $group->taken)
                    : sprintf('%d of %d', $group->taken, $group->capacity),
                $group->needsALeader()
                    ? '<span class="pill warn">nobody leading it</span>'
                    : e(implode(', ', $group->leaders))
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">No groups yet.</td></tr>';
        }

        return <<<HTML
        <h1>Small groups</h1>

        {$this->leaderless($data)}

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Group</th><th>Places</th><th>Led by</th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
            <p class="muted small">A place is taken by somebody in the group <em>and</em> by
               anybody who has asked and not been answered — an unanswered ask holds a place, and
               a "no" gives it back. Without that, one free place gets offered to everybody on the
               waiting list in turn.</p>
          </div>

          <div>
            <h2>Add a group</h2>
            <form method="post" action="/admin/groups">
              <input type="hidden" name="_token" value="{$token}">
              <label>Name <input type="text" name="name" required placeholder="Tuesday night"></label>
              <button class="btn" name="action" value="create">Create</button>
            </form>
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function leaderless(array $data): string
    {
        $groups = (array) ($data['leaderless'] ?? []);

        if ($groups === []) {
            return '';
        }

        $names = implode(', ', array_map(
            static fn (GroupCard $g): string => e($g->name),
            $groups
        ));

        return <<<HTML
        <div class="notice error">
          <strong>Nobody is leading these: {$names}</strong>
          <p class="muted small">They are still in the directory and still taking requests, and
             those requests are going to nobody.</p>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function group(array $data): string
    {
        $token = e((string) $data['token']);
        /** @var GroupCard $group */
        $group = $data['group'];
        $row = (array) $data['row'];
        $id = $group->id;

        $accounts = '<option value="0">— choose —</option>';
        foreach ((array) ($data['accounts'] ?? []) as $account) {
            $accounts .= sprintf(
                '<option value="%d">%s</option>',
                (int) $account['id'],
                e((string) $account['person_name'])
            );
        }

        return <<<HTML
        <p class="muted small"><a href="/admin/groups">&larr; Small groups</a></p>
        <h1>{$this->text($group->name)}</h1>
        <p class="muted small">Live at <a href="/groups/{$this->text($group->slug)}">/groups/{$this->text($group->slug)}</a></p>

        <div class="cols">
          <div>
            {$this->people($data, $token, $id)}
          </div>
          <div>
            <h2>Details</h2>
            <form method="post" action="/admin/groups">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$id}">
              <input type="hidden" name="_whole_form" value="1">

              <label>Name <input type="text" name="name" value="{$this->text($group->name)}"></label>
              <label>About
                <textarea name="description" rows="3">{$this->text((string) ($group->description ?? ''))}</textarea>
              </label>
              <label>When <input type="text" name="meets" value="{$this->text((string) ($group->meets ?? ''))}"
                     placeholder="Tuesdays, 7.30pm"></label>

              <label>Area <input type="text" name="area" value="{$this->text((string) ($group->area ?? ''))}"
                     placeholder="Northside"></label>
              <p class="muted small">Public. Roughly where, so somebody can find one near them.</p>

              <label>Address <input type="text" name="address"
                     value="{$this->text((string) ($row['address'] ?? ''))}"></label>
              <p class="muted small"><strong>Shown only to the people in the group.</strong> Not to
                 somebody who has asked, and not to somebody on the waiting list — otherwise anybody
                 with an account learns where a leader lives by pressing a button. It appears when
                 the leader says yes, and not before.</p>

              <label>How many people fit
                <input type="number" name="capacity" min="0"
                       value="{$this->capacity($group)}">
              </label>
              <p class="muted small">Leave it at 0 for no limit. Raising it asks whoever is waiting.</p>

              <label class="check">
                <input type="checkbox" name="is_published" value="1" {$this->checked($group->isPublished)}>
                In the directory
              </label>

              <button class="btn" name="action" value="save">Save</button>
            </form>

            <h2>Who leads it</h2>
            <form method="post" action="/admin/groups">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$id}">
              <select name="person">{$accounts}</select>
              <button class="btn secondary" name="action" value="leader">Make them a leader</button>
            </form>
            <p class="muted small">Leading a group is not a job on this site — it grants no admin
               screen and no permission anywhere else. It does put them in the group, and it does
               give them the address.</p>
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function people(array $data, string $token, int $id): string
    {
        $rows = '';

        foreach ((array) ($data['people'] ?? []) as $person) {
            $state = (string) $person['state'];
            $isLeader = (string) $person['role'] === 'leader' && $state === 'member';

            $rows .= sprintf(
                '<tr>
                   <td>%s%s<div class="muted small">%s</div>%s</td>
                   <td class="right">%s%s</td>
                 </tr>',
                e((string) $person['person_name']),
                $isLeader ? ' <span class="pill">leads it</span>' : '',
                e($this->describe($state)),
                empty($person['note']) ? '' : '<div class="muted small">' . e((string) $person['note']) . '</div>',
                $isLeader
                    ? $this->button($token, $id, (int) $person['user_id'], 'no-leader', 'Not a leader')
                    : ($state === 'member'
                        ? $this->button($token, $id, (int) $person['user_id'], 'leader', 'Make a leader')
                        : ''),
                in_array($state, ['member', 'requested', 'waiting'], true)
                    ? $this->button($token, $id, (int) $person['user_id'], 'remove', 'Take out')
                    : ''
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">Nobody yet.</td></tr>';
        }

        return <<<HTML
        <h2>Who is in it</h2>
        <table><tbody>{$rows}</tbody></table>
        <p class="muted small">Requests are answered by whoever leads the group, on the group's own
           page — not here. This screen is for making groups and appointing leaders.</p>
        HTML;
    }

    private function describe(string $state): string
    {
        return match ($state) {
            'member'    => 'in the group',
            'requested' => 'has asked — holds a place until it is answered',
            'waiting'   => 'on the list',
            'declined'  => 'was told no',
            'left'      => 'left',
            default     => $state,
        };
    }

    private function button(string $token, int $groupId, int $userId, string $action, string $label): string
    {
        return sprintf(
            '<form method="post" action="/admin/groups" class="inline">
               <input type="hidden" name="_token" value="%s">
               <input type="hidden" name="id" value="%d">
               <input type="hidden" name="person" value="%d">
               <button name="action" value="%s" class="btn tiny secondary">%s</button>
             </form>',
            $token,
            $groupId,
            $userId,
            e($action),
            e($label)
        );
    }

    private function capacity(GroupCard $group): string
    {
        return (string) ($group->capacity ?? 0);
    }

    private function checked(bool $on): string
    {
        return $on ? 'checked' : '';
    }

    private function text(string $value): string
    {
        return e($value);
    }
}
