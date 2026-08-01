<?php

declare(strict_types=1);

namespace Portal\Plugins\Geo;

use Portal\Auth\Capability;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginContext;
use Portal\Plugins\PluginPage;
use Portal\Support\Audit;

/**
 * The country restrictions screen.
 *
 * Two toggles and a lot of read-only text. That imbalance is the point: the
 * only things safe to change from a web page are the switches that turn the
 * feature OFF, and everything that could lock someone out is shown here but
 * edited in config.php.
 */
final class GeoPage extends PluginPage
{
    public function __construct(private readonly PluginContext $plugin)
    {
        parent::__construct();
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        if ($request->method === 'POST') {
            return $this->save($request);
        }

        return $this->page('Countries', $this->body($request), 'geo');
    }

    private function save(Request $request): Response
    {
        $this->verifyCsrf($request);

        $viewers = $request->input('geo_enabled') !== null;
        $admin = $request->input('admin_geo_enabled') !== null;

        $this->config()->setSettings([
            'geo_enabled'       => $viewers ? '1' : '0',
            'admin_geo_enabled' => $admin ? '1' : '0',
        ]);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'geo.settings',
            'plugin',
            'geo',
            sprintf('viewers=%s admin=%s', $viewers ? 'on' : 'off', $admin ? 'on' : 'off')
        );

        // Warn rather than refuse. An admin may reasonably switch the toggle on
        // first and edit config.php next, and blocking that order would mean
        // the two halves of the setting have to be changed in one specific
        // sequence for no reason the screen ever explained.
        $warning = '';
        if ($admin && $this->config()->csv('admin_geo_whitelist') === []) {
            $warning = ' Note: admin_geo_whitelist is empty in config.php, so nothing is restricted yet.';
        }

        return $this->back($request, 'Country restrictions saved.' . $warning);
    }

    private function body(Request $request): string
    {
        $config = $this->config();

        $viewerCountries = $config->csv('geo_whitelist');
        $adminCountries = $config->csv('admin_geo_whitelist');
        $bypass = $config->csv('admin_geo_bypass_emails');

        $viewersOn = $config->settingBool('geo_enabled', false);
        $adminOn = $config->settingBool('admin_geo_enabled', false);

        $token = $this->csrfField();
        $viewersChecked = $viewersOn ? ' checked' : '';
        $adminChecked = $adminOn ? ' checked' : '';

        $detected = $request->country();
        $diagnostic = $this->diagnostic($detected, $viewersOn || $adminOn);

        $viewerList = $this->list($viewerCountries, 'every country');
        $adminList = $this->list($adminCountries, 'every country');
        $bypassList = $this->list($bypass, 'nobody');

        return <<<HTML
        <h1>Countries</h1>

        <p class="muted">Restricts who can reach this site, and the admin area, by the country the
           request appears to come from.</p>

        {$diagnostic}

        <form method="post">
          {$token}
          <fieldset>
            <legend>What is restricted</legend>

            <label class="checkbox">
              <input type="checkbox" name="geo_enabled" value="1"{$viewersChecked}>
              Restrict the public site and share links
            </label>
            <p class="muted small">Applies to everything except sign-in, static files, and the admin area.</p>

            <label class="checkbox">
              <input type="checkbox" name="admin_geo_enabled" value="1"{$adminChecked}>
              Restrict the admin area
            </label>
            <p class="muted small">The admin area is governed by its own list only. Restricting the
               public site can never, on its own, lock you out of this screen.</p>

            <button class="btn" type="submit">Save</button>
          </fieldset>
        </form>

        <fieldset>
          <legend>The lists — edit in config.php</legend>

          <p class="muted small">These are deliberately not editable here. Whitelisting the wrong country
             would lock you out of the very screen that did it, and on a host with no shell access there
             would be no way back. Keeping them in <code>config.php</code> means the fix is always an
             FTP edit away.</p>

          <table>
            <tr><th>geo_whitelist</th><td>{$viewerList}</td></tr>
            <tr><th>admin_geo_whitelist</th><td>{$adminList}</td></tr>
            <tr><th>admin_geo_bypass_emails</th><td>{$bypassList}</td></tr>
          </table>

          <p class="muted small">Two-letter country codes, comma-separated — <code>US, CA, GB</code>.
             Bypass addresses are never blocked anywhere; write <code>@example.com</code> for a whole
             domain.</p>
        </fieldset>

        <fieldset>
          <legend>When a request is allowed anyway</legend>
          <ul class="muted small">
            <li>The matching list is <strong>empty</strong>. An empty list means no restriction, never
                "block everyone".</li>
            <li>The country is <strong>unknown</strong>. Most shared hosts send no country header, and
                refusing everything they cannot identify would block all traffic.</li>
            <li>The address is on the <strong>bypass</strong> list.</li>
            <li>The path is <code>/auth/…</code>, <code>/cron</code>, or a static asset. Sign-in stays
                open because the bypass list works on an email address, and we only learn one once
                somebody has signed in.</li>
          </ul>
        </fieldset>
        HTML;
    }

    /**
     * The one thing on this screen worth reading first.
     *
     * A site owner turning this on has no way to know whether their host sends
     * a country header, and without one the feature silently does nothing at
     * all. Showing what this very request looked like answers that immediately,
     * instead of after a support thread.
     */
    private function diagnostic(string $detected, bool $anyEnabled): string
    {
        if ($detected === '') {
            $class = $anyEnabled ? 'flash error' : 'flash';
            $note = $anyEnabled
                ? 'Restrictions are switched on, but they will allow everyone through.'
                : 'Switching restrictions on would have no effect.';

            return <<<HTML
            <div class="{$class}">
              <strong>This server does not report visitor countries.</strong>
              Your request arrived with no country header, so every request looks the same to this
              plugin. {$note} Country headers usually come from a CDN in front of the site — Cloudflare
              sends <code>CF-IPCountry</code> — or from a host that adds one.
            </div>
            HTML;
        }

        $country = e($detected);

        return <<<HTML
        <div class="flash">
          <strong>This request came from {$country}.</strong>
          Make sure that code is in any list you switch on, or you will not be able to get back here.
        </div>
        HTML;
    }

    /** @param list<string> $values */
    private function list(array $values, string $emptyMeaning): string
    {
        if ($values === []) {
            return '<span class="muted">empty — allows ' . e($emptyMeaning) . '</span>';
        }

        return '<code>' . e(implode(', ', $values)) . '</code>';
    }
}
