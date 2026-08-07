<?php
/**
 * Browse by passage: the list of books that have something under them.
 *
 * Its own template rather than a reuse of archive.php, because this lists BOOKS
 * and archive lists VIDEOS — a shared template would be a branch on which of
 * two unrelated things it had been handed.
 *
 * Grouped by testament, which is the only grouping anybody scans a canon by.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array{slug: string, name: string, testament: string, videos: int, url: string}> $books
 * @var string $heading
 */

declare(strict_types=1);

$books ??= [];
$heading ??= 'Browse by passage';

$groups = ['ot' => [], 'dc' => [], 'nt' => []];
foreach ($books as $book) {
    $groups[$book['testament']][] = $book;
}

$labels = [
    'ot' => 'Old Testament',
    'dc' => 'Deuterocanon',
    'nt' => 'New Testament',
];

echo $template->partial('header', get_defined_vars());
?>

<h1><?= e($heading) ?></h1>

<?php if ($books === []): ?>
  <p class="muted">
    Nothing has been indexed by passage yet. References are read from video
    descriptions, and can be set on any video from the admin screen.
  </p>
<?php else: ?>
  <?php foreach ($labels as $testament => $label): ?>
    <?php if ($groups[$testament] === []) {
        // Skipped entirely rather than shown empty. A site that preaches only
        // from the New Testament should not have two empty headings above it.
        continue;
    } ?>

    <h2><?= e($label) ?></h2>
    <ul class="book-list">
      <?php foreach ($groups[$testament] as $book): ?>
        <li>
          <a href="<?= e($book['url']) ?>"><?= e($book['name']) ?></a>
          <span class="muted small"><?= (int) $book['videos'] ?></span>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endforeach ?>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
