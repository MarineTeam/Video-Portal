<?php
/**
 * The search page.
 *
 * Separate from archive.php, which it used to share, because a search result is
 * not an archive: it needs the query echoed back, the narrowing controls, and
 * matching series and speakers above the videos. A theme that wants the plain
 * listing back deletes this file and archive.php takes over again.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $videos
 * @var string $searchTerm
 * @var string $correctedFrom what was typed, when the spelling was corrected
 * @var string $exactUrl      the same search, uncorrected
 * @var list<array{title: string, url: string, count: int}> $matchedSeries
 * @var list<array{name: string, url: string, count: int}> $matchedSpeakers
 * @var list<array{id: int, label: string}> $seriesOptions
 * @var list<array{id: int, label: string}> $speakerOptions
 * @var array<string, mixed> $activeFilters
 * @var int $total
 * @var array{page: int, pages: int, prevUrl: ?string, nextUrl: ?string} $pagination
 * @var bool $thumbnailsAvailable
 */

declare(strict_types=1);

$videos ??= [];
$searchTerm ??= '';
$correctedFrom ??= '';
$exactUrl ??= '';
$matchedSeries ??= [];
$matchedSpeakers ??= [];
$seriesOptions ??= [];
$speakerOptions ??= [];
$activeFilters ??= [];
$total ??= count($videos);
$thumbnailsAvailable ??= true;
$pagination ??= ['page' => 1, 'pages' => 1, 'prevUrl' => null, 'nextUrl' => null];

$showDuration = ($theme ?? null)?->setting('show-duration') !== '0';

$activeSeries = (int) ($activeFilters['seriesId'] ?? 0);
$activeSpeaker = (int) ($activeFilters['speakerId'] ?? 0);
$activeYear = (int) ($activeFilters['year'] ?? 0);

$thisYear = (int) date('Y');

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Search</h1>

<?php
/*
 * GET, so a result is a URL somebody can bookmark, send to a colleague, or
 * reload after going back. A search that cannot be linked to is a search
 * people describe to each other in words instead.
 */
?>
<form class="toolbar search-toolbar" method="get" action="/search" role="search">
  <div class="field">
    <label class="visually-hidden" for="q">Search</label>
    <input type="search" id="q" name="q" value="<?= e($searchTerm) ?>"
           placeholder="Title, speaker, series…" autocomplete="off" autofocus>
  </div>

  <div class="field">
    <label class="visually-hidden" for="series">Series</label>
    <select id="series" name="series">
      <option value="">Any series</option>
      <?php foreach ($seriesOptions as $option): ?>
        <option value="<?= (int) $option['id'] ?>"
          <?= $activeSeries === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
      <?php endforeach ?>
    </select>
  </div>

  <div class="field">
    <label class="visually-hidden" for="speaker">Speaker</label>
    <select id="speaker" name="speaker">
      <option value="">Anyone</option>
      <?php foreach ($speakerOptions as $option): ?>
        <option value="<?= (int) $option['id'] ?>"
          <?= $activeSpeaker === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
      <?php endforeach ?>
    </select>
  </div>

  <div class="field">
    <label class="visually-hidden" for="year">Year</label>
    <select id="year" name="year">
      <option value="">Any year</option>
      <?php for ($year = $thisYear; $year >= $thisYear - 15; $year--): ?>
        <option value="<?= $year ?>" <?= $activeYear === $year ? 'selected' : '' ?>><?= $year ?></option>
      <?php endfor ?>
    </select>
  </div>

  <button class="btn secondary" type="submit">Search</button>

  <?php if ($searchTerm !== '' || $activeSeries || $activeSpeaker || $activeYear): ?>
    <a class="btn secondary" href="/search">Clear</a>
  <?php endif ?>
</form>

<?php if ($correctedFrom !== ''): ?>
  <?php
  /*
   * The correction is announced, not performed quietly.
   *
   * These are results for words nobody typed. Saying so — and keeping the
   * typed words one click away rather than describing them in the past tense —
   * is what separates a site that helps from one that argues: somebody who
   * really did mean an unusual spelling gets it back without retyping.
   */
  ?>
  <p class="page-subtitle">
    Showing results for <strong><?= e($searchTerm) ?></strong>.
    <?php if ($exactUrl !== ''): ?>
      <a href="<?= e($exactUrl) ?>">Search instead for &ldquo;<?= e($correctedFrom) ?>&rdquo;</a>
    <?php endif ?>
  </p>
<?php endif ?>

<?php if ($matchedSeries !== [] || $matchedSpeakers !== []): ?>
  <?php
  /*
   * Above the videos on purpose. Somebody typing a series name wants the
   * series page — its episodes, in order — not twelve of them scattered
   * through a relevance ranking they then have to reassemble.
   */
  ?>
  <section class="search-jump" aria-labelledby="jump-heading">
    <h2 class="section-title" id="jump-heading">Jump to</h2>
    <div class="chips">
      <?php foreach ($matchedSeries as $item): ?>
        <a class="chip" href="<?= e($item['url']) ?>">
          <?= e($item['title']) ?>
          <span style="opacity:.6"> · series<?= $item['count'] ? ', ' . (int) $item['count'] : '' ?></span>
        </a>
      <?php endforeach ?>
      <?php foreach ($matchedSpeakers as $item): ?>
        <a class="chip" href="<?= e($item['url']) ?>">
          <?= e($item['name']) ?>
          <span style="opacity:.6"> · speaker<?= $item['count'] ? ', ' . (int) $item['count'] : '' ?></span>
        </a>
      <?php endforeach ?>
    </div>
  </section>
<?php endif ?>

<?php do_action('before_video_list') ?>

<?php if ($searchTerm === '' && $videos === []): ?>
  <div class="empty">Type something above to search the library.</div>

<?php elseif ($videos === []): ?>
  <div class="empty">
    <?php if ($searchTerm !== ''): ?>
      Nothing matched &ldquo;<?= e($searchTerm) ?>&rdquo;.
    <?php else: ?>
      Nothing matched those filters.
    <?php endif ?>
  </div>

<?php else: ?>
  <p class="page-subtitle">
    <?= (int) $total ?> result<?= $total === 1 ? '' : 's' ?><?php
      if ($searchTerm !== '') { echo ' for &ldquo;' . e($searchTerm) . '&rdquo;'; }
    ?>
  </p>

  <?php if (!$thumbnailsAvailable): ?>
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
