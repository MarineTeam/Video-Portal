<?php
/**
 * The library — and the last-resort template for anything with no more
 * specific match, which is why it has to cope with an empty $videos.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $videos
 * @var list<array<string, mixed>> $continueWatching
 * @var list<array{id: int, name: string, slug: string}> $categories
 * @var array{page: int, pages: int, prevUrl: ?string, nextUrl: ?string} $pagination
 * @var bool   $thumbnailsAvailable
 * @var string $searchTerm
 * @var string $activeCategory
 */

declare(strict_types=1);

$videos ??= [];
$continueWatching ??= [];
$categories ??= [];
$searchTerm ??= '';
$activeCategory ??= '';
$thumbnailsAvailable ??= true;
$pagination ??= ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null];

$showDuration = ($theme ?? null)?->setting('show-duration') !== '0';
$showContinue = apply_filters('show_continue_watching', $continueWatching !== []);

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= e($title ?? 'Library') ?></h1>
<?php if (!empty($subtitle)): ?>
  <p class="page-subtitle"><?= e($subtitle) ?></p>
<?php endif ?>

<?php if ($showContinue && $continueWatching !== []): ?>
  <section aria-labelledby="continue-heading" style="margin-bottom:2.5rem">
    <h2 class="section-title" id="continue-heading">Continue watching</h2>
    <div class="video-grid">
      <?php foreach ($continueWatching as $video): ?>
        <?= $template->partial('video-card', ['video' => $video, 'showDuration' => $showDuration]) ?>
      <?php endforeach ?>
    </div>
  </section>
<?php endif ?>

<form class="toolbar" method="get" action="/" role="search">
  <div class="field">
    <label class="visually-hidden" for="q">Search videos</label>
    <input type="search" id="q" name="q" value="<?= e($searchTerm) ?>"
           placeholder="Search videos…" autocomplete="off">
  </div>

  <?php if ($categories !== []): ?>
    <div class="chips">
      <a class="chip" href="/" <?= $activeCategory === '' ? 'aria-pressed="true"' : 'aria-pressed="false"' ?>>All</a>
      <?php foreach ($categories as $category): ?>
        <a class="chip"
           href="/category/<?= e($category['slug']) ?>"
           aria-pressed="<?= $activeCategory === $category['slug'] ? 'true' : 'false' ?>">
          <?= e($category['name']) ?>
        </a>
      <?php endforeach ?>
    </div>
  <?php endif ?>

  <noscript><button class="btn secondary" type="submit">Search</button></noscript>
</form>

<?php do_action('before_video_list') ?>

<?php if ($videos === []): ?>
  <div class="empty">
    <?php if ($searchTerm !== ''): ?>
      Nothing matched “<?= e($searchTerm) ?>”.
    <?php else: ?>
      There are no videos here yet.
    <?php endif ?>
  </div>

<?php elseif (!$thumbnailsAvailable): ?>
  <?php
  /*
   * No pull zone is configured, so every thumbnail URL would be null. A grid
   * of empty boxes reads as broken; a clean list reads as deliberate. The
   * predecessor apps made the same call.
   */
  ?>
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
