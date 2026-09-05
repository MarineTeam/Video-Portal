<?php
/**
 * Category, series, speaker, tag, and search listings.
 *
 * One template for all of them: they differ only in heading and description,
 * and a theme author who wants them to diverge overrides the specific name
 * (category.php, series.php) rather than editing a branchy shared file.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $videos
 * @var list<array{name: string, slug: string, url: string, count: int}> $children
 * @var array{page: int, pages: int, prevUrl: ?string, nextUrl: ?string} $pagination
 * @var string  $heading
 * @var ?string $description
 * @var bool    $thumbnailsAvailable
 */

declare(strict_types=1);

$videos ??= [];
$children ??= [];
$description ??= null;
$thumbnailsAvailable ??= true;
$pagination ??= ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null];
$heading ??= $title ?? '';

$showDuration = ($theme ?? null)?->setting('show-duration') !== '0';

echo $template->partial('header', get_defined_vars());
echo $template->partial('breadcrumbs', get_defined_vars());
?>

<h1 class="page-title"><?= e($heading) ?></h1>

<?php if ($description !== null && $description !== ''): ?>
  <p class="page-subtitle" style="max-width:44rem"><?= nl2br(e($description)) ?></p>
<?php endif ?>

<?php if ($children !== []): ?>
  <section aria-labelledby="subsections-heading" style="margin-bottom:2.5rem">
    <h2 class="section-title" id="subsections-heading">Sections</h2>
    <div class="chips">
      <?php foreach ($children as $child): ?>
        <a class="chip" href="<?= e($child['url']) ?>">
          <?= e($child['name']) ?>
          <?php if (!empty($child['count'])): ?>
            <span style="opacity:.6"> · <?= (int) $child['count'] ?></span>
          <?php endif ?>
        </a>
      <?php endforeach ?>
    </div>
  </section>
<?php endif ?>

<?php do_action('before_video_list') ?>

<?php if ($videos === []): ?>
  <div class="empty">Nothing here yet.</div>

<?php elseif (!$thumbnailsAvailable): ?>
  <ul class="title-list">
    <?php foreach ($videos as $video): ?>
      <li>
        <a href="<?= e($video['url']) ?>">
          <span><?= e($video['title']) ?></span>
          <?php if (!empty($video['duration'])): ?>
            <span class="dur"><?= e(\Portal\Support\Str::duration((int) $video['duration'])) ?></span>
          <?php endif ?>
        </a>
      </li>
    <?php endforeach ?>
  </ul>

<?php else: ?>
  <div class="video-grid">
    <?php foreach ($videos as $video): ?>
      <?= $template->partial('video-card', ['video' => $video, 'showDuration' => $showDuration]) ?>
    <?php endforeach ?>
  </div>
<?php endif ?>

<?php do_action('after_video_list') ?>

<?php if (!empty($subscribeEnabled)): ?>
  <?= $template->partial('subscribe', get_defined_vars()) ?>
<?php endif ?>

<?php if (($pagination['pages'] ?? 1) > 1): ?>
  <nav class="pagination" aria-label="Pagination">
    <?php if (!empty($pagination['prevUrl'])): ?>
      <a class="btn secondary" href="<?= e($pagination['prevUrl']) ?>" rel="prev">Previous</a>
    <?php endif ?>
    <span>Page <?= (int) $pagination['page'] ?> of <?= (int) $pagination['pages'] ?></span>
    <?php if (!empty($pagination['nextUrl'])): ?>
      <a class="btn secondary" href="<?= e($pagination['nextUrl']) ?>" rel="next">Next</a>
    <?php endif ?>
  </nav>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
