<?php
/**
 * Settings for THIS device.
 *
 * Kept in the browser, not the account: a phone on the bus and the television
 * in the lounge are routinely wanted differently by the same person, and most
 * visitors have no account at all. So this works signed out, differs per
 * device, and is gone when site data is cleared — which the page says.
 *
 * The storage keys are a contract with player.js and the playback plugin:
 *   portal.autoplay  'on' | 'off'   (absent means on)
 *   portal.speed     '0.75' … '2'   (absent means 1)
 * tools/smoke.php checks the three files agree on them.
 *
 * @var \Portal\Themes\TemplateLoader $template
 */

declare(strict_types=1);

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Settings on this device</h1>
<p class="page-subtitle">Saved in this browser only. Another phone or computer keeps its own, and
   clearing this browser's site data resets them.</p>

<noscript>
  <div class="notice error"><p>These settings are kept by your browser, so they need JavaScript
     to be switched on. Everything else on the site works without it.</p></div>
</noscript>

<form class="stacked-form" id="device-settings" hidden onsubmit="return false">
  <label class="checkbox">
    <input type="checkbox" name="autoplay" id="device-autoplay">
    Play the next video automatically
  </label>
  <p class="muted small">When a video in a series ends, the next one starts after a short
     countdown. Switched off, you are shown what is next and choose.</p>

  <label>
    Usual playback speed
    <select name="speed" id="device-speed">
      <option value="0.75">0.75&times;</option>
      <option value="1">1&times;</option>
      <option value="1.25">1.25&times;</option>
      <option value="1.5">1.5&times;</option>
      <option value="1.75">1.75&times;</option>
      <option value="2">2&times;</option>
    </select>
  </label>
  <p class="muted small">Listening starts at this speed. The video player cannot be set from this
     site, so under a video you are reminded of it instead — choose it in the player's own
     settings.</p>

  <p id="device-status" class="muted small" role="status" aria-live="polite"></p>
</form>

<script src="<?= e(isset($themeAsset) ? $themeAsset('device-settings.js') : '/theme-asset/default/device-settings.js') ?>" defer></script>

<?= $template->partial('footer', get_defined_vars()) ?>
