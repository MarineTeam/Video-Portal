<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Reader\BookRepository;

/**
 * Keeping the books.
 *
 * The screen that matters is the offset. Everything else here is ordinary
 * cataloguing; that one field decides what number a congregation sees against
 * every page of the book, and the form shows a worked example rather than
 * asking somebody to imagine one.
 */
final class AdminBookView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'books' => $this->overview($data),
            'book'  => $this->book($data),
            'songs' => $this->songs($data),
            default => '<p>Unknown screen.</p>',
        };

        return (new AdminView())->shell($body, $data);
    }

    /** @param array<string, mixed> $data */
    private function overview(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['books'] ?? []) as $book) {
            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/books/%d"><strong>%s</strong></a>
                       <div class="muted small">/books/%s</div></td>
                   <td>%s %s %s</td>
                   <td class="muted small">%s</td>
                 </tr>',
                (int) $book['id'],
                e((string) $book['title']),
                e((string) $book['slug']),
                $book['is_published']
                    ? '<span class="pill">published</span>'
                    : '<span class="pill warn">draft</span>',
                $book['is_hymnal'] ? '<span class="pill">hymnal</span>' : '',
                $book['member_only'] ? '<span class="pill">members only</span>' : '',
                $book['indexed_at'] === null
                    // Said rather than left blank: an unindexed book has a
                    // search box that silently finds nothing.
                    ? 'not indexed — search will not find anything in it'
                    : 'indexed ' . e((string) $book['indexed_at'])
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">No books yet.</td></tr>';
        }

        return <<<HTML
        <h1>Books &amp; hymnals</h1>

        <p class="muted">Read in the browser — the pages are never rendered on the server, which
           is what makes this work on ordinary hosting.</p>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Book</th><th></th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>
          <div>
            <h2>Add a book</h2>
            <form method="post" action="/admin/books">
              <input type="hidden" name="_token" value="{$token}">
              <label>Title <input type="text" name="title" required></label>
              <label>Kind
                <select name="kind">
                  <option value="pdf">PDF</option>
                  <option value="epub">EPUB</option>
                </select>
              </label>
              <p class="muted small">Highlighting is PDF-only. An EPUB's text lives inside an
                 iframe its own renderer owns, so there is nothing this site can attach a
                 highlight to — better said here than offered as a button that does nothing.</p>
              <button class="btn" name="action" value="create">Create</button>
            </form>
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function book(array $data): string
    {
        $token = e((string) $data['token']);
        $book = (array) $data['book'];
        $id = (int) $book['id'];

        return <<<HTML
        <p class="muted small"><a href="/admin/books">&larr; Books</a></p>
        <h1>{$this->text((string) $book['title'])}</h1>
        <p class="muted small">Read at <a href="/books/{$this->text((string) $book['slug'])}">/books/{$this->text((string) $book['slug'])}</a></p>

        <div class="cols">
          <div>
            {$this->numbering($data, $token, $id)}
            {$this->contents($data, $token, $id)}
          </div>
          <div>
            {$this->details($book, $data, $token, $id)}
            {$this->indexing($data, $id)}
          </div>
        </div>
        HTML;
    }

    /**
     * The offset, with a worked example.
     *
     * @param array<string, mixed> $data
     */
    private function numbering(array $data, string $token, int $id): string
    {
        $book = (array) $data['book'];
        $example = (array) ($data['example'] ?? []);
        $offset = (int) $book['page_offset'];

        $reads = $example['printed'] === null
            ? 'nothing printed on it'
            : 'printed page ' . (int) $example['printed'];

        return <<<HTML
        <h2>Page numbering</h2>

        <form method="post" action="/admin/books">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$id}">

          <label>Leaves before printed page 1
            <input type="number" name="page_offset" min="0" value="{$offset}">
          </label>

          <p class="muted small">A cover, a title page, a blank. With this set to {$offset},
             page {$example['pdf']} of the file shows {$reads}.</p>

          <p class="muted small"><strong>Getting this wrong is not expensive to put right.</strong>
             What is stored everywhere — every bookmark, highlight, contents entry and saved
             place — is the page in the FILE. Changing this number relabels the whole book at
             once and rewrites nothing.</p>

          <label>Pages in the file
            <input type="number" name="page_count" min="0" value="{$book['page_count']}">
          </label>

          <button class="btn" name="action" value="save">Save</button>
        </form>
        HTML;
    }

    /**
     * @param array<string, mixed> $book
     * @param array<string, mixed> $data
     */
    private function details(array $book, array $data, string $token, int $id): string
    {
        $categories = '<option value="0">— none —</option>';
        foreach ((array) ($data['categories'] ?? []) as $category) {
            $categories .= sprintf(
                '<option value="%d"%s>%s</option>',
                (int) $category['id'],
                (int) ($book['category_id'] ?? 0) === (int) $category['id'] ? ' selected' : '',
                e((string) $category['name'])
            );
        }

        return <<<HTML
        <h2>Details</h2>
        <form method="post" action="/admin/books">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$id}">
          <input type="hidden" name="_whole_form" value="1">

          <label>Title <input type="text" name="title" value="{$this->text((string) $book['title'])}"></label>
          <label>Subtitle <input type="text" name="subtitle" value="{$this->text((string) ($book['subtitle'] ?? ''))}"></label>
          <label>Author <input type="text" name="author" value="{$this->text((string) ($book['author'] ?? ''))}"></label>
          <label>Shelf <select name="category_id">{$categories}</select></label>

          <label class="check">
            <input type="checkbox" name="is_hymnal" value="1" {$this->checked((bool) $book['is_hymnal'])}>
            This is a hymnal
          </label>
          <p class="muted small">Changes the reader's box from "page" to "hymn", and starts
             counting which hymns get looked up.</p>

          <label class="check">
            <input type="checkbox" name="is_published" value="1" {$this->checked((bool) $book['is_published'])}>
            On the shelf
          </label>
          <label class="check">
            <input type="checkbox" name="member_only" value="1" {$this->checked((bool) $book['member_only'])}>
            Members only
          </label>
          <p class="muted small">A members-only book is <strong>invisible</strong> to everybody
             else rather than refused — the title is a leak too.</p>

          <button class="btn" name="action" value="save">Save</button>
        </form>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function contents(array $data, string $token, int $id): string
    {
        $offset = (int) ((array) $data['book'])['page_offset'];

        $rows = '';
        foreach ((array) ($data['contents'] ?? []) as $entry) {
            $printed = (int) $entry['pdf_page'] - $offset;

            $rows .= sprintf(
                '<tr>
                   <td>%s</td>
                   <td>%s</td>
                   <td class="muted small">file page %d%s</td>
                   <td class="right">
                     <form method="post" action="/admin/books" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="entry" value="%d">
                       <button name="action" value="remove-entry" class="btn tiny secondary">Remove</button>
                     </form>
                   </td>
                 </tr>',
                $entry['number'] === null ? '' : (int) $entry['number'],
                e((string) $entry['title']),
                (int) $entry['pdf_page'],
                $printed >= 1 ? ' · printed ' . $printed : '',
                $token,
                (int) $entry['id']
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">Nothing indexed yet.</td></tr>';
        }

        return <<<HTML
        <h2>Contents</h2>
        <p class="muted small">From the file's own bookmarks where it has them, or typed here.
           <strong>Back and next step through these</strong> rather than through pages, because a
           hymn is not a page — some run to three and some sit two to a page.</p>

        <table>
          <thead><tr><th>No.</th><th>Title</th><th>Where</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>

        <h3>Add one</h3>
        <form method="post" action="/admin/books" class="inline-form">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$id}">
          <label>Number <input type="number" name="number" min="0" style="width:6rem"></label>
          <label>Title <input type="text" name="title" required></label>
          <label>File page <input type="number" name="pdf_page" min="1" value="1" style="width:7rem"></label>
          <button class="btn tiny" name="action" value="add-entry">Add</button>
        </form>
        <p class="muted small">The FILE page, not the printed one — that is what makes correcting
           the offset above free.</p>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function indexing(array $data, int $id): string
    {
        $book = (array) $data['book'];
        $pages = (int) ($data['indexedPages'] ?? 0);

        $state = $book['indexed_at'] === null
            ? '<span class="pill warn">not indexed</span>'
            : '<span class="pill">indexed</span>';

        return <<<HTML
        <h2>Indexing {$state}</h2>

        <p class="muted small">{$pages} page(s) of text stored. Reading the text out of a book
           happens <strong>in your browser</strong>, not on the server — a shared host cannot run
           OCR at any price, and the machine you are sitting at can. Open the book and leave the
           tab alone while it works.</p>

        <p class="muted small">Where a page has a text layer it is read directly; where it does
           not — a scanned hymnal — it is read by OCR, and that is remembered per page, because
           OCR text is good enough to search and not good enough to quote.</p>

        <p class="muted small">Replacing the file clears this. Page 40 of a new scan is not page
           40 of the old one, and keeping the text would leave search confidently pointing at the
           wrong pages.</p>

        {$this->indexer($book, $data)}
        HTML;
    }

    /**
     * The button that starts the browser reading.
     *
     * Only where there is a file. Offering it against a book with nothing
     * behind it produces an error that reads as the feature being broken.
     *
     * tesseract.min.js is 63KB and loads with the screen; the four megabytes
     * of engine and language data behind it load lazily, and only once
     * somebody ticks the OCR box. No visitor ever fetches any of it.
     *
     * @param array<string, mixed> $book
     * @param array<string, mixed> $data
     */
    private function indexer(array $book, array $data): string
    {
        if (empty($book['asset_id'])) {
            return '<p class="muted small">Attach a file first — there is nothing to read yet.</p>';
        }

        $token = e((string) $data['token']);
        $id = (int) $book['id'];
        $slug = e((string) $book['slug']);

        return <<<HTML
        <div data-book-index data-book="{$id}" data-token="{$token}" data-file="/books/{$slug}/file">
          <label class="check">
            <input type="checkbox" data-index-ocr>
            Also read scanned pages with OCR
          </label>
          <p class="muted small">Slow — seconds per page — and only worth it for a book with no
             text in it at all. Leave it off first: the pages that have text are read in moments,
             and the screen then tells you how many had none.</p>
          <p class="muted small">The recogniser is about four megabytes and loads the first time
             you tick this. It is served from this site rather than from anybody else's, so it
             works on a network that blocks outside scripts and nothing is told which books you
             are indexing.</p>

          <p><button class="btn" data-index-start>Read this book</button></p>
          <p class="muted small" data-index-status>Keep this tab open while it works. It stores as
             it goes, so closing it early keeps everything read so far.</p>
        </div>

        <script src="/assets/vendor/pdfjs/pdf.min.js" defer></script>
        <script src="/assets/vendor/tesseract/tesseract.min.js" defer></script>
        <script src="/theme-asset/default/book-index.js" defer></script>
        HTML;
    }

    /**
     * What we sang, for a licence return.
     *
     * The screen says what its evidence is, because the number on it goes on a
     * document somebody signs. Lookups are not on it and the page says why.
     *
     * @param array<string, mixed> $data
     */
    private function songs(array $data): string
    {
        $report = (array) $data['report'];
        $from = e((string) $report['from']);
        $to = e((string) $report['to']);

        $rows = '';
        foreach ((array) $report['songs'] as $song) {
            $ccli = trim((string) ($song['ccli_number'] ?? ''));

            $rows .= sprintf(
                '<tr>
                   <td>%d</td>
                   <td>%s<div class="muted small">%s</div></td>
                   <td>%s</td>
                   <td>%s</td>
                   <td class="right">%d</td>
                   <td class="muted small">%s</td>
                 </tr>',
                (int) $song['number'],
                e((string) ($song['song_title'] ?? '')),
                e((string) $song['book_title']),
                e((string) ($song['author'] ?? '')),
                // Named rather than left blank: this is the field that decides
                // whether the row can go on the return at all.
                $ccli === '' ? '<span class="pill warn">no number</span>' : e($ccli),
                (int) $song['times'],
                e((string) $song['first_used']) . ' – ' . e((string) $song['last_used'])
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="muted">Nothing recorded as sung in that period.</td></tr>';
        }

        $missing = (int) $report['withoutCcli'];
        $warning = $missing === 0
            ? ''
            : sprintf(
                '<div class="notice error"><strong>%d of these have no CCLI number.</strong>
                 <p class="muted small">A song without one cannot go on a return. They are listed
                    here rather than left out, because a return that quietly omits them looks
                    complete and is not.</p></div>',
                $missing
            );

        return <<<HTML
        <h1>What we sang</h1>

        <p class="muted">For a licence return. This counts songs <strong>recorded as sung</strong>
           — from a service plan, from present mode, or entered by hand. It does
           <strong>not</strong> count hymn lookups: somebody opening a hymn on their phone is not
           a performance, and a return that counted it would overstate.</p>

        <form method="get" action="/admin/books/songs" class="inline-form">
          <label>From <input type="date" name="from" value="{$from}"></label>
          <label>To <input type="date" name="to" value="{$to}"></label>
          <button class="btn tiny">Show</button>
          <a class="btn tiny secondary"
             href="/admin/books/songs.csv?from={$from}&amp;to={$to}">Download as a spreadsheet</a>
        </form>

        {$warning}

        <table>
          <thead>
            <tr><th>No.</th><th>Song</th><th>Author</th><th>CCLI</th><th>Times</th><th>Between</th></tr>
          </thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    private function checked(bool $on): string
    {
        return $on ? 'checked' : '';
    }

    private function text(string $value): string
    {
        return e($value);
    }
}
