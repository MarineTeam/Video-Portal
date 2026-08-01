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
 *   membersOnly?: bool
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

      <?php if ($status === 'processing'): ?>
        <span class="badge processing">
          Processing<?= !empty($video['encodeProgress']) ? ' ' . (int) $video['encodeProgress'] . '%' : '' ?>
        </span>
      <?php elseif ($status === 'failed'): ?>
        <span class="badge failed">Failed</span>
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
