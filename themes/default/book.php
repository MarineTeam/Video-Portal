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
     <?php
     /*
      * Where an EPUB reader had got to. An opaque string only epub.js
      * understands — kept beside the page rather than instead of it, because
      * nothing here could turn one into the other.
      */
     ?>
     data-cfi="<?= e((string) ($position['epub_cfi'] ?? '')) ?>"
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
    <?php
    /*
     * Searching inside this book, against the text an admin's browser read out
     * of it. Nothing is found in a book nobody has indexed, and the answer says
     * so rather than looking like an empty result.
     */
    ?>
    <form class="reader-find" data-reader-find>
      <label>Find <input type="search" data-reader-query placeholder="a line you remember"></label>
      <button class="btn tiny secondary">Find</button>
    </form>

    <form class="reader-goto" data-reader-goto>
      <label><?= $isHymnal ? 'Hymn' : 'Page' ?>
        <input type="number" min="1" inputmode="numeric" data-reader-number>
      </label>
      <button class="btn tiny">Go</button>
    </form>

    <?php
    /*
     * MARKS NEED AN ACCOUNT, and a highlight needs a PDF.
     *
     * Both are decided here as well as on the server, because a button that
     * always refuses is worse than no button: somebody presses it, nothing
     * happens, and they conclude the reader is broken rather than that the
     * feature is not for them. An EPUB's text lives inside an iframe its own
     * renderer owns, so there is no selection this application can see.
     */
    ?>
    <?php if (($currentUser ?? null) !== null): ?>
      <button class="btn tiny secondary" data-reader-bookmark>Bookmark</button>

      <?php if ((string) $book['kind'] === 'pdf'): ?>
        <button class="btn tiny secondary" data-reader-highlight
                title="Select some words first">Highlight</button>
      <?php endif ?>
    <?php endif ?>

    <button class="btn tiny secondary" data-reader-smaller title="Smaller">&minus;</button>
    <button class="btn tiny secondary" data-reader-bigger title="Bigger">+</button>

    <?php
    /*
     * Reading aloud needs the text layer, which only our own renderer produces
     * — with the browser's built-in viewer there is nothing to read. The button
     * is here regardless and does nothing where there is no text, which is
     * quieter than a control that appears and disappears.
     */
    ?>
    <button class="btn tiny secondary" data-reader-aloud title="Read this page aloud">Read aloud</button>

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

  <div class="reader-hits" data-reader-hits hidden></div>

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

<?php
/*
 * The renderer. Loaded before the reader, which checks for it and falls back
 * to the browser's own viewer when it is absent — a reader that pages is worth
 * much more than a broken one, and vendored files do go missing.
 */
?>
<?php if ((string) $book['kind'] === 'epub'): ?>
  <?php
  /*
   * JSZip first: an EPUB is a zip file and epub.js expects the unzipper on the
   * window rather than bundling one. Without it a book fails with an error
   * naming neither library.
   */
  ?>
  <script src="<?= e(asset_url('/assets/vendor/epubjs/jszip.min.js')) ?>" defer></script>
  <script src="<?= e(asset_url('/assets/vendor/epubjs/epub.min.js')) ?>" defer></script>
<?php else: ?>
  <script src="<?= e(asset_url('/assets/vendor/pdfjs/pdf.min.js')) ?>" defer></script>
<?php endif ?>
<script src="<?= e(asset_url('/assets/offline.js')) ?>" defer></script>
<script src="<?= e(isset($themeAsset)
    ? $themeAsset('book-reader.js')
    : ($assetsUrl ?? '/theme-asset/default') . '/book-reader.js') ?>" defer></script>

<?= $template->partial('footer', get_defined_vars()) ?>
