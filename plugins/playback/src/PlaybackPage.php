<?php

declare(strict_types=1);

namespace Portal\Plugins\Playback;

use Portal\Auth\Capability;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginContext;
use Portal\Plugins\PluginPage;
use Portal\Support\Audit;

/**
 * How playback behaves around the video.
 *
 * Both features are switchable independently, which is the reason they are one
 * plugin rather than two: they share the plumbing and nothing else.
 */
final class PlaybackPage extends PluginPage
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

        return $this->page('Playback', $this->body(), 'playback');
    }

    private function save(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        /*
         * Every field is read on every save, and the form always submits all of
         * them — so an absent checkbox really does mean "off" here. This screen
         * has no partial-POST path the way the site settings do, which is what
         * the _whole_form marker exists for there.
         */
        $this->plugin->setSetting('skip_enabled', $request->input('skip_enabled') !== null);
        $this->plugin->setSetting('next_enabled', $request->input('next_enabled') !== null);

        $titles = trim((string) ($request->input('skip_titles') ?? ''));
        $this->plugin->setSetting(
            'skip_titles',
            $titles === '' ? PlaybackPolicy::DEFAULT_TITLES : mb_substr($titles, 0, 300)
        );

        $this->plugin->setSetting(
            'next_countdown',
            PlaybackPolicy::countdown($request->input('next_countdown'))
        );

        Audit::log($this->db(), $this->user()?->email, 'playback.settings');

        return $this->redirect($this->plugin->config()->url('/admin/playback'));
    }

    private function body(): string
    {
        $token = e($this->csrfToken());
        $skip = $this->plugin->setting('skip_enabled', true) ? ' checked' : '';
        $next = $this->plugin->setting('next_enabled', true) ? ' checked' : '';
        $titles = e((string) $this->plugin->setting('skip_titles', PlaybackPolicy::DEFAULT_TITLES));
        $countdown = (int) PlaybackPolicy::countdown(
            $this->plugin->setting('next_countdown', PlaybackPolicy::DEFAULT_COUNTDOWN)
        );

        return <<<HTML
        <h1>Playback</h1>

        <form method="post">
          <input type="hidden" name="_token" value="{$token}">

          <fieldset>
            <legend>Skip ahead</legend>
            <label class="checkbox">
              <input type="checkbox" name="skip_enabled" value="1"{$skip}>
              Offer a button that jumps to the main part
            </label>
            <p class="muted small">Appears only on videos that have chapters, and only when one of the
               chapters below is found after the start. On a recording of a whole service that is the
               difference between people watching the sermon and people giving up on the notices.</p>
            <label>Chapter names to look for
              <input type="text" name="skip_titles" value="{$titles}" maxlength="300">
            </label>
            <p class="muted small">Checked in order, first match wins, and matching ignores case and
               surrounding words — a chapter called “Sermon: Romans 8” matches “Sermon”. Nothing is
               shown if a video has only one chapter, because then there is nothing to skip.</p>
          </fieldset>

          <fieldset>
            <legend>Up next</legend>
            <label class="checkbox">
              <input type="checkbox" name="next_enabled" value="1"{$next}>
              When a video ends, offer the next one in its series
            </label>
            <p class="muted small">Series only. In a series “next” is a running order somebody decided;
               for a standalone video it would be a guess, and a guess that plays itself is a different
               and ruder thing. “More like this” already offers those and waits to be asked.</p>
            <label>Seconds before it plays by itself
              <input type="number" name="next_countdown" value="{$countdown}" min="0" max="60">
            </label>
            <p class="muted small"><strong>Set 0 to never play automatically</strong> — the card still
               appears with a link. Any click, key or tap while the countdown runs stops it, because
               somebody who is still reading has not finished.</p>
          </fieldset>

          <button class="btn">Save</button>
        </form>

        <p class="muted small">Both need JavaScript to talk to the player, and both work without it:
           the skip button falls back to a link that starts the video at that moment, and the up-next
           card stays hidden with a working link inside. Nothing here is only reachable by script.</p>
        HTML;
    }
}
