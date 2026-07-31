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
 *   status: string, encodeProgress?: int, meta?: string, progressPercent?: int
 * } $video
 * @var bool $showDuration
 */

declare(strict_types=1);

$showDuration ??= true;
$status = $video['status'] ?? 'ready';
$percent = (int) ($video['progressPercent'] ?? 0);
?>
<article class="video-card">
  <a href="<?= e($video['url']) ?>">
    <div class="thumb">
      <?php if (!empty($video['thumbnail'])): ?>
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
