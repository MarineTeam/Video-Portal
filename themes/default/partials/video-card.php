<?php
/**
 * One video card.
 *
 * Kept as a partial so every listing — the library, a category, a series,
 * search results, continue-watching — renders identically, and a child theme
 * can restyle all of them by overriding this one file.
 *
 * @var array{
 *   id: int, title: string, url: string, thumbnail: ?string, duration: ?int,
 *   status: string, encodeProgress?: int, meta?: string, progressPercent?: int,
 *   membersOnly?: bool, badges?: list<array{label: string, kind?: string}>
 * } $video
 * @var bool $showDuration
 */

declare(strict_types=1);

$showDuration ??= true;
$status = $video['status'] ?? 'ready';
$percent = (int) ($video['progressPercent'] ?? 0);

/* Set by the controller when this video's artwork is withheld. The thumbnail
   URL is already null by then — this only decides what to draw instead, so a
   theme cannot accidentally reveal anything by ignoring it. */
$membersOnly = !empty($video['membersOnly']);

/*
 * Badges a plugin added through the `video_list` filter.
 *
 * Until this existed a plugin could not decorate a card at all: the filter has
 * always been able to add a key, and nothing rendered one. That made "mark
 * these videos" — new, popular, watched, whatever — impossible without shipping
 * a whole theme, which is the wrong price for a label.
 *
 * `kind` is a CSS class and comes from a plugin, so it is reduced to the
 * characters a class name may contain rather than trusted. An unstyled kind
 * still renders as a plain badge, so a plugin that invents one gets a label
 * that looks deliberate instead of nothing.
 *
 * Status badges below are the theme's own and always come last, so a plugin
 * cannot bury "Processing" under its own decoration.
 */
$badges = [];
foreach ((array) ($video['badges'] ?? []) as $badge) {
    $label = trim((string) ($badge['label'] ?? ''));
    if ($label === '') {
        continue;
    }

    $badges[] = [
        'label' => $label,
        'kind'  => strtolower(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($badge['kind'] ?? '')) ?? ''),
    ];
}
?>
<article class="video-card<?= $membersOnly ? ' is-locked' : '' ?>">
  <a href="<?= e($video['url']) ?>">
    <div class="thumb">
      <?php if ($membersOnly): ?>
        <div class="thumb-locked">
          <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false">
            <path fill="currentColor"
                  d="M12 1.5a4.75 4.75 0 0 0-4.75 4.75V9.5H6.5A2.5 2.5 0 0 0 4 12v8a2.5 2.5 0 0 0 2.5 2.5h11A2.5 2.5 0 0 0 20 20v-8a2.5 2.5 0 0 0-2.5-2.5h-.75V6.25A4.75 4.75 0 0 0 12 1.5Zm3.25 8H8.75V6.25a3.25 3.25 0 0 1 6.5 0V9.5Z"/>
          </svg>
          <span>Members only</span>
        </div>
      <?php elseif (!empty($video['thumbnail'])): ?>
        <?php /* loading="lazy" matters here: a 100-video page would otherwise
                 fire 100 signed CDN requests on first paint. */ ?>
        <img src="<?= e($video['thumbnail']) ?>"
             alt=""
             loading="lazy"
             decoding="async"
             width="640" height="360">
      <?php else: ?>
        <?php /* No thumbnail: the pull zone is unconfigured, or the video is
                 still encoding. Show the title rather than a broken image. */ ?>
        <div class="thumb-fallback"><?= e($video['title']) ?></div>
      <?php endif ?>

      <?php
      /*
       * One stack, so a plugin badge and a status badge do not land on top of
       * each other in the same corner. Nothing is emitted at all when there is
       * nothing to say, which keeps an ordinary card's markup as it was.
       *
       * A premiere is listed before it plays, so the card says when. A badge
       * reading "Premiering" with no date is an invitation to click something
       * that will not start.
       */
      $hasStatusBadge = !empty($video['premiereAt']) || $status === 'processing' || $status === 'failed';
      ?>
      <?php if ($badges !== [] || $hasStatusBadge): ?>
        <div class="badge-stack">
          <?php foreach ($badges as $badge): ?>
            <span class="badge<?= $badge['kind'] !== '' ? ' ' . e($badge['kind']) : '' ?>"><?= e($badge['label']) ?></span>
          <?php endforeach ?>

          <?php if (!empty($video['premiereAt'])): ?>
            <span class="badge premiere">
              Premieres <?php
                try {
                    echo e((new DateTimeImmutable((string) $video['premiereAt']))->format('j M'));
                } catch (Throwable) {
                    echo 'soon';
                }
              ?>
            </span>
          <?php elseif ($status === 'processing'): ?>
            <span class="badge processing">
              Processing<?= !empty($video['encodeProgress']) ? ' ' . (int) $video['encodeProgress'] . '%' : '' ?>
            </span>
          <?php elseif ($status === 'failed'): ?>
            <span class="badge failed">Failed</span>
          <?php endif ?>
        </div>
      <?php endif ?>

      <?php if ($showDuration && !empty($video['duration'])): ?>
        <span class="duration"><?= e(\Portal\Support\Str::duration((int) $video['duration'])) ?></span>
      <?php endif ?>
    </div>

    <?php if ($percent > 0 && $percent < 100): ?>
      <div class="progress" role="progressbar"
           aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"
           aria-label="Watched <?= $percent ?> percent">
        <span style="width: <?= $percent ?>%"></span>
      </div>
    <?php endif ?>

    <div class="card-body">
      <h3 class="card-title"><?= e($video['title']) ?></h3>
      <?php if (!empty($video['meta'])): ?>
        <p class="card-meta"><?= e($video['meta']) ?></p>
      <?php endif ?>
    </div>
  </a>
</article>
