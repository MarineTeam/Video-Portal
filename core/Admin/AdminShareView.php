<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Content\Video;
use Portal\Sharing\Bundle;
use Portal\Sharing\Share;
use Portal\Support\Str;

/**
 * The sharing admin screens.
 *
 * Shares the shell and stylesheet of AdminView, so the two look like one
 * application rather than two that happen to be adjacent.
 */
final class AdminShareView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'shares'        => $this->shares($data),
            'private-list'  => $this->privateList($data),
            'viewer-groups' => $this->viewerGroups($data),
            default         => '<p>Unknown screen.</p>',
        };

        return (new AdminView())->shell($body, $data);
    }

    // ---------------------------------------------------------------- shares

    /** @param array<string, mixed> $data */
    private function shares(array $data): string
    {
        $token = e((string) $data['token']);
        $baseUrl = rtrim((string) $data['baseUrl'], '/');

        /** @var list<Share> $shares */
        $shares = $data['shares'] ?? [];

        $rows = '';
        foreach ($shares as $share) {
            $rows .= $this->shareRow($share, $token, $baseUrl);
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted">No share links yet.</td></tr>';
        }

        $mailWarning = ($data['mailReady'] ?? false)
            ? ''
            : '<div class="flash error">No email service is configured, so nothing will be sent. '
              . 'Links can still be created and copied by hand.</div>';

        $cleanup = ((int) ($data['purgeable'] ?? 0)) > 0
            ? sprintf(
                '<form method="post" action="/admin/shares/cleanup" class="inline">
                   <input type="hidden" name="_token" value="%s">
                   <button class="btn secondary tiny">Remove %d old link(s)</button>
                 </form>',
                $token,
                (int) $data['purgeable']
            )
            : '';

        return $mailWarning
            . $this->createForm($data, $token)
            . $this->shareTable($data, $rows, $token, $cleanup)
            . $this->bundleList($data, $baseUrl);
    }

    /** @param array<string, mixed> $data */
    private function createForm(array $data, string $token): string
    {
        $videoOptions = '';
        foreach ((array) ($data['videos'] ?? []) as $video) {
            $videoOptions .= sprintf(
                '<option value="%d">%s</option>',
                (int) $video['id'],
                e((string) $video['title'])
            );
        }

        $groupOptions = '';
        foreach ((array) ($data['groups'] ?? []) as $group) {
            $groupOptions .= sprintf(
                '<option value="%d">%s (%d)</option>',
                (int) $group['id'],
                e((string) $group['name']),
                (int) $group['memberCount']
            );
        }

        $tagOptions = '';
        foreach ((array) ($data['tags'] ?? []) as $tag) {
            $tagOptions .= sprintf(
                '<option value="%s">%s (%d)</option>',
                e((string) $tag['tag']),
                e((string) $tag['tag']),
                (int) $tag['count']
            );
        }

        // One form for every case. Separate "share" and "bulk share" screens
        // would be two implementations of the same decision.
        return <<<HTML
        <h1>Share links</h1>

        <fieldset>
          <legend>Create links</legend>
          <form method="post" action="/admin/shares/create">
            <input type="hidden" name="_token" value="{$token}">

            <div class="cols">
              <div>
                <label>Videos
                  <select name="videos[]" multiple size="8" required>{$videoOptions}</select>
                  <span class="muted small">Hold Ctrl or Cmd to pick several. Every video-and-person
                    pair gets its own link, so revoking one affects nobody else.</span>
                </label>
              </div>

              <div>
                <label>Email addresses
                  <textarea name="emails" rows="4"
                    placeholder="one per line, or separated by commas"></textarea>
                </label>

                <label>Or a group
                  <select name="groups[]" multiple size="3">{$groupOptions}</select>
                </label>

                <label>Or a tag
                  <select name="tags[]" multiple size="3">{$tagOptions}</select>
                </label>
              </div>
            </div>

            <div class="cols">
              <div>
                <label>How they prove who they are
                  <select name="access_mode">
                    <option value="account">Sign in with an account</option>
                    <option value="gate">Confirm by email — no account needed</option>
                  </select>
                  <span class="muted small">Choose the second for people outside your organisation.</span>
                </label>
              </div>
              <div>
                <label>Expires after
                  <select name="hours">
                    <option value="24">24 hours</option>
                    <option value="72" selected>3 days</option>
                    <option value="168">7 days</option>
                    <option value="720">30 days</option>
                  </select>
                </label>
              </div>
              <div>
                <label>Watermark
                  <select name="watermark">
                    <option value="default">Use the site setting</option>
                    <option value="on">Always</option>
                    <option value="off">Never</option>
                  </select>
                </label>
              </div>
            </div>

            <label class="checkbox">
              <input type="checkbox" name="notify" value="1" checked> Email them a link
            </label>

            <button class="btn">Create links</button>
          </form>
        </fieldset>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function shareTable(array $data, string $rows, string $token, string $cleanup): string
    {
        $total = (int) ($data['total'] ?? 0);
        $search = e((string) ($data['search'] ?? ''));
        $status = (string) ($data['status'] ?? 'all');

        $options = '';
        foreach (['all' => 'All', 'live' => 'Live', 'expired' => 'Expired', 'revoked' => 'Revoked'] as $key => $label) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                $key,
                $key === $status ? ' selected' : '',
                $label
            );
        }

        // The bulk bar posts the same ids to whichever button is pressed, so
        // one selection can be revoked, extended, or resent without re-picking.
        return <<<HTML
        <h2>Existing links <span class="muted">({$total})</span></h2>

        <form method="get" class="toolbar">
          <input type="search" name="q" value="{$search}" placeholder="Search recipients or titles…">
          <select name="status">{$options}</select>
          <button class="btn secondary">Filter</button>
          {$cleanup}
        </form>

        <form method="post" action="/admin/shares/act">
          <input type="hidden" name="_token" value="{$token}">

          <table>
            <thead>
              <tr>
                <th style="width:1.5rem"></th>
                <th>Recipient and video</th>
                <th>Status</th>
                <th>Activity</th>
                <th></th>
              </tr>
            </thead>
            <tbody>{$rows}</tbody>
          </table>

          <div class="actions">
            <span class="muted small">With selected:</span>
            <button class="btn tiny" name="action" value="resend">Email</button>
            <button class="btn tiny" name="action" value="extend">Extend</button>
            <select name="hours" style="width:auto">
              <option value="24">24 hours</option>
              <option value="72" selected>3 days</option>
              <option value="168">7 days</option>
              <option value="720">30 days</option>
            </select>
            <button class="btn tiny" name="action" value="restore">Restore</button>
            <button class="btn tiny danger" name="action" value="revoke">Revoke</button>
            <button class="btn tiny danger" name="action" value="delete"
                    onclick="return confirm('Delete permanently? This cannot be undone.')">Delete</button>
          </div>
        </form>
        HTML;
    }

    private function shareRow(Share $share, string $token, string $baseUrl): string
    {
        $url = $baseUrl . $share->url();

        // Revoked and expired are shown DIFFERENTLY here, unlike to a
        // recipient: an admin needs to know which it was to decide what to do.
        if ($share->isRevoked()) {
            $status = '<span class="pill bad">Revoked</span>';
        } elseif ($share->hasExpired()) {
            $status = '<span class="pill warn">Expired</span>';
        } else {
            $status = '<span class="pill ok">Live ' . e(Str::relativeTo($share->expiresAt)) . '</span>';
        }

        $marks = '';
        if ($share->viaPrivateList) {
            $marks .= ' <span class="pill">list</span>';
        }
        if ($share->bundleId !== null) {
            $marks .= ' <span class="pill">bundled</span>';
        }
        if ($share->accessMode === Share::MODE_GATE) {
            $marks .= ' <span class="pill">no account</span>';
        }

        if ($share->emailError !== null) {
            // The provider's own words, on hover. "Failed" alone tells an
            // admin nothing they can fix.
            $marks .= sprintf(
                ' <span class="pill bad" title="%s">email failed</span>',
                e($share->emailError)
            );
        } elseif ($share->emailedAt !== null) {
            $marks .= sprintf(
                ' <span class="pill" title="%s">emailed</span>',
                e($share->emailedAt->format('j M Y, H:i') . ' UTC')
            );
        }

        $activity = $share->viewCount === 0
            ? '<span class="muted">not opened</span>'
            : sprintf(
                '%d view%s%s',
                $share->viewCount,
                $share->viewCount === 1 ? '' : 's',
                $share->playCount > 0
                    ? sprintf(
                        ', %d play%s, %d%%%s',
                        $share->playCount,
                        $share->playCount === 1 ? '' : 's',
                        $share->furthestPercent,
                        $share->completedAt !== null ? ' ✓' : ''
                    )
                    : ''
            );

        $toggle = $share->isRevoked() ? 'restore' : 'revoke';
        $toggleLabel = $share->isRevoked() ? 'Restore' : 'Revoke';

        return sprintf(
            '<tr>
               <td><input type="checkbox" name="ids[]" value="%s"></td>
               <td>
                 <strong>%s</strong>%s<br>
                 <span class="muted">%s</span><br>
                 <input type="text" class="urlbox" value="%s" readonly onclick="this.select()">
               </td>
               <td>%s</td>
               <td class="muted small">%s</td>
               <td class="right">
                 <button class="btn tiny" name="action" value="%s" form="row-%s">%s</button>
               </td>
             </tr>
             <form method="post" action="/admin/shares/act" id="row-%s">
               <input type="hidden" name="_token" value="%s">
               <input type="hidden" name="id" value="%s">
             </form>',
            e($share->id),
            e($share->recipientEmail),
            $marks,
            e($share->videoTitle),
            e($url),
            $status,
            $activity,
            $toggle,
            e($share->id),
            $toggleLabel,
            e($share->id),
            $token,
            e($share->id)
        );
    }

    /** @param array<string, mixed> $data */
    private function bundleList(array $data, string $baseUrl): string
    {
        $rows = '';

        foreach ((array) ($data['bundles'] ?? []) as $entry) {
            /** @var Bundle $bundle */
            $bundle = $entry['bundle'];

            $rows .= sprintf(
                '<tr>
                   <td><strong>%s</strong><br><span class="muted">%d video(s)</span></td>
                   <td><input type="text" class="urlbox" value="%s" readonly onclick="this.select()"></td>
                 </tr>',
                e($bundle->recipientEmail),
                (int) $entry['liveCount'],
                e($baseUrl . $bundle->url())
            );
        }

        if ($rows === '') {
            return '';
        }

        return <<<HTML
        <h2>Bundle pages</h2>
        <p class="muted">Anyone with several links gets one page listing them all. These addresses stay
           the same as links are added or revoked.</p>
        <table>
          <thead><tr><th>Recipient</th><th>Link</th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    // --------------------------------------------------------- private list

    /** @param array<string, mixed> $data */
    private function privateList(array $data): string
    {
        /** @var Video $video */
        $video = $data['video'];
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['members'] ?? []) as $member) {
            /** @var Share|null $share */
            $share = $member['share'];

            $status = match (true) {
                $share === null           => '<span class="pill">no link</span>',
                $share->isRevoked()       => '<span class="pill bad">revoked</span>',
                $share->hasExpired()      => '<span class="pill warn">expired</span>',
                default                   => '<span class="pill ok">' . e(Str::relativeTo($share->expiresAt)) . '</span>',
            };

            $rows .= sprintf(
                '<tr>
                   <td><strong>%s</strong></td>
                   <td>%s</td>
                   <td class="right">
                     <form method="post" action="/admin/shares/private-list" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="video" value="%d">
                       <input type="hidden" name="email" value="%s">
                       <button name="action" value="remove" class="btn tiny danger">Remove</button>
                     </form>
                   </td>
                 </tr>',
                e((string) $member['email']),
                $status,
                $token,
                $video->id,
                e((string) $member['email'])
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">Nobody yet.</td></tr>';
        }

        $groupOptions = '';
        foreach ((array) ($data['groups'] ?? []) as $group) {
            $groupOptions .= sprintf('<option value="%d">%s</option>', (int) $group['id'], e((string) $group['name']));
        }

        $title = e($video->title);

        return <<<HTML
        <h1>Who can watch “{$title}”</h1>
        <p class="muted">A standing list for this video. Removing someone revokes the link this list gave
           them — and only that one. If they also received an ordinary share of the same video, it keeps
           working, because this list did not create it.</p>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Person</th><th>Access</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>

          <div>
            <fieldset>
              <legend>Add people</legend>
              <form method="post" action="/admin/shares/private-list">
                <input type="hidden" name="_token" value="{$token}">
                <input type="hidden" name="video" value="{$video->id}">

                <label>Email addresses
                  <textarea name="emails" rows="4" placeholder="one per line"></textarea>
                </label>

                <label>Or a group
                  <select name="groups[]" multiple size="3">{$groupOptions}</select>
                </label>

                <label>How they prove who they are
                  <select name="access_mode">
                    <option value="account">Sign in with an account</option>
                    <option value="gate">Confirm by email — no account needed</option>
                  </select>
                </label>

                <label class="checkbox">
                  <input type="checkbox" name="notify" value="1" checked> Email the new people
                </label>
                <span class="muted small">Anyone already on the list is left alone and not emailed again.</span>

                <button class="btn" name="action" value="add">Add</button>
              </form>
            </fieldset>
          </div>
        </div>
        HTML;
    }

    // -------------------------------------------------------- viewer groups

    /** @param array<string, mixed> $data */
    private function viewerGroups(array $data): string
    {
        $token = e((string) $data['token']);

        $cards = '';
        foreach ((array) ($data['groups'] ?? []) as $group) {
            $members = '';
            foreach ((array) $group['emails'] as $email) {
                $members .= sprintf(
                    '<li>%s
                       <form method="post" action="/admin/shares/groups" class="inline">
                         <input type="hidden" name="_token" value="%s">
                         <input type="hidden" name="group" value="%d">
                         <input type="hidden" name="email" value="%s">
                         <button name="action" value="remove" class="btn tiny danger">&times;</button>
                       </form>
                     </li>',
                    e((string) $email),
                    $token,
                    (int) $group['id'],
                    e((string) $email)
                );
            }

            $cards .= sprintf(
                '<div class="card">
                   <h3>%s <span class="muted">(%d)</span></h3>
                   <ul class="plain">%s</ul>
                   <form method="post" action="/admin/shares/groups">
                     <input type="hidden" name="_token" value="%s">
                     <input type="hidden" name="group" value="%d">
                     <label>Add addresses
                       <textarea name="emails" rows="2" placeholder="one per line"></textarea>
                     </label>
                     <button class="btn tiny" name="action" value="add">Add</button>
                     <button class="btn tiny danger" name="action" value="delete"
                             onclick="return confirm(\'Delete this group? Links already sent keep working.\')">
                       Delete group</button>
                   </form>
                 </div>',
                e((string) $group['name']),
                (int) $group['memberCount'],
                $members,
                $token,
                (int) $group['id']
            );
        }

        return <<<HTML
        <h1>Viewer groups</h1>
        <p class="muted">Named lists of addresses, for filling in a share form quickly. A group grants
           nothing on its own — adding someone gives them no access, and deleting a group takes none
           away. Links belong to the people they were sent to.</p>

        <fieldset>
          <legend>New group</legend>
          <form method="post" action="/admin/shares/groups" class="inline">
            <input type="hidden" name="_token" value="{$token}">
            <input type="text" name="name" placeholder="Group name" required>
            <button class="btn" name="action" value="create">Create</button>
          </form>
        </fieldset>

        <div class="cards">{$cards}</div>
        HTML;
    }
}
