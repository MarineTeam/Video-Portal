<?php
/**
 * The shelf.
 *
 * One search box across every book on it, which is what the stored page text is
 * for — searching a whole hymnal category without opening six PDFs.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $books
 */

declare(strict_types=1);

$books ??= [];

echo $template->partial('header', get_defined_vars());
echo $template->partial('breadcrumbs', get_defined_vars());
?>

<h1 class="page-title">Books</h1>

<?php if ($books === []): ?>
  <div class="empty">Nothing here yet.</div>
<?php else: ?>

  <form class="book-search" method="get" action="/books/search" data-book-search>
    <label>Search every book here
      <input type="search" name="q" placeholder="a line you remember">
    </label>
    <button class="btn secondary">Search</button>
  </form>

  <div class="book-results" data-book-results hidden></div>

  <ul class="book-shelf">
    <?php foreach ($books as $book): ?>
      <li>
        <a href="/books/<?= e((string) $book['slug']) ?>">
          <strong><?= e((string) $book['title']) ?></strong>
          <?php if (!empty($book['subtitle'])): ?>
            <span class="muted small"><?= e((string) $book['subtitle']) ?></span>
          <?php endif ?>
          <?php if (!empty($book['author'])): ?>
            <span class="muted small"><?= e((string) $book['author']) ?></span>
          <?php endif ?>
        </a>
        <?php if (!empty($book['is_hymnal'])): ?>
          <span class="pill">hymnal</span>
        <?php endif ?>
        <?php if (!empty($book['member_only'])): ?>
          <span class="pill">members only</span>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<script src="<?= e(isset($themeAsset)
    ? $themeAsset('book-search.js')
    : ($assetsUrl ?? '/theme-asset/default') . '/book-search.js') ?>" defer></script>

<?= $template->partial('footer', get_defined_vars()) ?>
