<?php
/**
 * The ten-foot page: the library as a television shows it.
 *
 * # THE INPUT IS FOUR ARROWS AND AN OK BUTTON
 *
 * Every rule here is that sentence. A remote moves FOCUS and nothing else —
 * there is no pointer, no scroll wheel, no way to reach something that is not
 * on the focus path, and no way to see where you are except the focus ring.
 * So:
 *
 *   NOTHING WRAPS. A grid that reflows into a different number of columns at a
 *   different width makes Right from the end of a row land somewhere nobody can
 *   predict. The tiles are in one horizontal strip per row, and the strip
 *   scrolls; Right is always the next tile and never a different row.
 *
 *   THE FOCUSED TILE IS NOT CLIPPED. A focused tile grows, and a growing tile
 *   inside a scroll container is clipped by it — which is exactly the bug the
 *   admin sidebar flyout turned out to be, where `overflow-y: auto` made
 *   `overflow-x` compute to auto and the submenu widened the scroll area
 *   instead of escaping it. Measured there rather than reasoned about, and the
 *   lesson is applied here instead of relearned: the strip scrolls on ONE axis
 *   and the growth is a transform, which paints outside the box without
 *   changing the layout the scroller measures.
 *
 *   EVERY TILE IS FOCUSABLE AND THE RING IS LOUD. `outline: none` appears
 *   nowhere on this page. A remote user who cannot see what is focused cannot
 *   use the page at all.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $videos
 * @var string $heading
 */

declare(strict_types=1);

$videos ??= [];
$heading ??= 'Television';
?><!-- ten-foot page: no site chrome, by design -->
<style>
  /*
   * Inline, like present mode, and for the same reason: this page renders on a
   * device whose browser nobody has tested, and a stylesheet that fails to load
   * leaves a television showing 16px black-on-white text in a living room.
   */
  html, body { margin: 0; height: 100%; background: #05070d; color: #f8fafc; }

  body {
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    /* Sized for ten feet. Everything below is in rem against this, so one
       number moves the whole page. */
    font-size: 22px;
    /* overflow hidden on the BODY, so the page itself never scrolls — a
       television with no scrollbar and no pointer cannot recover from a page
       that has scrolled somewhere unexpected. The strip scrolls instead. */
    overflow: hidden;
  }

  .tv-head {
    padding: 2rem 3rem 1rem;
    font-size: 1.6rem;
    font-weight: 650;
  }

  .tv-row-title {
    padding: 0 3rem .5rem;
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: .16em;
    color: #94a3b8;
  }

  /*
   * The strip.
   *
   * `overflow-x: auto` with `overflow-y: visible` is NOT possible — a scroll
   * container clips both axes, which the admin sidebar work established by
   * measurement. So the strip is given generous vertical PADDING and the
   * focused tile grows by a transform inside it: the transform paints outside
   * the tile's box without changing the box the scroller measures, so nothing
   * is clipped and no scrollbar appears on the other axis.
   */
  .tv-strip {
    display: flex;
    gap: 1.5rem;
    /* The padding is the room the focused tile grows into. */
    padding: 1.5rem 3rem 2.5rem;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-behavior: smooth;
    /* No wrap. Ever. See the note at the top of this file. */
    flex-wrap: nowrap;
    /* A television has no scrollbar worth showing. */
    scrollbar-width: none;
  }

  .tv-strip::-webkit-scrollbar { display: none; }

  .tv-tile {
    flex: 0 0 auto;
    width: 16rem;
    color: inherit;
    text-decoration: none;
    display: block;
    transition: transform .15s ease-out;
    /* The tile is the focus target, and it is large: a remote's accuracy is
       one tile, so the target should be the whole card. */
    border-radius: 12px;
  }

  .tv-tile img,
  .tv-tile .tv-blank {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
    border-radius: 12px;
    background: #0f172a;
    border: 1px solid rgba(148, 163, 184, .2);
  }

  .tv-tile-title {
    /*
     * Two lines, then ellipsis — and the height is FIXED, so a long title does
     * not make its tile taller than its neighbours. On a focus-driven page a
     * row of tiles at different heights makes the focus ring jump about
     * vertically as you move along it, which reads as the page misbehaving.
     */
    margin: .6rem .2rem 0;
    font-size: .95rem;
    line-height: 1.3;
    height: 2.6em;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  /*
   * THE FOCUS RING. Loud, offset outside the tile, and paired with a scale so
   * it is not carried by colour alone — a television's colour rendering is
   * whatever somebody set it to in 2014.
   */
  .tv-tile:focus-visible,
  .tv-tile:focus {
    outline: 4px solid #38bdf8;
    outline-offset: 4px;
    transform: scale(1.06);
    /* The transform paints outside the box. That is the point: see the note on
       .tv-strip about why this is not a width change. */
  }

  .tv-empty { padding: 3rem; font-size: 1.2rem; color: #94a3b8; }

  .tv-foot { padding: 0 3rem 2rem; color: #94a3b8; font-size: .9rem; }
</style>

<h1 class="tv-head"><?= e($heading) ?></h1>

<?php if ($videos === []): ?>
  <p class="tv-empty">Nothing to watch yet.</p>
<?php else: ?>
  <p class="tv-row-title">Latest</p>

  <div class="tv-strip" data-tv-strip>
    <?php foreach ($videos as $card): ?>
      <?php
      /*
       * The card comes from VideoPresenter, which is where the members-only
       * thumbnail rule lives. A locked card carries NO url — the provider is
       * never even asked for one — so this template checks for the key rather
       * than for a flag. Reading `membersOnly` and deciding here would be the
       * second implementation that Phase 3 recorded leaking the artwork with
       * every test green.
       */
      $thumb = (string) ($card['thumbnail'] ?? '');
      ?>
      <?php
      /*
       * The card's own `url`, not a path assembled here from a slug. The model
       * builds it, so a site running under a sub-directory — or one that ever
       * changes the watch path — keeps working without this template knowing.
       */
      ?>
      <a class="tv-tile" href="<?= e((string) ($card['url'] ?? '/')) ?>">
        <?php if ($thumb !== ''): ?>
          <img src="<?= e($thumb) ?>" alt="">
        <?php else: ?>
          <span class="tv-blank"></span>
        <?php endif ?>
        <span class="tv-tile-title"><?= e((string) $card['title']) ?></span>
      </a>
    <?php endforeach ?>
  </div>
<?php endif ?>

<p class="tv-foot">
  Use the arrows on the remote, then OK. To sign this television out, sign out everywhere
  from your account on a phone or computer.
</p>

<script src="<?= e(isset($themeAsset)
    ? $themeAsset('tv-screen.js')
    : ($assetsUrl ?? '/theme-asset/default') . '/tv-screen.js') ?>" defer></script>
