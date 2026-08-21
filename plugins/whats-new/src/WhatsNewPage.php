<?php

declare(strict_types=1);

namespace Portal\Plugins\WhatsNew;

use Portal\Auth\Capability;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginContext;
use Portal\Plugins\PluginPage;
use Portal\Support\Audit;

/**
 * What the badge says, and how far back it looks.
 */
final class WhatsNewPage extends PluginPage
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

        return $this->page("What's new", $this->body(), 'whats-new');
    }

    private function save(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        // Both fields are read on every save and the form always submits both,
        // so there is no partial-POST path here of the kind the site settings
        // screen needs its _whole_form marker for.
        $this->plugin->setSetting('label', WhatsNewPolicy::label($request->input('label')));
        $this->plugin->setSetting('horizon_days', WhatsNewPolicy::horizon($request->input('horizon_days')));

        Audit::log($this->db(), $this->user()?->email, 'whats_new.settings');

        return $this->redirect($this->plugin->config()->url('/admin/whats-new'));
    }

    private function body(): string
    {
        $token = e($this->csrfToken());
        $label = e(WhatsNewPolicy::label($this->plugin->setting('label', WhatsNewPolicy::DEFAULT_LABEL)));
        $horizon = WhatsNewPolicy::horizon($this->plugin->setting('horizon_days', WhatsNewPolicy::DEFAULT_HORIZON_DAYS));
        $max = WhatsNewPolicy::MAX_HORIZON_DAYS;
        $gap = (int) round(WhatsNewPolicy::SESSION_GAP / 60);

        return <<<HTML
        <h1>What's new</h1>

        <p class="muted">Videos published since somebody was last here get a badge on their card,
           everywhere cards appear. Nothing is stored about what anyone watched — only when they
           were last on the site.</p>

        <form method="post">
          <input type="hidden" name="_token" value="{$token}">

          <fieldset>
            <legend>The badge</legend>
            <label>Wording
              <input type="text" name="label" value="{$label}" maxlength="24">
            </label>
            <p class="muted small">Kept short on purpose — it sits in the corner of a thumbnail.</p>

            <label>Look back at most
              <input type="number" name="horizon_days" value="{$horizon}" min="1" max="{$max}"> days
            </label>
            <p class="muted small"><strong>This is the setting that matters.</strong> Somebody
               returning after a year has a perfectly valid last visit, and honouring it literally
               would badge every video published since — which is the whole library, on every card,
               telling them nothing. Past this many days they are shown what is new recently
               instead of everything they missed.</p>
          </fieldset>

          <button class="btn">Save</button>
        </form>

        <h2>What counts as a visit</h2>
        <ul class="muted small">
          <li>Leaving for more than {$gap} minutes and coming back starts a new visit. Until then
              the badges stay put, so they do not disappear from under somebody who is still
              reading the page they arrived on.</li>
          <li><strong>Signed-in people only.</strong> The only thing that identifies an anonymous
              visitor is a cookie, and a marker built on one resets whenever the cookie is cleared —
              which would badge the whole library at random rather than never.</li>
          <li>Nothing is badged on a first visit. Everything is new then, and a page where every
              card says “{$label}” says nothing at all.</li>
        </ul>

        <p class="muted small">Deactivating this plugin removes the badges and keeps the markers, so
           turning it back on carries on where it left off. Uninstalling drops them.</p>
        HTML;
    }
}
