<?php
/**
 * The order of service, on the screen at the front of the building.
 *
 * EVERY SLIDE IS IN THIS PAGE. Nothing is fetched after it loads, because a
 * projector is on the wifi that drops during the second hymn and a present mode
 * that went blank then would do it at the one moment nobody can be debugging
 * it. The script moves between slides that are already here.
 *
 * NO HEADER, NO FOOTER, NO NAVIGATION. This is not a page somebody browses; it
 * is a wall. The partials are deliberately not included — which also means this
 * template does not inherit the site chrome a child theme might change, and
 * that is the intent rather than an oversight.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, mixed> $service
 * @var \Portal\Rota\PresentPlan $plan
 * @var int $at
 */

declare(strict_types=1);

$service ??= [];
$at ??= 0;

/** @var \Portal\Rota\PresentPlan $plan */
$slides = $plan->slides;
$id = (int) ($service['id'] ?? 0);
?><!-- present mode: no chrome, by design -->
<style>
  /*
   * Inline, and the only place in this theme where that is true.
   *
   * A stylesheet is a second request that has to succeed before a wall of text
   * is legible, and if it fails this page is unreadable rather than merely
   * plain — white-on-white, or 16px type in a hall. On the one screen in the
   * building that has to work, the styles travel with the markup.
   */
  html, body { margin: 0; height: 100%; background: #05070d; color: #f8fafc; }

  body {
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    display: flex;
    flex-direction: column;
  }

  .present {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    /* Padding in vw so the margins scale with the projector rather than
       leaving a 1rem gutter on a nine-foot screen. */
    padding: 4vw;
    gap: 2vh;
  }

  /*
   * SIZED IN vw, not px or rem.
   *
   * This has to be readable from the back of a hall, on a projector nobody has
   * measured, at whatever resolution it happens to be running. A fixed size
   * that looks right on a laptop is unreadable at the back; sized to the
   * viewport it is proportionate on both, and `clamp` stops a phone held
   * sideways from rendering one enormous word.
   */
  .present-reference { font-size: clamp(2rem, 9vw, 12rem); font-weight: 700; line-height: 1; }
  .present-title { font-size: clamp(1.25rem, 4vw, 5rem); font-weight: 500; line-height: 1.2; }
  .present-label {
    font-size: clamp(.875rem, 1.8vw, 2rem);
    text-transform: uppercase;
    letter-spacing: .18em;
    color: #94a3b8;
  }

  .present-slide[hidden] { display: none; }

  /*
   * The controls fade out and come back on any movement — see the script. They
   * are still THERE when faded, because a touch screen has no pointer to move:
   * tapping the bottom of the screen has to hit something.
   */
  .present-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    padding: 1rem;
    font-size: 1rem;
    transition: opacity .4s;
  }

  .present-quiet .present-bar { opacity: 0; }
  .present-bar:focus-within { opacity: 1 !important; }

  .present-bar a, .present-bar button {
    font: inherit;
    color: #f8fafc;
    background: rgba(148, 163, 184, .16);
    border: 1px solid rgba(148, 163, 184, .3);
    border-radius: 8px;
    /* Big enough to hit with a finger, and to focus with a television remote:
       a 24px target is unusable with either. */
    padding: .75rem 1.25rem;
    text-decoration: none;
    cursor: pointer;
  }

  /*
   * A visible focus ring, and not `outline: none` anywhere on this page.
   *
   * A remote control moves focus and nothing else. Without a ring the person
   * pressing the arrows on a television cannot tell what they are about to
   * activate, which makes the whole page unusable from the only input that
   * device has.
   */
  .present-bar a:focus-visible, .present-bar button:focus-visible {
    outline: 3px solid #38bdf8;
    outline-offset: 2px;
  }

  .present-count { color: #94a3b8; }

  @media print { .present-bar { display: none; } }
</style>

<main class="present" data-present data-total="<?= count($slides) ?>" data-at="<?= (int) $at ?>">
  <?php if ($slides === []): ?>
    <p class="present-title">Nothing in the order yet.</p>
  <?php endif ?>

  <?php foreach ($slides as $index => $slide): ?>
    <?php
    /*
     * Every slide, hidden except one. `hidden` rather than display:none in a
     * class, so with the script blocked a browser still shows exactly one
     * slide — the one the server chose — and ?at=3 is a working link to the
     * third item. A present mode that needs a script is one that fails
     * completely rather than partly.
     */
    ?>
    <div class="present-slide" data-slide="<?= (int) $index ?>"
         <?= $index === (int) $at ? '' : 'hidden' ?>>
      <?php if ($slide->label() !== ''): ?>
        <p class="present-label"><?= e($slide->label()) ?></p>
      <?php endif ?>

      <?php if ($slide->reference !== ''): ?>
        <p class="present-reference"><?= e($slide->reference) ?></p>
      <?php endif ?>

      <p class="present-title"><?= e($slide->title) ?></p>
    </div>
  <?php endforeach ?>
</main>

<nav class="present-bar">
  <?php
  /*
   * Real links, not script-only buttons, and each one carries ?at= so it works
   * with nothing running. The script intercepts them to avoid a page load
   * between hymns — a reload on a projector is a visible flash of black.
   */
  $link = static fn (int $index): string => '/services/' . $id . '/present?at=' . $index;
  ?>

  <a href="<?= e($link(max(0, (int) $at - 1))) ?>" data-present-prev
     aria-label="Previous">&larr; Back</a>

  <span class="present-count" data-present-count>
    <?= $slides === [] ? '0 of 0' : ((int) $at + 1) . ' of ' . count($slides) ?>
  </span>

  <a href="<?= e($link(min(max(0, count($slides) - 1), (int) $at + 1))) ?>" data-present-next
     aria-label="Next">Next &rarr;</a>

  <button type="button" data-present-full>Full screen</button>

  <a href="/services/<?= $id ?>">Leave</a>
</nav>

<script src="<?= e(isset($themeAsset)
    ? $themeAsset('service-present.js')
    : ($assetsUrl ?? '/theme-asset/default') . '/service-present.js') ?>" defer></script>
