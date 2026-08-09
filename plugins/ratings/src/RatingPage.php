<?php

declare(strict_types=1);

namespace Portal\Plugins\Ratings;

use Portal\Auth\Capability;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginContext;
use Portal\Plugins\PluginPage;
use Portal\Support\Audit;

/**
 * What people rated, and how ratings behave.
 *
 * Two different questions on one screen, gated separately: reading the
 * leaderboard is analytics, and changing how ratings work is a setting. Somebody
 * given "view statistics" should be able to see what is popular without also
 * being able to switch the average off.
 */
final class RatingPage extends PluginPage
{
    public function __construct(private readonly PluginContext $plugin)
    {
        parent::__construct();
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $this->require(Capability::VIEW_ANALYTICS);

        if ($request->method === 'POST') {
            return $this->save($request);
        }

        return $this->page('Ratings', $this->body(), 'ratings');
    }

    private function save(Request $request): Response
    {
        $this->verifyCsrf($request);

        // Deliberately a second, stricter check rather than the page's own.
        $this->require(Capability::MANAGE_SETTINGS);

        $minimum = (int) ($request->input('minimum_votes') ?? 1);
        $minimum = max(1, min(100, $minimum));

        $allowChanges = $request->input('allow_changes') !== null;

        $this->plugin->setSetting('minimum_votes', $minimum);
        $this->plugin->setSetting('allow_changes', $allowChanges);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'ratings.settings',
            null,
            null,
            sprintf('minimum=%d changes=%s', $minimum, $allowChanges ? 'on' : 'off')
        );

        return $this->back($request, 'Settings saved.');
    }

    private function body(): string
    {
        $repository = new RatingRepository($this->db());
        $token = $this->csrfField();

        $rated = $repository->ratedVideoCount();
        $site = RatingPolicy::format($repository->siteAverage());

        $rows = '';
        foreach ($repository->leaderboard(25) as $row) {
            $rows .= sprintf(
                '<tr>
                   <td><a href="/watch/%s">%s</a></td>
                   <td class="right">%s</td>
                   <td class="right">%d</td>
                   <td class="right muted">%s</td>
                 </tr>',
                e((string) $row['slug']),
                e((string) $row['title']),
                e(RatingPolicy::format((float) $row['average'])),
                (int) $row['vote_count'],
                e(RatingPolicy::format((float) $row['ranking']))
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">Nothing has been rated yet.</td></tr>';
        }

        $minimum = (int) $this->plugin->setting('minimum_votes', 1);
        $allowChanges = (bool) $this->plugin->setting('allow_changes', true);

        $checked = $allowChanges ? ' checked' : '';
        $prior = RatingPolicy::PRIOR_VOTES;

        return <<<HTML
        <h1>Ratings</h1>
        <p class="muted">{$rated} video(s) rated, averaging {$site} across the site.</p>

        <table>
          <thead>
            <tr>
              <th>Video</th>
              <th class="right">Average</th>
              <th class="right">Ratings</th>
              <th class="right">Ranked</th>
            </tr>
          </thead>
          <tbody>{$rows}</tbody>
        </table>

        <p class="muted small">The <strong>ranked</strong> column is what the order above uses. It is
           the average pulled toward the site's own average as though every video already had
           {$prior} ratings at that number, so one enthusiastic five-star rating cannot outrank
           fifty ratings averaging 4.8. With enough ratings the two columns converge.</p>

        <form method="post">
          {$token}
          <fieldset>
            <legend>How ratings behave</legend>

            <label>Show the average once a video has
              <input type="number" name="minimum_votes" value="{$minimum}" min="1" max="100" size="3">
              rating(s)</label>
            <p class="muted small">Below this the count is still shown. A single five-star rating
               displayed as "5.0 out of 5" reads like a verdict rather than one person's opinion.</p>

            <label><input type="checkbox" name="allow_changes" value="1"{$checked}>
              Let people change or withdraw their rating</label>
            <p class="muted small">On by default. Someone who rates a video before watching it
               properly has no other way to correct themselves, and a rating they cannot change is
               one they will hesitate to give.</p>

            <button class="btn" type="submit">Save</button>
          </fieldset>
        </form>
        HTML;
    }
}
