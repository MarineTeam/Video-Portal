<?php
/**
 * Reading a book.
 *
 * The rendering is entirely browser-side — see book-reader.js — because that is
 * what makes this work on shared hosting at all. What the page provides is the
 * contents, where to open, and the controls.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, mixed> $book
 * @var list<array<string, mixed>> $contents
 * @var int $page
 * @var array<string, mixed>|null $position
 * @var list<array<string, mixed>> $marks
 * @var string $token
 */

declare(strict_types=1);

use Portal\Reader\Locator;

$contents ??= [];
$marks ??= [];
$page ??= 1;
$position ??= null;
$token ??= '';

$offset = (int) $book['page_offset'];
$isHymnal = !empty($book['is_hymnal']);

echo $template->partial('header', get_defined_vars());
?>

<div class="reader"
     data-reader
     data-slug="<?= e((string) $book['slug']) ?>"
     data-kind="<?= e((string) $book['kind']) ?>"
     data-page="<?= (int) $page ?>"
     data-offset="<?= $offset ?>"
     data-pages="<?= (int) $book['page_count'] ?>"
     data-revision="<?= (int) $book['file_revision'] ?>"
     data-token="<?= e($token) ?>">

  <header class="reader-bar">
    <a href="/books" class="btn tiny secondary">&larr; Books</a>

    <strong class="reader-title"><?= e((string) $book['title']) ?></strong>

    <?php if ($contents !== []): ?>
      <button class="btn tiny secondary" data-reader-contents>Contents</button>
    <?php endif ?>

    <?php
      /*
       * "Go to hymn 214". The number is a CONTENTS ENTRY, not a page — the
       * page it lands on is worked out here, so correcting the book's offset
       * moves every one of these at once.
       */
    ?>
    <form class="reader-goto" data-reader-goto>
      <label><?= $isHymnal ? 'Hymn' : 'Page' ?>
        <input type="number" min="1" inputmode="numeric" data-reader-number>
      </label>
      <button class="btn tiny">Go</button>
    </form>

    <button class="btn tiny secondary" data-reader-present title="Full screen for the front">Present</button>

    <?php
    /*
     * Saving buys speed and bandwidth, NOT availability — the book still asks
     * the site on every open. The button says so rather than leaving somebody
     * to find out in a hall with no signal.
     */
    ?>
    <button class="btn tiny secondary" data-reader-save hidden
            title="Keeps the file on this device. It still checks with the site before opening.">
      Save this book
    </button>
  </header>

  <?php
  /*
   * Said plainly rather than hidden. A book revalidates before it opens, so it
   * will not open with no signal at all — deliberately unlike a downloaded
   * video, because access to a book is a question about right now.
   */
  ?>
  <p class="notice reader-offline" data-reader-offline hidden>
    This needs a connection to open — the site checks you can still read it each time.
  </p>

  <div class="reader-stage" data-reader-stage>
    <div class="reader-loading">Opening…</div>
  </div>

  <nav class="reader-nav">
    <button class="btn secondary" data-reader-prev>
      &larr; <?= $contents === [] ? 'Page' : ($isHymnal ? 'Hymn' : 'Section') ?>
    </button>

    <span class="reader-where" data-reader-where></span>

    <button class="btn secondary" data-reader-next>
      <?= $contents === [] ? 'Page' : ($isHymnal ? 'Hymn' : 'Section') ?> &rarr;
    </button>
  </nav>

  <?php if ($contents !== []): ?>
    <aside class="reader-toc" data-reader-toc hidden>
      <h2 class="section-title">Contents</h2>
      <ul>
        <?php foreach ($contents as $entry): ?>
          <li>
            <a href="?at=<?= e(Locator::reference((int) $entry['pdf_page'], $entry['number'] === null ? null : (int) $entry['number'])) ?>"
               data-reader-jump="<?= (int) $entry['pdf_page'] ?>">
              <?php if ($entry['number'] !== null): ?>
                <span class="reader-number"><?= (int) $entry['number'] ?></span>
              <?php endif ?>
              <?= e((string) $entry['title']) ?>
            </a>
          </li>
        <?php endforeach ?>
      </ul>
    </aside>
  <?php endif ?>

  <?php
  /*
   * The contents as data, so the browser can step by ENTRY without a request
   * per press — the same list the server stepped through, so the two cannot
   * disagree about what comes next.
   */
  ?>
  <script type="application/json" data-reader-data>
    <?= json_encode([
        'contents' => array_map(
            static fn (array $entry): array => [
                'number' => $entry['number'] === null ? null : (int) $entry['number'],
                'title'  => (string) $entry['title'],
                'page'   => (int) $entry['pdf_page'],
            ],
            $contents
        ),
        'marks' => array_map(
            static fn (array $mark): array => [
                'page' => (int) $mark['pdf_page'],
                'kind' => (string) $mark['kind'],
            ],
            $marks
        ),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
  </script>
</div>

<script src="<?= e(asset_url('/assets/offline.js')) ?>" defer></script>
<script src="<?= e(isset($themeAsset)
    ? $themeAsset('book-reader.js')
    : ($assetsUrl ?? '/theme-asset/default') . '/book-reader.js') ?>" defer></script>

<?= $template->partial('footer', get_defined_vars()) ?>
