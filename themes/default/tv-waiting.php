<?php
/**
 * What the television shows while it waits to be let in.
 *
 * # THE CODE IS THE ONLY THING ON THIS PAGE
 *
 * Somebody is reading it from a sofa, and the room may be bright. So it is
 * enormous, it is grouped in fours, and there is nothing else competing with
 * it — no navigation, no logo, no "welcome to". The two lines of instruction
 * are the smallest thing here, deliberately: if you can read the instructions
 * from where you are sitting, the code is too small.
 *
 * And the alphabet is explained ON THIS SCREEN as well as on the phone. The
 * commonest failure in the whole flow is somebody reading a 0 and typing an O,
 * and the person reading is at the television, not looking at the help text on
 * their phone.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var string $userCode
 * @var string $deviceCode
 * @var int $interval
 * @var int $expiresIn
 * @var string $siteAddress
 */

declare(strict_types=1);

$userCode ??= '';
$deviceCode ??= '';
$interval ??= 5;
$expiresIn ??= 600;
$siteAddress ??= '';
?><!-- the television's own screen: no chrome, by design -->
<style>
  /* Inline for the same reason as the other two television pages: a stylesheet
     that fails to load on a set-top browser leaves a code nobody can read. */
  html, body { margin: 0; height: 100%; background: #05070d; color: #f8fafc; }

  body {
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 4vw;
    gap: 3vh;
  }

  .tv-wait-lead { font-size: clamp(1rem, 2.4vw, 2rem); color: #94a3b8; max-width: 40ch; }

  /*
   * The code itself. Sized to the VIEWPORT, because the distance between the
   * sofa and the set is unknown and a fixed size is a guess. Monospace with a
   * wide letter-spacing: a proportional font puts the characters at uneven
   * intervals, which is the other way somebody loses their place reading eight
   * of them across a room.
   */
  .tv-wait-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: clamp(3rem, 14vw, 16rem);
    font-weight: 700;
    letter-spacing: .12em;
    line-height: 1;
  }

  .tv-wait-note { font-size: clamp(.875rem, 1.4vw, 1.25rem); color: #64748b; max-width: 46ch; }

  .tv-wait-state { font-size: clamp(.875rem, 1.4vw, 1.25rem); color: #94a3b8; min-height: 1.4em; }
</style>

<p class="tv-wait-lead">
  On your phone, go to <strong><?= e($siteAddress) ?>/tv</strong> and type this code.
</p>

<p class="tv-wait-code" data-tv-code><?= e($userCode) ?></p>

<p class="tv-wait-note">
  There are no letter Os or Is in the code — a round one is a zero, and a tall one is a one.
  It lasts <?= (int) round($expiresIn / 60) ?> minutes; reload this page for a new one.
</p>

<p class="tv-wait-state" data-tv-state aria-live="polite"></p>

<?php
/*
 * The device code is in a data attribute rather than in the script, so the
 * template does not have to inline a value into JavaScript — which is where an
 * escaping mistake becomes script injection rather than a wrong string. It is
 * read with getAttribute and sent in a form body.
 */
?>
<div hidden data-tv-pairing
     data-device="<?= e($deviceCode) ?>"
     data-interval="<?= (int) $interval ?>"
     data-expires="<?= (int) $expiresIn ?>"></div>

<script src="<?= e(isset($themeAsset)
    ? $themeAsset('tv-waiting.js')
    : ($assetsUrl ?? '/theme-asset/default') . '/tv-waiting.js') ?>" defer></script>
