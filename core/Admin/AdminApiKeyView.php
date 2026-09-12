<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Api\ApiKeys;
use Portal\Api\Scope;

/**
 * Keys for the read API.
 *
 * The screen has one job beyond the two buttons: making it obvious which
 * choices hand over information about identifiable people. Two of the six
 * scopes do, and a person choosing them from a list of six equal-looking
 * checkboxes has no way to know that.
 */
final class AdminApiKeyView
{
    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $token = e((string) $data['token']);

        $body = <<<HTML
        <h1>API keys</h1>

        <p class="muted">A key lets another system read from this one — a website, a mobile app, a
           screen in the foyer. Everything the API offers is read-only: there is no key that can
           change anything here.</p>

        {$this->made($data)}
        {$this->create($data, $token)}
        {$this->listing($data, $token)}
        {$this->about($data)}
        HTML;

        return (new AdminView())->shell($body, $data);
    }

    /**
     * The one and only time the key is shown.
     *
     * Rendered from the POST rather than after a redirect, because a redirect
     * would mean putting the plaintext in the session — see the controller.
     * The warning about refreshing is not a courtesy: without it somebody who
     * misses the key reloads the page to get it back, which makes another one.
     *
     * @param array<string, mixed> $data
     */
    private function made(array $data): string
    {
        $key = (string) ($data['made'] ?? '');

        if ($key === '') {
            return '';
        }

        return sprintf(
            '<div class="notice ok">
               <strong>Here is the key. This is the only time it is shown.</strong>
               <p class="muted small">Only a hash of it is stored, so nothing on this site can tell
                  you what it is again — not this screen, not the database, not support. Copy it
                  into whatever is going to use it now.</p>
               <input type="text" class="urlbox" value="%s" readonly onclick="this.select()">
               <p class="muted small">Do not reload this page to see it again: your browser would
                  send the form a second time and you would get a second key. If you have lost it,
                  revoke this one and make another.</p>
             </div>',
            e($key)
        );
    }

    /** @param array<string, mixed> $data */
    private function create(array $data, string $token): string
    {
        $boxes = '';

        /** @var array<string, array{gives: string, personal: bool}> $scopes */
        $scopes = (array) ($data['scopes'] ?? []);

        foreach ($scopes as $scope => $about) {
            /*
             * The personal-data ones are marked, and the mark says what kind of
             * data rather than just "sensitive". "Names and phone numbers" is a
             * thing somebody can weigh; a yellow badge is not.
             */
            $mark = ($about['personal'] ?? false) === true
                ? ' <span class="pill warn">personal data</span>'
                : '';

            $boxes .= sprintf(
                '<label class="checkbox">
                   <input type="checkbox" name="scopes[]" value="%s">
                   <code>%s</code>%s
                 </label>
                 <p class="muted small">%s</p>',
                e((string) $scope),
                e((string) $scope),
                $mark,
                e((string) ($about['gives'] ?? ''))
            );
        }

        return sprintf(
            '<h2>Make a key</h2>
             <form method="post" action="/admin/api-keys">
               <input type="hidden" name="_token" value="%s">
               <label>What is it for
                 <input type="text" name="name" maxlength="190" required
                        placeholder="Foyer screen, nightly sync, the new website">
               </label>
               <p class="muted small">The name is the only way to tell two keys apart when you come
                  to revoke one, so name it after the thing that will hold it.</p>

               <fieldset>
                 <legend>What may it read?</legend>
                 <p class="muted small">Nothing is ticked to begin with, and there is no "everything"
                    button. Tick only what the thing using this key actually needs — these choices
                    do not imply one another, so <code>%s</code> gives how full an event is and
                    never who is on the list.</p>
                 %s
               </fieldset>

               <button name="action" value="create" class="btn">Make the key</button>
             </form>',
            $token,
            e(Scope::EVENTS),
            $boxes
        );
    }

    /** @param array<string, mixed> $data */
    private function listing(array $data, string $token): string
    {
        $keys = (array) ($data['keys'] ?? []);

        if ($keys === []) {
            return '<h2>Keys</h2><p class="muted">No keys yet.</p>';
        }

        $rows = '';

        foreach ($keys as $key) {
            $revoked = ($key['revoked_at'] ?? null) !== null;
            $scopes = ApiKeys::scopesOf((array) $key);

            $tags = '';
            foreach ($scopes as $scope) {
                $tags .= sprintf('<code>%s</code> ', e($scope));
            }

            if ($tags === '') {
                // Should not happen — issue() refuses a scopeless key — but a
                // row saying nothing is worse than a row saying it is useless.
                $tags = '<span class="muted small">nothing</span>';
            }

            $rows .= sprintf(
                '<tr>
                   <td>
                     <strong>%s</strong>%s
                     <div class="muted small"><code>%s…</code></div>
                     <div class="small">%s</div>
                   </td>
                   <td class="muted small">%s%s</td>
                   <td class="muted small">%s</td>
                   <td class="right">%s</td>
                 </tr>',
                e((string) ($key['name'] ?? '')),
                $revoked ? ' <span class="pill">revoked</span>' : '',
                e((string) ($key['key_prefix'] ?? '')),
                $tags,
                e((string) ($key['created_at'] ?? '')),
                ($key['created_by'] ?? null) !== null
                    ? '<br>by ' . e((string) $key['created_by'])
                    : '',
                $this->lastUsed($key),
                $revoked
                    ? sprintf('<span class="muted small">%s</span>', e((string) $key['revoked_at']))
                    : $this->revokeButton($token, (int) ($key['id'] ?? 0))
            );
        }

        return sprintf(
            '<h2>Keys</h2>
             <table>
               <thead><tr><th>Key</th><th>Made</th><th>Last used</th><th></th></tr></thead>
               <tbody>%s</tbody>
             </table>
             <p class="muted small">A revoked key stays on this list. It stops working immediately,
                and the row is what tells you afterwards who made it and when it was last used —
                which is the question somebody asks in the middle of an incident, and deleting the
                row would answer it with nothing.</p>',
            $rows
        );
    }

    /**
     * "Is anybody still relying on this?" — the question before revoking one.
     *
     * A key that has never been used reads differently from one used an hour
     * ago, and "—" for both would hide the difference.
     *
     * @param array<string, mixed> $key
     */
    private function lastUsed(array $key): string
    {
        $uses = (int) ($key['uses'] ?? 0);

        if (($key['last_used_at'] ?? null) === null) {
            return 'never used';
        }

        return sprintf(
            '%s<br>%s request%s',
            e((string) $key['last_used_at']),
            number_format($uses),
            $uses === 1 ? '' : 's'
        );
    }

    private function revokeButton(string $token, int $id): string
    {
        return sprintf(
            '<form method="post" action="/admin/api-keys" class="inline">
               <input type="hidden" name="_token" value="%s">
               <input type="hidden" name="id" value="%d">
               <button name="action" value="revoke" class="btn tiny secondary">Revoke</button>
             </form>',
            $token,
            $id
        );
    }

    /** @param array<string, mixed> $data */
    private function about(array $data): string
    {
        $base = e((string) ($data['baseUrl'] ?? '')) . '/api/v1';

        return sprintf(
            '<h2>How another system uses one</h2>
             <pre>curl -H "Authorization: Bearer vpk_…" %s/videos</pre>
             <p class="muted small">Or <code>X-Api-Key</code>, if whatever you are using cannot set
                an Authorization header. <a href="%s">%s</a> lists every endpoint and what each
                scope gives, and needs no key — so you can send it to whoever is doing the
                integration before you have given them anything.</p>

             <div class="notice">
               <strong>A small group\'s address is not available at any level.</strong>
               <p class="muted small">There is no scope that returns one and no setting that turns
                  it on. An address is given to people who are actually in the group, by this site,
                  and an integration reading the directory gets the area and the size and nothing
                  else.</p>
             </div>',
            $base,
            $base,
            $base
        );
    }
}
