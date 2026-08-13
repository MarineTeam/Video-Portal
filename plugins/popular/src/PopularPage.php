<?php

declare(strict_types=1);

namespace Portal\Plugins\Popular;

use Portal\Auth\Capability;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginContext;
use Portal\Plugins\PluginPage;
use Portal\Support\Audit;

/**
 * What the row is called, how far back it counts, and where it sits.
 */
final class PopularPage extends PluginPage
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

        return $this->page('Most watched', $this->body(), 'popular');
    }

    private function save(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        $this->plugin->setSetting('title', PopularPolicy::title($request->input('title')));
        $this->plugin->setSetting('days', PopularPolicy::days($request->input('days')));
        $this->plugin->setSetting('count', PopularPolicy::count($request->input('count')));
        $this->plugin->setSetting('position', PopularPolicy::position($request->input('position')));

        Audit::log($this->db(), $this->user()?->email, 'popular.settings');

        return $this->redirect($this->plugin->config()->url('/admin/popular'));
    }

    private function body(): string
    {
        $token = e($this->csrfToken());
        $title = e(PopularPolicy::title($this->plugin->setting('title', PopularPolicy::DEFAULT_TITLE)));
        $days = PopularPolicy::days($this->plugin->setting('days', PopularPolicy::DEFAULT_DAYS));
        $count = PopularPolicy::count($this->plugin->setting('count', PopularPolicy::DEFAULT_COUNT));
        $position = PopularPolicy::position($this->plugin->setting('position', PopularPolicy::FIRST));

        $first = $position === PopularPolicy::FIRST ? ' selected' : '';
        $last = $position === PopularPolicy::LAST ? ' selected' : '';

        $minimum = PopularPolicy::MIN_VIDEOS;
        $maxDays = PopularPolicy::MAX_DAYS;
        $maxCount = PopularPolicy::MAX_COUNT;

        return <<<HTML
        <h1>Most watched</h1>

        <p class="muted">A row on the front page, ordered by the view counts on the Analytics
           screen. It is not shown at all on page two or over search results — both of those are
           requests for a particular list.</p>

        <form method="post">
          <input type="hidden" name="_token" value="{$token}">

          <fieldset>
            <legend>The row</legend>
            <label>Heading
              <input type="text" name="title" value="{$title}" maxlength="60">
            </label>

            <label>Count views from the last
              <input type="number" name="days" value="{$days}" min="1" max="{$maxDays}"> days
            </label>
            <p class="muted small">A short window follows what people are watching now; a long one
               settles into the same handful of videos and stops being worth looking at.</p>

            <label>Show at most
              <input type="number" name="count" value="{$count}" min="{$minimum}" max="{$maxCount}"> videos
            </label>

            <label>Position
              <select name="position">
                <option value="first"{$first}>Above the other rows</option>
                <option value="last"{$last}>Below the other rows</option>
              </select>
            </label>
            <p class="muted small">On a site with no rows arranged on the Homepage screen this row
               sits above the ordinary library listing either way, and the listing stays where it
               is — adding a row here does not replace the front page.</p>
          </fieldset>

          <button class="btn">Save</button>
        </form>

        <h2>What it will and will not show</h2>
        <ul class="muted small">
          <li><strong>Fewer than {$minimum} videos and the row does not appear.</strong> A “most
              watched” list of one is not a ranking — it is the only thing anybody opened,
              presented as though a crowd had chosen it.</li>
          <li>Members-only videos are ranked but only offered to people who could play them, and
              unpublished, hidden and scheduled videos are left out for everybody. The row asks the
              same question the library asks, so it can never show more than the library would.</li>
          <li>Counts come from plays on this site. Views through a share link or an embedded player
              are counted by bunny.net and not here — the Provider statistics plugin is where that
              difference shows up.</li>
        </ul>
        HTML;
    }
}
