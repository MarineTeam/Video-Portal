<?php

declare(strict_types=1);

namespace Portal\Plugins\Watermark;

use Portal\Auth\Capability;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginContext;
use Portal\Plugins\PluginPage;
use Portal\Support\Audit;

/**
 * The watermark settings screen.
 *
 * One handler serves GET and POST, because addAdminPage() registers both on a
 * single path.
 */
final class WatermarkPage extends PluginPage
{
    public function __construct(private readonly PluginContext $plugin)
    {
        parent::__construct();
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        // Route middleware only decides who gets through the admin front door.
        // The capability for changing plugin behaviour is checked here.
        $this->require(Capability::MANAGE_PLUGINS);

        if ($request->method === 'POST') {
            return $this->save($request);
        }

        return $this->page('Watermark', $this->body(), 'watermark');
    }

    private function save(Request $request): Response
    {
        $this->verifyCsrf($request);

        // Stored normalized. Doing it on save rather than on read means the
        // admin sees exactly what will be matched at playback time, instead of
        // wondering why " Alice@Example.COM " did not take effect.
        $exempt = WatermarkPolicy::parseList($request->input('exempt_emails') ?? '');

        $this->plugin->setSetting('exempt_emails', implode("\n", $exempt));
        $this->plugin->setSetting('label', trim($request->input('label') ?? '{email}'));
        $this->plugin->setSetting('opacity', WatermarkPolicy::clampOpacity($request->input('opacity')));

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'plugin.watermark.settings',
            'plugin',
            'watermark',
            count($exempt) . ' exempt address(es)'
        );

        return $this->back($request, 'Watermark settings saved.');
    }

    private function body(): string
    {
        $rawLabel = (string) $this->plugin->setting('label', '{email}');
        $opacity = WatermarkPolicy::clampOpacity($this->plugin->setting('opacity', 0.12));

        $exempt = e((string) $this->plugin->setting('exempt_emails', ''));
        $label = e($rawLabel);
        $opacityValue = number_format($opacity, 2, '.', '');
        $token = $this->csrfField();

        $globalState = $this->config()->settingBool('watermark_default', false)
            ? 'on for every video unless a video or a share says otherwise'
            : 'off unless a video or a share turns it on';

        // Rendered with the real renderer, at the real opacity, using the
        // signed-in admin's own address. A preview drawn by different code
        // would eventually stop matching what viewers actually see.
        $preview = WatermarkOverlay::render(
            WatermarkPolicy::label($rawLabel, [
                'email' => (string) ($this->user()?->email ?? 'viewer@example.com'),
                'date'  => date('j M Y'),
                'time'  => date('H:i'),
                'site'  => (string) ($this->config()->setting('site_name', '') ?? ''),
            ]),
            $opacity
        );

        return <<<HTML
        <h1>Watermark</h1>

        <p class="muted">Tiles the viewer's email address over the player. It deters casual
           re-sharing and makes a leaked recording traceable. It is <strong>not</strong> copy
           protection: anyone who opens developer tools can remove it from their own screen.
           The value is knowing who a leak came from, not stopping one.</p>

        <fieldset>
          <legend>Preview</legend>
          <div class="player" style="aspect-ratio:16/9;background:#111;border-radius:10px">{$preview}</div>
        </fieldset>

        <form method="post">
          <fieldset>
            <legend>Appearance</legend>

            <label>Label
              <input type="text" name="label" value="{$label}">
            </label>
            <p class="muted small">Use <code>{email}</code>, <code>{date}</code>, <code>{time}</code>,
               or <code>{site}</code>. Anything else is literal text.</p>

            <label>Opacity
              <input type="number" name="opacity" value="{$opacityValue}" step="0.01" min="0.04" max="0.40">
            </label>
            <p class="muted small">Between 0.04 and 0.40. Fainter marks nothing on bright footage;
               heavier makes the video unwatchable, and someone will simply turn the plugin off.</p>
          </fieldset>

          <fieldset>
            <legend>Exemptions</legend>

            <label>Never watermark these addresses
              <textarea name="exempt_emails" rows="4">{$exempt}</textarea>
            </label>
            <p class="muted small">One per line or comma-separated. Write <code>@example.com</code>
               to exempt a whole domain. <strong>An exemption beats everything else</strong>, including a
               share set to "always watermark" — so this is a list of people trusted not to leak,
               not a convenience list.</p>
          </fieldset>

          <button class="btn" type="submit">Save</button>
        </form>

        <fieldset>
          <legend>How a decision is made</legend>
          <p class="muted small">The first of these that gives an answer wins:</p>
          <ol class="muted small">
            <li><strong>Exempt address</strong> — never watermarked.</li>
            <li><strong>The share</strong> — the Watermark choice on the share link.</li>
            <li><strong>The video</strong> — the Watermark choice on the video.</li>
            <li><strong>This site</strong> — currently {$globalState}, changed under Settings.</li>
          </ol>
          <p class="muted small">Before any of that, the plugin has to be switched on for the video's
             category. Turn it off for one section of the site from the Plugins screen.</p>
        </fieldset>
        HTML;
    }
}
