<?php
/**
 * One viewer's saved videos.
 *
 * Two lists on one page rather than two pages: "did I favourite this or save it
 * for later" is not a question anybody should answer by visiting two addresses.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $favorites
 * @var list<array<string, mixed>> $watchLater
 * @var bool $thumbnailsAvailable
 */

declare(strict_types=1);

$favorites ??= [];
$watchLater ??= [];
$thumbnailsAvailable ??= true;

$showDuration = ($theme ?? null)?->setting('show-duration') !== '0';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Saved</h1>

<?php
/**
 * Rendering one list, so the two below cannot drift apart.
 *
 * @param list<array<string, mixed>> $items
 */
$section = static function (string $heading, array $items, string $emptyText) use ($template, $showDuration, $thumbnailsAvailable): void { ?>
  <section aria-labelledby="<?= e(strtolower(str_replace(' ', '-', $heading))) ?>-heading"
           style="margin-bottom:3rem">
    <h2 class="section-title" id="<?= e(strtolower(str_replace(' ', '-', $heading))) ?>-heading">
      <?= e($heading) ?>
      <?php if ($items !== []): ?>
        <span class="muted" style="font-weight:400"> · <?= count($items) ?></span>
      <?php endif ?>
    </h2>

    <?php if ($items === []): ?>
      <div class="empty"><?= e($emptyText) ?></div>
    <?php elseif (!$thumbnailsAvailable): ?>
      <ul class="title-list">
        <?php foreach ($items as $video): ?>
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
        <?php foreach ($items as $video): ?>
          <?= $template->partial('video-card', ['video' => $video, 'showDuration' => $showDuration]) ?>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </section>
<?php };

$section('Favourites', $favorites, 'Nothing here yet. Favourite a video from its page.');
$section('Watch later', $watchLater, 'Nothing queued. Save a video for later from its page.');
?>

<?= $template->partial('footer', get_defined_vars()) ?>
