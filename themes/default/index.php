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

$homeRows ??= [];

/*
 * Did a PERSON arrange this front page, or did a plugin add a row to it?
 *
 * Only the first replaces the library listing below. Defaulted from $homeRows
 * so an older controller — or another screen borrowing this template, which is
 * the fallback for anything with no better match — behaves as it always did.
 */
$homeRowsCurated ??= ($homeRows !== []);

$showDuration = ($theme ?? null)?->setting('show-duration') !== '0';

/*
 * With curated rows configured, continue-watching is one of them and appears
 * wherever it was placed. Showing it here as well would print it twice.
 */
$showContinue = apply_filters(
    'show_continue_watching',
    !$homeRowsCurated && $continueWatching !== []
);

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

<?php
/*
 * Aimed at /search rather than back at this page. Searching from here used to
 * reload the library with a filter applied, which meant the narrowing controls
 * and the matching series and speakers were unreachable from the one place
 * everybody starts.
 */
?>
<form class="toolbar" method="get" action="/search" role="search">
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

<?php
/*
 * Playlists. Only shown when there are some, so a site that does not use them
 * never sees an empty heading.
 */
$playlists ??= [];
?>
<?php if ($playlists !== []): ?>
  <section aria-labelledby="playlists-heading" style="margin-bottom:2.5rem">
    <h2 class="section-title" id="playlists-heading">Playlists</h2>
    <div class="chips">
      <?php foreach ($playlists as $playlist): ?>
        <a class="chip" href="<?= e($playlist['url']) ?>">
          <?= e($playlist['title']) ?>
          <?php if (!empty($playlist['count'])): ?>
            <span style="opacity:.6"> · <?= (int) $playlist['count'] ?></span>
          <?php endif ?>
        </a>
      <?php endforeach ?>
    </div>
  </section>
<?php endif ?>

<?php do_action('before_video_list') ?>

<?php
/*
 * Rows: somebody's arrangement of the front page, plus anything a plugin added.
 *
 * Curated rows replace the flat listing rather than sitting above it — a
 * homepage that shows three curated rows and then every video again is not a
 * curated homepage. A row a PLUGIN added does not replace anything, so on the
 * usual install it appears above the library and the library stays. With
 * neither, this block renders nothing and the listing below is exactly what it
 * always was.
 */
?>
<?php if ($homeRows !== []): ?>
  <?php foreach ($homeRows as $index => $row): ?>
    <?php $rowId = 'home-row-' . $index; ?>
    <section aria-labelledby="<?= e($rowId) ?>" style="margin-bottom:2.5rem">
      <h2 class="section-title" id="<?= e($rowId) ?>">
        <?= e($row['title']) ?>
        <?php if (!empty($row['url'])): ?>
          <a class="card-meta" href="<?= e($row['url']) ?>" style="margin-left:auto">See all</a>
        <?php endif ?>
      </h2>

      <?php if (!$thumbnailsAvailable): ?>
        <ul class="title-list">
          <?php foreach ($row['videos'] as $video): ?>
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
          <?php foreach ($row['videos'] as $video): ?>
            <?= $template->partial('video-card', ['video' => $video, 'showDuration' => $showDuration]) ?>
          <?php endforeach ?>
        </div>
      <?php endif ?>
    </section>
  <?php endforeach ?>
<?php endif ?>

<?php if (!$homeRowsCurated): ?>
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
<?php endif ?>

<?php do_action('after_video_list') ?>

<?php if (!empty($subscribeEnabled)): ?>
  <?= $template->partial('subscribe', get_defined_vars()) ?>
<?php endif ?>

<?php
/*
 * No pagination under curated rows. The rows are a front page, not page one of
 * a list, and a "Next" button there would lead somewhere with a completely
 * different shape. A plugin's row leaves the listing in place, so it leaves the
 * pagination in place too.
 */
?>
<?php if (!$homeRowsCurated && ($pagination['pages'] ?? 1) > 1): ?>
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
